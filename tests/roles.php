<?php
// Integration tests: real application handlers, isolated in-memory storage.
session_start();$_SESSION['csrf']='test-csrf';$_SERVER['REMOTE_ADDR']='qa';
require __DIR__.'/../src/runtime.php';
require __DIR__.'/../src/domain.php';
require __DIR__.'/../src/integration.php';
class MemoryCollection {
    public array $rows=[];
    function createIndex($a,$b=[]){return null;}
    static function value($row,$key){foreach(explode('.',$key) as $k){if(!is_array($row)||!array_key_exists($k,$row))return null;$row=$row[$k];}return $row;}
    static function matches($row,$filter){foreach($filter as $k=>$v){if($k==='$and'){foreach($v as $f)if(!self::matches($row,$f))return false;continue;}if($k==='$or'){if(!array_filter($v,fn($f)=>self::matches($row,$f)))return false;continue;}$actual=self::value($row,$k);if(is_array($v)&&array_filter(array_keys($v),fn($a)=>str_starts_with((string)$a,'$'))){foreach($v as $op=>$target){$ok=match($op){'$exists'=>($actual!==null)===$target,'$lte'=>$actual<=$target,'$in'=>in_array($actual,$target,true),'$ne'=>$actual!==$target,default=>throw new Exception('unsupported '.$op)};if(!$ok)return false;}}elseif($actual!==$v&&!(is_array($actual)&&!is_array($v)&&in_array($v,$actual,true)))return false;}return true;}
    function find($f=[],$options=[]){$r=array_values(array_filter($this->rows,fn($r)=>self::matches($r,$f)));if(isset($options['limit']))$r=array_slice($r,0,$options['limit']);return new class($r){function __construct(private array $rows){}function toArray(){return $this->rows;}};}
    function findOne($f=[],$options=[]){foreach(['$and','$or'] as $op)if(isset($f[$op]))foreach($f[$op] as $part)if($part===[])throw new Exception('Empty BSON array in logical query');return $this->find($f,$options)->toArray()[0]??null;}
    function insertOne($r){$this->rows[]=$r;}
    function countDocuments($f=[]){return count($this->find($f)->toArray());}
    function deleteOne($f){foreach($this->rows as $i=>$r)if(self::matches($r,$f)){unset($this->rows[$i]);break;}}
    function updateOne($f,$u,$opts=[]){$match=null;$insert=false;foreach($this->rows as $i=>$r)if(self::matches($r,$f)){$match=$i;break;}if($match===null&&!empty($opts['upsert'])){$this->rows[]=$f;$match=array_key_last($this->rows);$insert=true;}if($match!==null){$r=&$this->rows[$match];foreach($u as $op=>$fields)foreach($fields as $k=>$v){switch($op){case '$set':$r[$k]=$v;break;case '$setOnInsert':if($insert)$r[$k]=$v;break;case '$inc':$r[$k]=($r[$k]??0)+$v;break;case '$addToSet':$r[$k]??=[];if(!in_array($v,$r[$k],true))$r[$k][]=$v;break;case '$pull':$r[$k]=array_values(array_filter($r[$k],fn($a)=>$a!==$v));break;case '$push':$r[$k][]=$v;break;case '$unset':unset($r[$k]);break;default:throw new Exception('unsupported '.$op);}}}return new class($match!==null&&!$insert){function __construct(private bool $matched){}function getMatchedCount(){return (int)$this->matched;}};}
}
class MemoryDatabase {private array $collections=[];function __get($name){return $this->collections[$name]??=$this->collections[$name]=new MemoryCollection();}}
$database=new MemoryDatabase();
function db(){return $GLOBALS['database'];}
function redisClient(){return $GLOBALS['fakeRedis']??null;}
function connectionConfig(){return ['namespace'=>'qa','database'=>'qa'];}
function appProfile(){return 'local';}
function accessHash(){static $hash;return $hash??=password_hash('InitialPassword123',PASSWORD_DEFAULT);}
function documents($c){return db()->$c->find()->toArray();}
function invalidate(){}
function jsonResponse($r,$data,$status=200){$r->body=json_encode($data);return $r->withStatus($status);}
class TestRequest {function __construct(public string $method,public string $path,public array $body=[],public bool $csrf=true){}function getMethod(){return $this->method;}function getUri(){return new class($this->path){function __construct(private string $p){}function getPath(){return $this->p;}};}function getHeaderLine($k){return $this->csrf?($_SESSION['csrf']??''):'';}function getParsedBody(){return $this->body;}function getQueryParams(){return [];}}
class TestApp {
    public array $routes=[],$middleware=[];
    function add($c){$this->middleware[]=$c;}
    function __call($m,$args){$this->routes[]=[strtoupper($m),...$args];}
    function dispatch($method,$path,$body=[],$csrf=true){$req=new TestRequest($method,'/api/'.$path,$body,$csrf);$call=function($r){foreach($this->routes as [$m,$p,$c]){if($m===$r->method&&preg_match('#^'.str_replace('\{id\}','(?P<id>[^/]+)',preg_quote($p,'#')).'$#',$r->path,$args))return $c($r,new Response(),isset($args['id'])?['id'=>$args['id']]:[]);}return jsonResponse(new Response(),[],404);};foreach($this->middleware as $mw){$next=$call;$call=fn($r)=>$mw($r,new class($next){function __construct(private $next){}function handle($r){return ($this->next)($r);}});}try{return $call($req);}catch(InvalidArgumentException $e){return jsonResponse(new Response(),['error'=>$e->getMessage()],422);}}
}
require __DIR__.'/../src/security.php';$app=new TestApp();require __DIR__.'/../src/routes.php';
$checks=0;
function check($ok,$label){$GLOBALS['checks']++;if(!$ok)throw new Exception('FAILED: '.$label);}
function callApi($method,$path,$body=[],$expected=200,$csrf=true){$r=$GLOBALS['app']->dispatch($method,$path,$body,$csrf);check($r->status===$expected,$method.' '.$path.' status '.$r->status.' expected '.$expected);return json_decode($r->body,true);}
function login($email,$password){return callApi('POST','login',compact('email','password'));}
function useUser($u){$_SESSION=['userId'=>$u['id'],'userVersion'=>$u['version'],'startedAt'=>time(),'lastSeen'=>time(),'csrf'=>'test-csrf'];}
ob_start();bootstrapAdmin('admin@example.test');ob_end_clean();
$admin=login('admin@example.test','InitialPassword123')['user'];
callApi('GET','users',[],403);
callApi('POST','password',['currentPassword'=>'InitialPassword123','password'=>'ChangedPassword123']);
$admin=db()->odac_users->findOne(['id'=>$admin['id']]);
$c1=callApi('POST','register',['name'=>'Cliente Uno','email'=>'one@example.test','password'=>'ClientPassword123','role'=>'administrador'],201)['user'];
check($c1['role']==='cliente','public signup cannot inject administrator');
$c2=callApi('POST','register',['name'=>'Cliente Dos','email'=>'two@example.test','password'=>'ClientPassword123'],201)['user'];
useUser($admin);$agent=callApi('POST','users',['name'=>'Agente','email'=>'agent@example.test','password'=>'AgentPassword123','role'=>'agente'],201);
useUser(db()->odac_users->findOne(['id'=>$c1['id']]));
$base=['id'=>'ticket-one-123','subject'=>'Pedido atrasado','description'=>'Necesito seguimiento','priority'=>'Alta','category'=>'Consulta','clientId'=>$c2['clientId']];
$t1=callApi('POST','tickets',$base,201);check($t1['clientId']===$c1['clientId'],'client cannot create ticket for someone else');
useUser(db()->odac_users->findOne(['id'=>$c2['id']]));$base['id']='ticket-two-123';callApi('POST','tickets',$base,201);
$visible=callApi('GET','tickets');check(count($visible)===1&&$visible[0]['id']==='ticket-two-123','client ticket isolation');
callApi('PATCH','tickets/ticket-one-123',['note'=>'Private?'],404);
callApi('PATCH','tickets/ticket-two-123',['status'=>'Resuelto','note'=>'No'],403);
callApi('PATCH','tickets/ticket-two-123',['note'=>'Más información']);
foreach(['users','reports','audit'] as $path)callApi('GET',$path,[],403);
callApi('POST','settings',['hours'=>SLA_HOURS],403);
callApi('POST','tickets',$base,403,false);
useUser($admin);callApi('PATCH','tickets/ticket-one-123',['assigneeId'=>$agent['id'],'status'=>'En atención']);
$publicUsers=callApi('GET','users');foreach($publicUsers as $u)check(!isset($u['passwordHash']),'hash never returned');
login('agent@example.test','AgentPassword123');callApi('GET','tickets',[],403);
callApi('POST','password',['currentPassword'=>'AgentPassword123','password'=>'NewAgentPassword123']);
$visible=callApi('GET','tickets');check(count($visible)===1&&$visible[0]['id']==='ticket-one-123','agent assignment isolation');
callApi('PATCH','tickets/ticket-two-123',['note'=>'Private?'],404);
callApi('PATCH','tickets/ticket-one-123',['status'=>'Resuelto','note'=>'Entregado','assigneeId'=>$agent['id']]);
callApi('DELETE','tickets/ticket-one-123',[],403);
useUser($admin);callApi('PATCH','tickets/ticket-one-123',['status'=>'En atención','assigneeId'=>$agent['id'],'note'=>'Reabrir']);check(!isset(db()->odac_tickets->findOne(['id'=>'ticket-one-123'])['resolvedAt']),'reopen clears resolved timestamp');
$old=db()->odac_users->findOne(['id'=>$c1['id']]);
callApi('PATCH','users/'.$c1['id'],['password'=>'ResetPassword123']);useUser($old);callApi('GET','tickets',[],401);
useUser($admin);callApi('PATCH','users/'.$admin['id'],['active'=>false],403);
$second=callApi('POST','users',['name'=>'Admin Dos','email'=>'admin2@example.test','password'=>'SecondAdminPassword123','role'=>'administrador'],201);
check(reserveAdminRemoval($admin),'first administrator removal reserved');check(!reserveAdminRemoval(db()->odac_users->findOne(['id'=>$second['id']])),'last administrator remains under competing removals');restoreAdmin($admin);
useUser($admin);$_SESSION['lastSeen']=time()-1801;callApi('GET','users',[],401);
for($i=0;$i<3;$i++)check(authLimit('isolated-limit',3),'attempt within limit');check(!authLimit('isolated-limit',3),'durable attempts blocked');
try{passwordInput(str_repeat('é',6));check(false,'Unicode minimum');}catch(InvalidArgumentException $e){check(true,'Unicode minimum');}
check(passwordInput(str_repeat('é',12))===str_repeat('é',12),'Unicode password accepted');
// QA regression suite for business operations and invalid payloads.
useUser($admin);
callApi('GET','tickets');callApi('GET','queue');callApi('GET','reports');callApi('GET','audit');callApi('GET','agents');callApi('GET','settings');callApi('GET','health');
callApi('PATCH','tickets/ticket-one-123',['assigneeId'=>['$ne'=>''],'status'=>'En atención'],422);
callApi('PATCH','tickets/ticket-one-123',['assigneeId'=>'unknown-agent','status'=>'En atención'],422);
callApi('PATCH','tickets/ticket-one-123',['assigneeId'=>'','status'=>'En atención'],422);
callApi('PATCH','tickets/ticket-one-123',['status'=>'Resuelto','note'=>''],422);
callApi('PATCH','tickets/ticket-one-123',[],422);
callApi('POST','tickets',array_replace($base,['id'=>'bad-priority-123','priority'=>['$ne'=>'']]),422);
$count=db()->odac_users->countDocuments();callApi('POST','register',['name'=>'Bad','email'=>'invalidphone@example.test','password'=>'ValidPassword123','phone'=>['$ne'=>'']],422);check(db()->odac_users->countDocuments()===$count,'invalid registration has no partial user');
callApi('POST','login',['email'=>'admin@example.test','password'=>['$ne'=>'']],422);
callApi('POST','password',['currentPassword'=>'ChangedPassword123','password'=>"TwelveChars123\0"],422);
$product=['id'=>'PRODUCT-QA','name'=>'Producto QA','type'=>'Producto','category'=>'Prueba','reference'=>'QA-001','status'=>'Disponible'];
callApi('POST','products',$product);callApi('GET','products');
callApi('PATCH','products/PRODUCT-QA',array_replace($product,['status'=>'Suspendido']));
callApi('PATCH','products/no-such-product',$product,404);
$contact=['id'=>'qa-contact-123','name'=>'Contacto QA','email'=>'contact@example.test','phone'=>'','company'=>'QA'];
callApi('POST','clients',$contact,201);callApi('GET','clients');callApi('PATCH','clients/qa-contact-123',array_replace($contact,['company'=>'QA editada']));
callApi('PATCH','clients/no-such-client',$contact,404);
callApi('DELETE','clients/'.$c2['clientId'],[],422);
callApi('POST','settings',['hours'=>['Alta'=>0,'Media'=>24,'Baja'=>72]],422);
callApi('POST','settings',['hours'=>['Alta'=>2,'Media'=>12,'Baja'=>48]]);
$idempotent=array_replace($base,['id'=>'qa-final-ticket','clientId'=>$c1['clientId'],'productId'=>'PRODUCT-QA']);
$new=callApi('POST','tickets',$idempotent,201);check($new['dueAt']-$new['createdAt']===7200,'configured SLA applies to new tickets');
callApi('POST','tickets',$idempotent);check(db()->odac_tickets->countDocuments(['id'=>'qa-final-ticket'])===1,'retry does not duplicate ticket');
callApi('DELETE','products/PRODUCT-QA',[],422);
callApi('PATCH','tickets/qa-final-ticket',['assigneeId'=>$agent['id'],'status'=>'En atención']);
callApi('PATCH','users/'.$agent['id'],['active'=>false],422);
callApi('PATCH','tickets/qa-final-ticket',['assigneeId'=>'','status'=>'Nuevo','reason'=>'Reasignación antes de baja']);
callApi('PATCH','tickets/ticket-one-123',['assigneeId'=>'','status'=>'Nuevo','reason'=>'Reasignación antes de baja']);
callApi('PATCH','users/'.$agent['id'],['active'=>false]);
callApi('PATCH','tickets/qa-final-ticket',['assigneeId'=>$agent['id'],'status'=>'Resuelto','note'=>'Solución'],422);
callApi('PATCH','users/'.$agent['id'],['active'=>true]);
callApi('PATCH','tickets/qa-final-ticket',['assigneeId'=>$agent['id'],'status'=>'Resuelto','note'=>'Solución QA','reason'=>'Cierre de control QA']);
check(db()->odac_tickets->findOne(['id'=>'qa-final-ticket'])['resolvedAt']>0,'resolution timestamp persists');
callApi('DELETE','tickets/qa-final-ticket');callApi('DELETE','products/PRODUCT-QA');callApi('DELETE','clients/qa-contact-123');
callApi('PATCH','users/'.$c2['id'],['name'=>'Cliente Dos Editado','email'=>'two-updated@example.test']);
check(db()->odac_users->findOne(['id'=>$c2['id']])['email']==='two-updated@example.test','administrator edits account');
callApi('PATCH','tickets/ticket-one-123',['assigneeId'=>$agent['id'],'status'=>'En atención']);
useUser(db()->odac_users->findOne(['id'=>$agent['id']]));
foreach(['users','audit','reports'] as $path)callApi('GET',$path,[],403);
callApi('POST','products',$product,403);callApi('POST','clients',$contact,403);callApi('POST','users',['role'=>'administrador'],403);
callApi('PATCH','tickets/ticket-one-123',['assigneeId'=>$admin['id'],'status'=>'En atención'],403);
useUser($admin);$_SESSION['startedAt']=time()-28801;callApi('GET','tickets',[],401);
class FakeRedis {public array $values=[];function get($k){return $this->values[$k]??false;}function hMSet($k,$v){$this->values[$k]=$v;}function hGet($k,$field){return $this->values[$k][$field]??false;}function setex($k,$ttl,$v){$this->values[$k]=$v;}function del($k){unset($this->values[$k]);}function zAdd($k,$s,$v){}function expire($k,$s){}function incr($k){return $this->values[$k]=($this->values[$k]??0)+1;}}
$fakeRedis=new FakeRedis();useUser($admin);check(callApi('GET','queue')['source']==='MongoDB','cold queue uses MongoDB');check(callApi('GET','queue')['source']==='Redis','warm queue uses Redis');
$cu=db()->odac_users->findOne(['id'=>$c2['id']]);useUser($cu);$q=callApi('GET','queue');check($q['source']==='MongoDB','cache isolated by user');foreach($q['items'] as $t)check($t['clientId']===$cu['clientId'],'cached queue respects client scope');
useUser($admin);$fakeRedis=new class {function get($k){throw new RuntimeException('Redis down');}};check(callApi('GET','queue')['source']==='MongoDB','Redis outage falls back to MongoDB');unset($fakeRedis);
// Workflow: authorization is enforced against MongoDB even when Redis is stale.
useUser($admin);
$a2=callApi('POST','users',['name'=>'Agente Dos','email'=>'agent-two@example.test','password'=>'SyntheticAgentTwo123','role'=>'agente','departmentId'=>'reclamos'],201);
$sales=callApi('POST','users',['name'=>'Ventas','email'=>'sales@example.test','password'=>'SyntheticSales123','role'=>'agente','departmentId'=>'ventas'],201);
foreach([$a2,$sales] as $a)db()->odac_users->updateOne(['id'=>$a['id']],['$set'=>['mustChangePassword'=>false]]);
$case=callApi('POST','tickets',array_replace($base,['id'=>'workflow-secure-123','clientId'=>$c2['clientId'],'departmentId'=>'reclamos']),201);
callApi('PATCH','tickets/workflow-secure-123',['assigneeId'=>$sales['id'],'status'=>'En atención'],422);
$suggestion=callApi('GET','assignment/workflow-secure-123');check($suggestion['suggestion']['id']===$a2['id'],'suggest least load in area');
callApi('PATCH','tickets/workflow-secure-123',['assigneeId'=>$a2['id'],'status'=>'En atención']);
check(!isset(db()->odac_tickets->findOne(['id'=>$case['id']])['firstResponseAt']),'assignment is not first response');
useUser(db()->odac_users->findOne(['id'=>$a2['id']]));
$fakeRedis=new FakeRedis();callApi('GET','queue');
callApi('PATCH','tickets/workflow-secure-123',['visibility'=>'internal','note'=>'INTERNAL SECRET QA']);
check(!isset(db()->odac_tickets->findOne(['id'=>$case['id']])['firstResponseAt']),'internal note is not public response');
callApi('PATCH','tickets/workflow-secure-123',['departmentId'=>'ventas','note'=>'attempt'],403);
callApi('PATCH','tickets/workflow-secure-123',['assigneeId'=>$agent['id'],'note'=>'attempt'],403);
useUser(db()->odac_users->findOne(['id'=>$c2['id']]));
foreach(['tickets','tickets/workflow-secure-123','queue','notifications'] as $endpoint){$out=callApi('GET',$endpoint);check(!str_contains(json_encode($out),'INTERNAL SECRET QA'),'private history excluded: '.$endpoint);}
callApi('POST','notifications/not-owned',[],404);
callApi('PATCH','tickets/workflow-secure-123',['action'=>'close','rating'=>5],422);
useUser($admin);callApi('PATCH','tickets/workflow-secure-123',['assigneeId'=>$agent['id'],'status'=>'En atención'],422);
callApi('PATCH','tickets/workflow-secure-123',['assigneeId'=>$agent['id'],'status'=>'En atención','reason'=>'Cambio de turno']);
// Keep stale Redis deliberately: authorization must still revoke the old agent.
useUser(db()->odac_users->findOne(['id'=>$a2['id']]));
check(callApi('GET','tickets')===[],'former agent list revoked');
callApi('GET','tickets/workflow-secure-123',[],404);callApi('PATCH','tickets/workflow-secure-123',['note'=>'attempt'],404);
check(!array_filter(callApi('GET','queue')['items'],fn($t)=>$t['id']===$case['id']),'stale Redis cannot leak reassigned ticket');
check(callApi('GET','notifications')===[],'former agent notices revoked');
// Creating a ticket does not grant an employee perpetual access.
db()->odac_tickets->updateOne(['id'=>$case['id']],['$set'=>['createdBy'=>$a2['id'],'clientId'=>$a2['clientId']]]);
callApi('GET','tickets/workflow-secure-123',[],404);
db()->odac_tickets->updateOne(['id'=>$case['id']],['$set'=>['clientId'=>$c2['clientId']]]);
unset($fakeRedis);
useUser(db()->odac_users->findOne(['id'=>$agent['id']]));
callApi('PATCH','tickets/workflow-secure-123',['status'=>'Solución propuesta','note'=>'Solución pública de prueba','waitingFor'=>'agente']);
$t=db()->odac_tickets->findOne(['id'=>$case['id']]);check(isset($t['firstResponseAt'])&&$t['waitingFor']==='cliente'&&$t['status']==='Solución propuesta','employee proposes solution, customer has not closed');
useUser(db()->odac_users->findOne(['id'=>$c2['id']]));
$n=callApi('GET','notifications');check(count($n)>=2,'customer receives assignment and solution notices');
$notice=$n[0];callApi('POST','notifications/'.$notice['id']);check(array_values(array_filter(callApi('GET','notifications'),fn($n)=>$n['id']===$notice['id']))[0]['read'],'notice read persists');
callApi('PATCH','tickets/workflow-secure-123',['action'=>'close','rating'=>6],422);
callApi('PATCH','tickets/workflow-secure-123',['action'=>'close','rating'=>'5'],422);
callApi('PATCH','tickets/workflow-secure-123',['action'=>'close','rating'=>5,'comment'=>'Excelente atención']);
$t=db()->odac_tickets->findOne(['id'=>$case['id']]);check($t['status']==='Resuelto'&&$t['rating']===5&&isset($t['closedAt']),'customer closes and rates');
callApi('PATCH','tickets/workflow-secure-123',['action'=>'close','rating'=>1],422);
callApi('PATCH','tickets/workflow-secure-123',['action'=>'reopen','note'=>'Necesito continuar']);
$t=db()->odac_tickets->findOne(['id'=>$case['id']]);check(!isset($t['rating'])&&!isset($t['resolvedAt'])&&$t['status']==='En atención','reopen clears final satisfaction and resolution');
useUser($admin);callApi('PATCH','tickets/workflow-secure-123',['status'=>'Resuelto','note'=>'Cierre administrativo'],422);
callApi('PATCH','tickets/workflow-secure-123',['status'=>'Resuelto','note'=>'Cierre administrativo','reason'=>'Control QA']);
// Escalation is idempotent, area-scoped and transfers exclusive ownership.
$supervisor=callApi('POST','users',['name'=>'Supervisor','email'=>'supervisor@example.test','password'=>'SyntheticSupervisor123','role'=>'agente','supervisor'=>true,'departmentId'=>'reclamos'],201);
db()->odac_users->updateOne(['id'=>$supervisor['id']],['$set'=>['mustChangePassword'=>false]]);
$due=callApi('POST','tickets',array_replace($base,['id'=>'workflow-escalate-123','clientId'=>$c2['clientId'],'departmentId'=>'reclamos']),201);
callApi('PATCH','tickets/workflow-escalate-123',['assigneeId'=>$agent['id'],'status'=>'En atención']);
db()->odac_tickets->updateOne(['id'=>$due['id']],['$set'=>['dueAt'=>time()+120]]);
$r=processEscalations();check($r['escalations']===1,'warning escalates to area supervisor');
$t=db()->odac_tickets->findOne(['id'=>$due['id']]);check($t['assigneeId']===$supervisor['id']&&$t['slaStage']==='warning','escalation transfers ownership');
$count=count($t['history']);processEscalations();check(count(db()->odac_tickets->findOne(['id'=>$due['id']])['history'])===$count,'repeated warning produces no duplicate');
useUser(db()->odac_users->findOne(['id'=>$agent['id']]));callApi('GET','tickets/workflow-escalate-123',[],404);
useUser(db()->odac_users->findOne(['id'=>$supervisor['id']]));callApi('GET','tickets/workflow-escalate-123');
useUser($admin);db()->odac_tickets->updateOne(['id'=>$due['id']],['$set'=>['dueAt'=>time()-1]]);processEscalations();
$t=db()->odac_tickets->findOne(['id'=>$due['id']]);check($t['slaStage']==='overdue'&&$t['assigneeId']===$supervisor['id'],'overdue retains current supervisor');
$count=count($t['history']);processEscalations();check(count(db()->odac_tickets->findOne(['id'=>$due['id']])['history'])===$count,'overdue deduplicates');
callApi('POST','escalation',['enabled'=>true,'warningMinutes'=>0],422);callApi('POST','escalation',['enabled'=>false,'warningMinutes'=>30]);check(escalationPolicy()['enabled']===false,'admin can pause escalation');
useUser(db()->odac_users->findOne(['id'=>$c2['id']]));callApi('POST','escalation',['enabled'=>true,'warningMinutes'=>30],403);callApi('GET','assignment/workflow-secure-123',[],403);
echo "PASS: $checks assertions; authentication, CRUD, roles, ticket assignment, SLA, CSRF, cache, invalid payloads and administrator protection.\n";

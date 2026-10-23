<?php
declare(strict_types=1);
require __DIR__.'/../src/domain.php';
const USER_ROLES=['cliente','agente','administrador'];
require __DIR__.'/../src/integration.php';
$settings=[];function setting(string $k,string $default=''):string{return $GLOBALS['settings'][$k]??$default;}
$checks=0;function verify(bool $value,string $message):void{$GLOBALS['checks']++;if(!$value)throw new RuntimeException($message);}
class ProbeRedis {
    public bool $connected=true,$authenticated=true,$selected=true;public mixed $pong=true;public array $calls=[];
    function connect($h,$p,$t){$this->calls[]=['connect',$h,$p];return $this->connected;}
    function auth($auth){$this->calls[]=['auth',$auth];return $this->authenticated;}
    function select($db){$this->calls[]=['select',$db];return $this->selected;}
    function setOption($a,$b){return true;}
    function ping(){return $this->pong;}
}
verify(str_starts_with(redisNamespace('local|connection|db'),'grupo3:opendesk:'),'Shared prefix');
verify(redisNamespace('a')!==redisNamespace('b'),'Profiles separated');
$settings['REDIS_PREFIX']='odac:';try{redisNamespace('a');verify(false,'Bad prefix accepted');}catch(InvalidArgumentException $e){verify(true,'Bad prefix rejected');}$settings=[];
foreach([true,'PONG','+PONG'] as $pong){$r=new ProbeRedis();$r->pong=$pong;verify(openRedis(fn()=>$r)===$r,'Valid PING');}
foreach(['connected','authenticated','selected'] as $failure){$settings=['REDIS_PASSWORD'=>'synthetic'];$r=new ProbeRedis();$r->$failure=false;verify(openRedis(fn()=>$r)===null,'False return rejected '.$failure);}
$settings=[];$r=new ProbeRedis();$r->pong=false;verify(openRedis(fn()=>$r)===null,'PING failure');
verify(openRedis(fn()=>throw new RuntimeException('offline'))===null,'Connection exception');
$settings=['REDIS_USERNAME'=>'example','REDIS_PASSWORD'=>'synthetic'];$r=new ProbeRedis();verify(openRedis(fn()=>$r)===$r,'ACL auth');verify(in_array(['auth',['example','synthetic']],$r->calls,true),'ACL shape');
foreach(['REDIS_PORT'=>'0','REDIS_DATABASE'=>'-1'] as $key=>$value){$settings=[$key=>$value];verify(openRedis(fn()=>new ProbeRedis())===null,'Configuration validation '.$key);}$settings=[];
verify(sampleMedian([7,1,3])===3.0,'Odd median');verify(sampleMedian([1,8,2,4])===3.0,'Even median');
try{sampleMedian([]);verify(false,'Empty samples accepted');}catch(InvalidArgumentException $e){verify(true,'Empty samples rejected');}
try{benchmarkServices(99,3);verify(false,'Invalid count accepted');}catch(InvalidArgumentException $e){verify(true,'Count bounded');}
try{benchmarkServices(100,2);verify(false,'Too few repetitions');}catch(InvalidArgumentException $e){verify(true,'At least three runs');}
$schemas=mongoSchemas();verify(count($schemas)===4,'Four business validators');
verify($schemas['odac_tickets']['properties']['status']['enum']===STATUSES,'Schema statuses');
verify(in_array('history',$schemas['odac_tickets']['required'],true),'History required');
verify($schemas['odac_users']['properties']['role']['enum']===USER_ROLES,'Schema roles');
class QueueSpy {public array $calls=[];function __call($method,$args){$this->calls[]=[$method,$args];return true;}}
$spy=new QueueSpy();$key=redisNamespace('qa').':queue:synthetic';writeQueueCache($spy,$key,[['id'=>'example','priority'=>'Alta','dueAt'=>100]]);
$methods=array_column($spy->calls,0);foreach(['setex','zAdd','hMSet'] as $method)verify(in_array($method,$methods,true),'Structure operation '.$method);
foreach($spy->calls as [$method,$args]){verify(str_starts_with($args[0],'grupo3:'),'Every cache key scoped');if($method==='expire')verify($args[1]===15,'Short metadata TTL');}
echo "PASS: $checks integration-tool assertions; prefix, connection, ACL, cache types, schemas and medians.\n";

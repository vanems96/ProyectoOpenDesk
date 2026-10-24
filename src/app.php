<?php
declare(strict_types=1);
/* OpenDesk Atención al Cliente. Toda la aplicación está en este archivo.
 * Requiere PHP 8.2+, extensión mongodb y Redis para la caché.
 * No requiere Composer, Slim ni archivos CSS/JS adicionales.
 * Configuración privada: no publique este archivo en repositorios abiertos.
 */

ini_set('display_errors','0');ini_set('display_startup_errors','0');ini_set('log_errors','1');
if(PHP_SAPI!=='cli'&&!empty($_SERVER['PATH_INFO'])){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'No encontrado.';exit;}
// Los secretos nunca se incluyen en el archivo que está bajo public_html.
function privateConfig():array{
    $path=getenv('OPENDESK_PRIVATE_CONFIG')?:'/home/grupo3/.config/opendesk/config.php';
    $real=realpath($path);$web=realpath($_SERVER['DOCUMENT_ROOT']??'');
    if(!$real||!is_readable($real))throw new RuntimeException('Configuración privada no disponible.');
    if(PHP_SAPI!=='cli'&&$web&&str_starts_with(str_replace('\\','/',$real),rtrim(str_replace('\\','/',$web),'/').'/'))throw new RuntimeException('Configuración privada dentro del directorio público.');
    $config=require $real;if(!is_array($config))throw new RuntimeException('Configuración inválida.');return $config;
}
try{$APP_CONFIG=privateConfig();}catch(Throwable $e){
    if(PHP_SAPI==='cli'){fwrite(STDERR,"Configuración privada no disponible fuera del directorio público.\n");exit(1);}
    http_response_code(503);header('Content-Type: text/plain; charset=utf-8');echo 'Configuración del servidor pendiente. Contacte al administrador.';exit;
}
if(PHP_SAPI!=='cli'){
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');header('Cache-Control: no-store');
    $scriptNonce=base64_encode(random_bytes(24));
    header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$scriptNonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; worker-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}

function setting(string $name, string $default=''): string {
    $v=getenv($name);if($v!==false)return $v;
    if(str_starts_with($name,'REDIS_')){static $redis;$redis??=$GLOBALS['APP_CONFIG']['redis'];return (string)($redis[$name]??$default);}
    return $default;
}
function connectionConfig(): array {
    static $cfg;
    if($cfg) return $cfg;
    $base='';
    $profile=setting('OPENDESK_PROFILE',$GLOBALS['APP_CONFIG']['profile']);
    if(!in_array($profile,['local','atlas'],true)) throw new RuntimeException('Perfil inválido.');
    $uri=setting('MONGODB_URI');$database=setting('MONGODB_DATABASE');
    if(!$uri && $profile==='local'){
        $private=$GLOBALS['APP_CONFIG']['local'];
        $uri=$private['uri']??'mongodb://127.0.0.1:27017/?authSource=grupo3_db';
        $database=$database?:($private['database']??'grupo3_db');
    }
    if(!$uri && $profile==='atlas'){
        $private=$GLOBALS['APP_CONFIG']['atlas'];
        $uri=$private['uri']??'';$database=$database?:($private['database']??'');
        if(!$uri){
            $path=setting('ATLAS_CONFIG_SOURCE','/home/grupo3/public_html/BIBLIOTECA45/index.php');
            if(!is_file($path)||!is_readable($path)) throw new RuntimeException('La configuración Atlas no es accesible.');
            // Solo se leen asignaciones literales. Nunca se ejecuta el archivo de biblioteca.
            $text=file_get_contents($path);$values=[];
            foreach(['mongoUsuario','mongoClave','mongoCluster','mongoBase'] as $key){
                if(preg_match('/\$'.preg_quote($key,'/').'\s*=\s*([\'\"])(.*?)\1\s*;/s',$text,$m)) $values[$key]=$m[2];
            }
            foreach(['mongoUsuario','mongoClave','mongoCluster'] as $key) if(empty($values[$key])) throw new RuntimeException('Formato Atlas no reconocido. Configure config/atlas.php.');
            if(!preg_match('/^[a-zA-Z0-9.-]+\.mongodb\.net$/',$values['mongoCluster'])) throw new RuntimeException('Cluster Atlas inválido.');
            $uri='mongodb+srv://'.rawurlencode($values['mongoUsuario']).':'.rawurlencode($values['mongoClave']).'@'.$values['mongoCluster'].'/?retryWrites=true&w=majority';
            $database=$database?:($values['mongoBase']??'grupo3_db');
        }
    }
    $database=$database?:'grupo3_db';
    if(!preg_match('/^[a-zA-Z0-9_\-]+$/',$database)) throw new RuntimeException('Base inválida.');
    return $cfg=['profile'=>$profile,'uri'=>$uri,'database'=>$database,'namespace'=>redisNamespace($profile.'|'.$uri.'|'.$database),'storageKey'=>'odac:'.substr(hash('sha256',$profile.'|'.$uri.'|'.$database),0,16)];
}
function appProfile(): string { return setting('OPENDESK_PROFILE',$GLOBALS['APP_CONFIG']['profile']); }
function storageKey(): string {
    try{return connectionConfig()['storageKey'];}catch(Throwable $e){return 'odac:'.appProfile();}
}
function accessHash(): string {
    return setting('APP_PASSWORD_HASH',$GLOBALS['APP_CONFIG']['accessHash']);
}
if(($_GET['asset']??'')==='sw'){header('Content-Type: application/javascript');header('Cache-Control: no-cache');echo <<<'SW_CODE'
const BASE=new URL('./',self.location).pathname,CACHE='opendesk-qa-v2:'+BASE;
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.add('index.php')).then(()=>self.skipWaiting())));
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE&&((k.startsWith('opendesk-one-php:')||k.startsWith('opendesk-qa-'))&&k.endsWith(BASE))).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',e=>{const u=new URL(e.request.url);if(u.origin!==location.origin||![BASE,BASE+'index.php'].includes(u.pathname)||u.search!==''||e.request.method!=='GET')return;e.respondWith(fetch(e.request).then(r=>{if(r.ok){const copy=r.clone();caches.open(CACHE).then(c=>c.put(e.request,copy))}return r}).catch(()=>caches.match(e.request).then(r=>r||caches.match(BASE+'index.php'))))});
SW_CODE;
exit;}
// BEGIN COMPONENT runtime
// HTTP mínimo en PHP. No necesita Slim ni Composer.
final class Response {
    public string $body='';public int $status=200;public array $headers=[];
    public function getBody(): object{return new class($this){public function __construct(private Response $r){}public function write(string $text):void{$this->r->body.=$text;}};}
    public function withHeader(string $key,string $value):self{$this->headers[$key]=$value;return $this;}
    public function withStatus(int $code):self{$this->status=$code;return $this;}
}
final class Request {
    public function getMethod():string{return $_SERVER['REQUEST_METHOD']??'GET';}
    public function getUri():object{return new class {public function getPath():string{return '/api/'.(parse_url((string)($_GET['api']??''),PHP_URL_PATH)?:'');}};}
    public function getParsedBody():array{$text=file_get_contents('php://input',false,null,0,65537);if(!$text)return [];if(strlen($text)>65536)throw new InvalidArgumentException('Solicitud demasiado grande.');try{$object=json_decode($text,false,32,JSON_THROW_ON_ERROR);if(!is_object($object))throw new InvalidArgumentException('Se requiere un objeto JSON.');$v=json_decode($text,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new InvalidArgumentException('Objeto JSON inválido.');}return $v;}
    public function getHeaderLine(string $key):string{return $_SERVER['HTTP_'.strtoupper(str_replace('-','_',$key))]??'';}
    public function getQueryParams():array{$q=[];parse_str(parse_url((string)($_GET['api']??''),PHP_URL_QUERY)?:'',$q);return $q;}
}
final class Handler {
    public function __construct(private Closure $callback){}
    public function handle(Request $r):Response{return ($this->callback)($r);}
}
final class HttpApp {
    private array $routes=[],$middleware=[];
    public function setBasePath(string $path):void{}
    public function addBodyParsingMiddleware():void{}
    public function add(Closure $c):void{$this->middleware[]=$c;}
    public function get(string $p,Closure $c):void{$this->routes[]=['GET',$p,$c];}
    public function post(string $p,Closure $c):void{$this->routes[]=['POST',$p,$c];}
    public function patch(string $p,Closure $c):void{$this->routes[]=['PATCH',$p,$c];}
    public function delete(string $p,Closure $c):void{$this->routes[]=['DELETE',$p,$c];}
    public function run():void{
        $handler=new Handler(function(Request $r):Response{
            $path=$r->getUri()->getPath();
            foreach($this->routes as [$method,$pattern,$callback]){
                $regex='#^'.str_replace('\{id\}','(?P<id>[^/]+)',preg_quote($pattern,'#')).'$#';
                if($method===$r->getMethod()&&preg_match($regex,$path,$m))return $callback($r,new Response(),isset($m['id'])?['id'=>rawurldecode($m['id'])]:[]);
            }
            return jsonResponse(new Response(),['error'=>'Ruta no encontrada.'],404);
        });
        foreach($this->middleware as $mw){$next=$handler;$handler=new Handler(fn(Request $r)=>$mw($r,$next));}
        try{$response=$handler->handle(new Request());}catch(Throwable $e){error_log('OpenDesk: '.get_class($e));$response=jsonResponse(new Response(),['error'=>'No se pudo completar la solicitud.'],503);}
        http_response_code($response->status);foreach($response->headers as $k=>$v)header($k.': '.$v);echo $response->body;
    }
}
// Adaptadores pequeños sobre la extensión oficial mongodb de PHP.
final class Database {
    public function __construct(private MongoDB\Driver\Manager $manager,private string $name){}
    public function __get(string $name):Collection{return $this->selectCollection($name);}
    public function selectCollection(string $name):Collection{return new Collection($this->manager,$this->name,$name);}
    public function command(array $command):MongoDB\Driver\Cursor{$cursor=$this->manager->executeCommand($this->name,new MongoDB\Driver\Command($command));$cursor->setTypeMap(['root'=>'array','document'=>'array','array'=>'array']);return $cursor;}
}
final class Collection {
    public function __construct(private MongoDB\Driver\Manager $manager,private string $db,private string $name){}
    public function find(array $filter=[],array $options=[]):MongoDB\Driver\Cursor{$cursor=$this->manager->executeQuery($this->db.'.'.$this->name,new MongoDB\Driver\Query($filter,$options));$cursor->setTypeMap(['root'=>'array','document'=>'array','array'=>'array']);return $cursor;}
    public function findOne(array $filter,array $options=[]):?array{$options['limit']=1;return $this->find($filter,$options)->toArray()[0]??null;}
    public function insertOne(array $document):MongoDB\Driver\WriteResult{validateCollectionDocument($this->name,$document);$bulk=new MongoDB\Driver\BulkWrite();$bulk->insert($document);return $this->manager->executeBulkWrite($this->db.'.'.$this->name,$bulk);}
    public function insertMany(array $documents):MongoDB\Driver\WriteResult{foreach($documents as $document)validateCollectionDocument($this->name,$document);$bulk=new MongoDB\Driver\BulkWrite();foreach($documents as $document)$bulk->insert($document);return $this->manager->executeBulkWrite($this->db.'.'.$this->name,$bulk);}
    public function updateOne(array $filter,array $update,array $options=[]):MongoDB\Driver\WriteResult{
        $upsert=(bool)($options['upsert']??false);
        if(isset(mongoSchemas()[$this->name])){
            $before=$this->findOne($filter);
            if($before!==null){
                validateCollectionDocument($this->name,schemaUpdatedDocument($before,$update,false));
                // Solo escribir sobre la instantánea validada; no pisar cambios concurrentes.
                $filter=['$and'=>[$filter,$before]];$upsert=false;
            }elseif($upsert){
                $base=[];foreach($filter as $field=>$value)if(!str_starts_with((string)$field,'$')&&!is_array($value))$base[$field]=$value;
                validateCollectionDocument($this->name,schemaUpdatedDocument($base,$update,true));
            }
        }
        $bulk=new MongoDB\Driver\BulkWrite();$bulk->update($filter,$update,['multi'=>false,'upsert'=>$upsert]);return $this->manager->executeBulkWrite($this->db.'.'.$this->name,$bulk);
    }
    public function deleteOne(array $filter):MongoDB\Driver\WriteResult{$bulk=new MongoDB\Driver\BulkWrite();$bulk->delete($filter,['limit'=>1]);return $this->manager->executeBulkWrite($this->db.'.'.$this->name,$bulk);}
    public function deleteMany(array $filter):MongoDB\Driver\WriteResult{$bulk=new MongoDB\Driver\BulkWrite();$bulk->delete($filter,['limit'=>0]);return $this->manager->executeBulkWrite($this->db.'.'.$this->name,$bulk);}
    public function countDocuments(array $filter):int{$c=$this->manager->executeCommand($this->db,new MongoDB\Driver\Command(['aggregate'=>$this->name,'pipeline'=>[['$match'=>(object)$filter],['$count'=>'total']],'cursor'=>(object)[]]));$c->setTypeMap(['root'=>'array','document'=>'array','array'=>'array']);return (int)($c->toArray()[0]['total']??0);}
    public function createIndex(array $keys,array $options=[]):void{$index=['key'=>$keys,'name'=>implode('_',array_map(fn($k,$v)=>$k.'_'.$v,array_keys($keys),array_values($keys)))]+$options;$this->manager->executeCommand($this->db,new MongoDB\Driver\Command(['createIndexes'=>$this->name,'indexes'=>[$index]]));}
}
// END COMPONENT runtime
// BEGIN COMPONENT domain
const SLA_HOURS = ['Alta'=>4, 'Media'=>24, 'Baja'=>72];
const STATUSES = ['Nuevo', 'En atención', 'Esperando cliente', 'Resuelto'];
function cleanText(mixed $value, string $name, int $max = 200, bool $required = true): string {
    if (!is_string($value)) throw new InvalidArgumentException("Campo inválido: $name");
    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $max) throw new InvalidArgumentException("Revise el campo $name (máximo $max caracteres).");
    return $value;
}
function ticketInput(array $b): array {
    $priority = $b['priority'] ?? 'Media';
    if (!is_string($priority)||!isset(SLA_HOURS[$priority])) throw new InvalidArgumentException('Prioridad inválida.');
    $id = cleanText($b['id'] ?? '', 'identificador', 64);
    if (!preg_match('/^[a-zA-Z0-9-]{8,64}$/', $id)) throw new InvalidArgumentException('Identificador inválido.');
    $category=$b['category']??'Consulta';
    if(!in_array($category,['Consulta','Reclamo','Devolución','Garantía','Problema con pedido'],true))throw new InvalidArgumentException('Categoría inválida.');
    return ['clientId'=>cleanText($b['clientId']??'', 'cliente',64),'category'=>$category,'orderRef'=>cleanText($b['orderRef']??'', 'pedido',100,false),'id'=>$id, 'subject'=>cleanText($b['subject'] ?? '', 'asunto', 160), 'description'=>cleanText($b['description'] ?? '', 'descripción', 4000), 'requester'=>cleanText($b['requester'] ?? '', 'solicitante', 100), 'productId'=>cleanText($b['productId'] ?? '', 'producto o servicio', 64, false), 'priority'=>$priority];
}
function queueScore(array $t): float {
    return (array_search($t['priority'], ['Alta','Media','Baja'], true) * 100000000000) + $t['dueAt'];
}
function reportData(array $tickets): array {
    $resolved = array_values(array_filter($tickets, fn($t)=>$t['status']==='Resuelto'));
    $ok = count(array_filter($resolved, fn($t)=>$t['resolvedAt'] <= $t['dueAt']));
    $responses = array_values(array_filter($tickets, fn($t)=>isset($t['firstResponseAt'])));
    return ['total'=>count($tickets), 'open'=>count($tickets)-count($resolved), 'resolved'=>count($resolved), 'compliance'=>count($resolved) ? round(100*$ok/count($resolved),1) : null, 'averageResponseMinutes'=>count($responses) ? round(array_sum(array_map(fn($t)=>($t['firstResponseAt']-$t['createdAt'])/60,$responses))/count($responses),1) : null, 'overdue'=>count(array_filter($tickets,fn($t)=>$t['status']!=='Resuelto' && $t['dueAt']<time()))];
}
// END COMPONENT domain
// BEGIN COMPONENT integration
// Cliente RESP2 limitado a las operaciones de OpenDesk; no requiere extensión.
// Referencia: https://redis.io/docs/latest/develop/reference/protocol-spec/
final class OpenDeskRedisSocket {
    private $socket=null;
    public function __destruct(){if(is_resource($this->socket))fclose($this->socket);}
    public function connect(string $host,int $port,float $timeout):bool {
        if(!preg_match('/^(?:tls:\/\/)?[a-zA-Z0-9.\[\]:_-]+$/D',$host))throw new RuntimeException('Host Redis inválido.');
        $address=(str_starts_with($host,'tls://')?'':'tcp://').$host.':'.$port;
        $this->socket=@stream_socket_client($address,$code,$error,$timeout,STREAM_CLIENT_CONNECT);
        if(!is_resource($this->socket))return false;
        stream_set_timeout($this->socket,1);return true;
    }
    private function exact(int $length):string {
        $data='';while(strlen($data)<$length){$chunk=fread($this->socket,$length-strlen($data));if($chunk===false||$chunk==='')throw new RuntimeException('Redis lectura incompleta.');$data.=$chunk;}return $data;
    }
    private function reply(int $depth=0):mixed {
        if($depth>16)throw new RuntimeException('Redis respuesta demasiado anidada.');
        $line=fgets($this->socket,65536);if($line===false||!str_ends_with($line,"\r\n"))throw new RuntimeException('Redis sin respuesta completa.');
        $type=$line[0];$value=substr($line,1,-2);
        if($type==='+')return $value;
        if($type==='-')throw new RuntimeException('Redis rechazó la operación.');
        if(!preg_match('/^-?\d+$/D',$value))throw new RuntimeException('Redis longitud inválida.');
        $n=(int)$value;if($type===':')return $n;
        if($n===-1)return false;
        if($n<0||$n>8388608)throw new RuntimeException('Redis respuesta fuera de límite.');
        if($type==='$'){$data=$this->exact($n);if($this->exact(2)!=="\r\n")throw new RuntimeException('Redis terminador inválido.');return $data;}
        if($type==='*'){$values=[];if($n>10000)throw new RuntimeException('Redis demasiados elementos.');for($i=0;$i<$n;$i++)$values[]=$this->reply($depth+1);return $values;}
        throw new RuntimeException('Redis protocolo inesperado.');
    }
    private function command(array $args):mixed {
        if(!is_resource($this->socket))throw new RuntimeException('Redis desconectado.');
        $data='*'.count($args)."\r\n";foreach($args as $arg){$arg=(string)$arg;$data.='$'.strlen($arg)."\r\n".$arg."\r\n";}
        $offset=0;while($offset<strlen($data)){$n=fwrite($this->socket,substr($data,$offset));if($n===false||$n===0)throw new RuntimeException('Redis escritura incompleta.');$offset+=$n;}
        return $this->reply();
    }
    public function auth(string|array $credentials):bool{return $this->command(array_merge(['AUTH'],(array)$credentials))==='OK';}
    public function select(int $database):bool{return $this->command(['SELECT',$database])==='OK';}
    public function setOption(int $option,mixed $value):bool{return true;}
    public function ping():string{return $this->command(['PING']);}
    public function get(string $key):string|false{return $this->command(['GET',$key]);}
    public function setex(string $key,int $ttl,string $value):bool{return $this->command(['SETEX',$key,$ttl,$value])==='OK';}
    public function del(string $key):int{return $this->command(['DEL',$key]);}
    public function incr(string $key):int{return $this->command(['INCR',$key]);}
    public function expire(string $key,int $ttl):bool{return (bool)$this->command(['EXPIRE',$key,$ttl]);}
    public function ttl(string $key):int{return $this->command(['TTL',$key]);}
    public function zAdd(string $key,int|float $score,string $member):int{return $this->command(['ZADD',$key,$score,$member]);}
    public function hMSet(string $key,array $values):bool{$args=['HMSET',$key];foreach($values as $field=>$value){$args[]=$field;$args[]=$value;}return $this->command($args)==='OK';}
    public function hGet(string $key,string $field):string|false{return $this->command(['HGET',$key,$field]);}
    public function type(string $key):int{return ['none'=>0,'string'=>1,'set'=>2,'list'=>3,'zset'=>4,'hash'=>5][$this->command(['TYPE',$key])]??0;}
}

function redisNamespace(string $identity):string {
    $prefix=setting('REDIS_PREFIX','grupo3:opendesk:');
    if(!preg_match('/^grupo3:[a-zA-Z0-9:_-]+:$/D',$prefix))throw new InvalidArgumentException('REDIS_PREFIX debe empezar con grupo3: y terminar con dos puntos.');
    return $prefix.substr(hash('sha256',$identity),0,16);
}

// El factory opcional permite probar errores de conexión sin servicios reales.
function openRedis(?callable $factory=null):?object {
    try {
        $mode=setting('REDIS_CLIENT','auto');
        if(!in_array($mode,['auto','extension','socket'],true))return null;
        if(!$factory&&$mode==='extension'&&!extension_loaded('redis'))return null;
        $r=$factory?$factory():($mode!=='socket'&&extension_loaded('redis')?new Redis():new OpenDeskRedisSocket());
        $port=filter_var(setting('REDIS_PORT','6379'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        $database=filter_var(setting('REDIS_DATABASE','0'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if($port===false||$database===false)return null;
        if(!$r->connect(setting('REDIS_HOST','127.0.0.1'),$port,1.0))return null;
        $password=setting('REDIS_PASSWORD');$username=setting('REDIS_USERNAME');
        if($username!==''&&$password==='')return null;
        if($password!==''&&!$r->auth($username!==''?[$username,$password]:$password))return null;
        if(!$r->select($database))return null;
        if(defined('Redis::OPT_READ_TIMEOUT'))$r->setOption(Redis::OPT_READ_TIMEOUT,1.0);
        $pong=$r->ping();
        return in_array($pong,[true,'PONG','+PONG'],true)?$r:null;
    } catch(Throwable $e){return null;}
}

function writeQueueCache(object $r,string $key,array $items):void {
    $r->setex($key,15,json_encode($items,JSON_THROW_ON_ERROR));
    $r->del($key.':order');
    foreach($items as $t)$r->zAdd($key.':order',queueScore($t),$t['id']);
    if($items)$r->expire($key.':order',15);
    // Hash: metadatos de la instantánea que la API entrega junto con la cola.
    $r->hMSet($key.':meta',['count'=>(string)count($items),'builtAt'=>(string)time()]);
    $r->expire($key.':meta',15);
}

function sampleMedian(array $samples):float {
    if(!$samples)throw new InvalidArgumentException('Se requieren muestras.');
    foreach($samples as $x)if(!is_int($x)&&!is_float($x))throw new InvalidArgumentException('Muestra no numérica.');
    sort($samples,SORT_NUMERIC);$n=count($samples);$m=intdiv($n,2);
    return $n%2?(float)$samples[$m]:($samples[$m-1]+$samples[$m])/2;
}

function mongoSchemas():array {
    $text=fn(int $max=160)=>['bsonType'=>'string','maxLength'=>$max];
    $id=['bsonType'=>'string','minLength'=>1,'maxLength'=>64];
    $time=['bsonType'=>['int','long'],'minimum'=>0];
    return [
        'odac_tickets'=>['bsonType'=>'object','required'=>['id','subject','description','clientId','priority','status','createdAt','dueAt','history'],'properties'=>[
            'id'=>$id,'subject'=>['bsonType'=>'string','minLength'=>1,'maxLength'=>160],
            'description'=>['bsonType'=>'string','minLength'=>1,'maxLength'=>4000],
            'clientId'=>$id,'productId'=>$text(64),'assigneeId'=>$text(64),'createdBy'=>$text(64),
            'priority'=>['enum'=>['Alta','Media','Baja']],'status'=>['enum'=>STATUSES],
            'category'=>['enum'=>['Consulta','Reclamo','Devolución','Garantía','Problema con pedido']],
            'createdAt'=>$time,'dueAt'=>$time,'firstResponseAt'=>$time,'resolvedAt'=>$time,
            'history'=>['bsonType'=>'array','items'=>['bsonType'=>'object','required'=>['date','text'],'properties'=>['date'=>$time,'text'=>$text(2400)]]],
        ]],
        'odac_users'=>['bsonType'=>'object','required'=>['id','name','email','role','active','clientId','passwordHash','version','mustChangePassword'],'properties'=>[
            'id'=>$id,'name'=>$text(100),'email'=>$text(160),'clientId'=>$id,'passwordHash'=>['bsonType'=>'string','minLength'=>20],
            'role'=>['enum'=>USER_ROLES],'active'=>['bsonType'=>'bool'],'mustChangePassword'=>['bsonType'=>'bool'],
            'version'=>['bsonType'=>['int','long'],'minimum'=>1],
        ]],
        'odac_clients'=>['bsonType'=>'object','required'=>['id','name','email','phone','company'],'properties'=>[
            'id'=>$id,'name'=>$text(100),'email'=>$text(160),'phone'=>$text(40),'company'=>$text(120),
        ]],
        'odac_products'=>['bsonType'=>'object','required'=>['id','name','type','category','reference','status'],'properties'=>[
            'id'=>$id,'name'=>$text(100),'type'=>['enum'=>['Producto','Servicio']],'category'=>$text(100),'reference'=>$text(100),'status'=>['enum'=>['Disponible','Suspendido','Retirado']],
        ]],
    ];
}

// Valida el subconjunto JSON Schema utilizado por mongoSchemas(), sin collMod.
// No sustituye validadores del motor para escrituras hechas fuera de OpenDesk.
function validateSchemaValue(mixed $value,array $schema,string $path):void {
    if(isset($schema['enum'])&&!in_array($value,$schema['enum'],true))throw new InvalidArgumentException('Valor no permitido en '.$path.'.');
    if(isset($schema['bsonType'])){
        $valid=false;foreach((array)$schema['bsonType'] as $type){$valid=$valid||match($type){
            'object'=>is_array($value)&&($value===[]||!array_is_list($value)),
            'array'=>is_array($value)&&array_is_list($value),'string'=>is_string($value),
            'int','long'=>is_int($value),'bool'=>is_bool($value),default=>false};}
        if(!$valid)throw new InvalidArgumentException('Tipo inválido en '.$path.'.');
    }
    if(is_string($value)){
        $length=preg_match_all('/./us',$value);if($length===false)throw new InvalidArgumentException('Texto inválido en '.$path.'.');
        if(isset($schema['minLength'])&&$length<$schema['minLength']||isset($schema['maxLength'])&&$length>$schema['maxLength'])throw new InvalidArgumentException('Longitud inválida en '.$path.'.');
    }
    if(isset($schema['minimum'])&&(!is_int($value)||$value<$schema['minimum']))throw new InvalidArgumentException('Valor fuera de rango en '.$path.'.');
    foreach($schema['required']??[] as $field)if(!is_array($value)||!array_key_exists($field,$value))throw new InvalidArgumentException('Falta '.$path.'.'.$field.'.');
    foreach($schema['properties']??[] as $field=>$rule)if(is_array($value)&&array_key_exists($field,$value))validateSchemaValue($value[$field],$rule,$path.'.'.$field);
    if(isset($schema['items'])&&is_array($value))foreach($value as $index=>$item)validateSchemaValue($item,$schema['items'],$path.'['.$index.']');
}
function validateCollectionDocument(string $collection,array $document):void {
    $schema=mongoSchemas()[$collection]??null;if($schema)validateSchemaValue($document,$schema,$collection);
}
function schemaUpdatedDocument(array $document,array $update,bool $insert):array {
    foreach($update as $operator=>$fields){
        if(!in_array($operator,['$set','$setOnInsert','$inc','$push','$unset'],true)||!is_array($fields))throw new InvalidArgumentException('Actualización no soportada por el esquema PHP.');
        foreach($fields as $field=>$value){
            if(!is_string($field)||str_contains($field,'.')||str_starts_with($field,'$'))throw new InvalidArgumentException('Campo de actualización inválido.');
            if($operator==='$set'||$operator==='$setOnInsert'&&$insert)$document[$field]=$value;
            elseif($operator==='$unset')unset($document[$field]);
            elseif($operator==='$inc'){if(!is_int($value)||isset($document[$field])&&!is_int($document[$field]))throw new InvalidArgumentException('Incremento inválido.');$document[$field]=($document[$field]??0)+$value;}
            elseif($operator==='$push'){if(isset($document[$field])&&(!is_array($document[$field])||!array_is_list($document[$field])))throw new InvalidArgumentException('Lista inválida.');$document[$field][]=$value;}
        }
    }return $document;
}

function installMongoSchemas(Database $database):array {
    $schemas=mongoSchemas();$invalid=[];
    // Comprobar TODOS antes de modificar validadores. No borrar ni corregir datos reales.
    foreach($schemas as $name=>$schema){$n=$database->selectCollection($name)->countDocuments(['$nor'=>[['$jsonSchema'=>$schema]]]);if($n)$invalid[$name]=$n;}
    if($invalid)throw new RuntimeException('Hay documentos incompatibles; revisar antes de activar JSON Schema.');
    foreach($schemas as $name=>$schema){
        $options=['validator'=>['$jsonSchema'=>$schema],'validationLevel'=>'strict','validationAction'=>'error'];
        try{$database->command(['collMod'=>$name]+$options);}catch(Throwable $e){if($e->getCode()!==26)throw $e;$database->command(['create'=>$name]+$options);}
    }
    foreach(array_keys($schemas) as $name)$database->selectCollection($name)->createIndex(['id'=>1],['unique'=>true]);
    $database->odac_users->createIndex(['email'=>1],['unique'=>true]);
    foreach([['status'=>1,'priority'=>1,'dueAt'=>1],['clientId'=>1,'createdAt'=>-1],['assigneeId'=>1,'status'=>1]] as $keys)$database->odac_tickets->createIndex($keys);
    return array_keys($schemas);
}

function serviceDiagnostics():array {
    $result=['checkedAt'=>gmdate(DATE_ATOM),'mongo'=>false,'redis'=>false,'mongoExtension'=>extension_loaded('mongodb'),'redisExtension'=>extension_loaded('redis')];
    try{$result['mongo']=(bool)(db()->command(['ping'=>1])->toArray()[0]['ok']??false);}catch(Throwable $e){}
    $r=redisClient();$result['redisClient']=$r instanceof OpenDeskRedisSocket?'php_socket':($r?'php_extension':'unavailable');if(!$r)return $result;
    $key=connectionConfig()['namespace'].':diagnostic:'.bin2hex(random_bytes(12));
    try{
        writeQueueCache($r,$key,[['id'=>'diagnostic','priority'=>'Alta','dueAt'=>time()+60]]);
        $result['redis']=$r->type($key)===1&&$r->type($key.':order')===4&&$r->type($key.':meta')===5&&$r->ttl($key)>0&&(int)$r->hGet($key.':meta','count')===1;
        $result['redisStructures']=['string','sorted_set','hash'];
    }catch(Throwable $e){$result['redis']=false;}
    finally{try{foreach([$key,$key.':order',$key.':meta'] as $k)$r->del($k);}catch(Throwable $e){}}
    return $result;
}

function benchmarkServices(int $count,int $runs):array {
    if($count<100||$count>100000||$runs<3||$runs>1000)throw new InvalidArgumentException('Volumen 100..100000; ejecuciones 3..1000.');
    $database=db();$redis=redisClient();if(!$redis)throw new RuntimeException('Redis no disponible.');
    $run=bin2hex(random_bytes(16));$col=$database->odac_benchmark_tickets;$key=connectionConfig()['namespace'].':benchmark:'.$run;
    $filter=['run'=>$run,'status'=>'Nuevo'];$opts=['sort'=>['priority'=>1,'dueAt'=>1],'limit'=>100,'projection'=>['_id'=>0]];
    $mongoRead=fn()=>$col->find($filter,$opts)->toArray();$samples=['mongo_queue'=>[],'redis_queue'=>[],'mongo_aggregation'=>[]];
    $pipeline=[['$match'=>['run'=>$run]],['$group'=>['_id'=>'$priority','total'=>['$sum'=>1]]],['$sort'=>['_id'=>1]]];
    try{
        $col->createIndex(['run'=>1,'status'=>1,'priority'=>1,'dueAt'=>1]);
        for($base=0;$base<$count;$base+=1000){$batch=[];for($i=$base;$i<min($count,$base+1000);$i++)$batch[]=['run'=>$run,'number'=>$i,'priority'=>$i%3,'status'=>'Nuevo','dueAt'=>time()+$i,'subject'=>'Dato sintético'];$col->insertMany($batch);}
        $payload=$mongoRead();if(!$redis->setex($key,600,json_encode($payload,JSON_THROW_ON_ERROR)))throw new RuntimeException('No se pudo preparar caché.');
        // Calentamiento fuera de muestras. Consultas y serialización comparables.
        $mongoRead();json_decode($redis->get($key),true,512,JSON_THROW_ON_ERROR);
        for($i=0;$i<$runs;$i++){
            foreach($i%2?['redis_queue','mongo_queue']:['mongo_queue','redis_queue'] as $op){$t=hrtime(true);if($op==='mongo_queue')$value=$mongoRead();else{$raw=$redis->get($key);if($raw===false)throw new RuntimeException('Caché expirada durante medición.');$value=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}if(count($value)!==count($payload))throw new RuntimeException('Resultados no comparables.');$samples[$op][]=(hrtime(true)-$t)/1e6;}
            $t=hrtime(true);$summary=$database->command(['aggregate'=>'odac_benchmark_tickets','pipeline'=>$pipeline,'cursor'=>(object)[]])->toArray();
            $samples['mongo_aggregation'][]=(hrtime(true)-$t)/1e6;
        }
        $explain=$database->command(['explain'=>['find'=>'odac_benchmark_tickets','filter'=>$filter,'sort'=>$opts['sort'],'limit'=>100],'verbosity'=>'executionStats'])->toArray()[0];
        return ['measuredAt'=>gmdate(DATE_ATOM),'php'=>PHP_VERSION,'documents'=>$count,'pageSize'=>100,'runs'=>$runs,'cacheMode'=>'warm','operations'=>array_map(fn($v)=>['samplesMs'=>$v,'medianMs'=>round(sampleMedian($v),4)],$samples),'mongoExplain'=>$explain,'aggregation'=>$summary,'peakPhpMemoryMB'=>round(memory_get_peak_usage(true)/1048576,2)];
    }finally{try{$col->deleteMany(['run'=>$run]);}finally{$redis->del($key);}}
}
// END COMPONENT integration
function db(): Database {
    static $db;
    $c=connectionConfig();
    return $db ??= new Database(new MongoDB\Driver\Manager($c['uri'], ['serverSelectionTimeoutMS'=>2500]),$c['database']);
}
function redisClient(): ?object { return openRedis(); }
function jsonResponse(Response $r, mixed $data, int $code=200): Response {
    $r->getBody()->write(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    return $r->withHeader('Content-Type','application/json')->withHeader('Cache-Control','no-store')->withStatus($code);
}
function documents(string $collection): array { return db()->selectCollection($collection)->find([],['projection'=>['_id'=>0]])->toArray(); }
function invalidate(): void { try { $r=redisClient(); if($r) $r->incr(connectionConfig()['namespace'].':version'); } catch(Throwable $e) {} }
// BEGIN COMPONENT security
const USER_ROLES=['cliente','agente','administrador'];
function userPublic(array $u):array{return array_intersect_key($u,array_flip(['id','name','email','role','active','clientId','mustChangePassword','createdAt']))+['cacheVersion'=>$u['version']??1];}
function passwordInput(mixed $p):string{if(!is_string($p)||str_contains($p,"\0")||preg_match_all('/./us',$p)<12||strlen($p)>72)throw new InvalidArgumentException('La contraseña debe tener al menos 12 caracteres y como máximo 72 bytes, sin caracteres nulos.');return $p;}
function loginPassword(mixed $p):string{if(!is_string($p)||strlen($p)>72||str_contains($p,"\0"))throw new InvalidArgumentException('Contraseña inválida.');return $p;}
function emailInput(mixed $email):string{$email=strtolower(cleanText($email,'correo',160));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Correo inválido.');return $email;}
function userId():string{return bin2hex(random_bytes(16));}
function initSecurity():void{db()->odac_users->createIndex(['id'=>1],['unique'=>true]);db()->odac_users->createIndex(['email'=>1],['unique'=>true]);db()->odac_auth_limits->createIndex(['id'=>1],['unique'=>true]);db()->odac_settings->createIndex(['id'=>1],['unique'=>true]);db()->odac_settings->updateOne(['id'=>'security'],['$setOnInsert'=>['id'=>'security','adminIds'=>[]]],['upsert'=>true]);}
function adminIds():array{return db()->odac_settings->findOne(['id'=>'security'])['adminIds']??[];}
function liveUser():?array{
    if(empty($_SESSION['userId']))return null;
    $u=db()->odac_users->findOne(['id'=>$_SESSION['userId']]);
    $expired=time()-($_SESSION['lastSeen']??0)>1800||time()-($_SESSION['startedAt']??0)>28800;
    if(!$u||empty($u['active'])||$expired||($u['version']??1)!==($_SESSION['userVersion']??0)||($u['role']==='administrador'&&!in_array($u['id'],adminIds(),true))){unset($_SESSION['userId'],$_SESSION['userVersion']);return null;}
    $_SESSION['lastSeen']=time();return $u;
}
function actor():array{return $GLOBALS['currentUser']??throw new RuntimeException('Sin usuario.');}
function isAdmin():bool{return actor()['role']==='administrador';}
function allowedRole(array $roles):bool{return in_array(actor()['role'],$roles,true);}
function deny(Response $res,string $msg='No tiene permiso para esta operación.'):Response{return jsonResponse($res,['error'=>$msg],403);}
function audit(string $action,string $target,array $details=[]):void{db()->odac_audit->insertOne(['id'=>userId(),'date'=>time(),'actorId'=>$GLOBALS['currentUser']['id']??'terminal','actorName'=>$GLOBALS['currentUser']['name']??'Instalación','action'=>$action,'target'=>$target,'details'=>$details]);}
function ticketFilter():array{$u=actor();if($u['role']==='administrador')return [];if($u['role']==='cliente')return ['clientId'=>$u['clientId']];return ['$or'=>[['assigneeId'=>$u['id']],['createdBy'=>$u['id']],['clientId'=>$u['clientId']]]];}
function visibleTicket(string $id):?array{return db()->odac_tickets->findOne(['id'=>$id]+ticketFilter(),['projection'=>['_id'=>0]]);}
function visibleTickets():array{return db()->odac_tickets->find(ticketFilter(),['projection'=>['_id'=>0]])->toArray();}
function policy():array{return db()->odac_settings->findOne(['id'=>'sla'])['hours']??SLA_HOURS;}
function authLimit(string $key,int $max):bool{
    $key=connectionConfig()['namespace'].':auth:'.hash('sha256',$key);$r=redisClient();
    try{if($r){$n=$r->incr($key);if($n===1)$r->expire($key,600);return $n<=$max;}}catch(Throwable $e){}
    // Contingencia duradera: borrar cookies no elimina el contador MongoDB.
    db()->odac_auth_limits->createIndex(['id'=>1],['unique'=>true]);
    db()->odac_auth_limits->updateOne(['id'=>$key],['$setOnInsert'=>['id'=>$key,'attempts'=>0,'expiresAt'=>time()+600]],['upsert'=>true]);
    db()->odac_auth_limits->updateOne(['id'=>$key,'expiresAt'=>['$lte'=>time()]],['$set'=>['attempts'=>0,'expiresAt'=>time()+600]]);
    db()->odac_auth_limits->updateOne(['id'=>$key],['$inc'=>['attempts'=>1]]);
    return (db()->odac_auth_limits->findOne(['id'=>$key])['attempts']??$max+1)<=$max;
}
function createUser(array $b,bool $public=false):array{
    $email=emailInput($b['email']??'');$name=cleanText($b['name']??'','nombre',100);$password=passwordInput($b['password']??'');
    $phone=cleanText($b['phone']??'','teléfono',40,false);
    $role=$public?'cliente':($b['role']??'cliente');if(!in_array($role,USER_ROLES,true))throw new InvalidArgumentException('Rol inválido.');
    initSecurity();if(db()->odac_users->findOne(['email'=>$email]))throw new InvalidArgumentException('No se pudo registrar esa cuenta. Revise los datos o inicie sesión.');
    $id=userId();$u=['id'=>$id,'name'=>$name,'email'=>$email,'role'=>$role,'active'=>true,'clientId'=>$id,'passwordHash'=>password_hash($password,PASSWORD_DEFAULT),'version'=>1,'mustChangePassword'=>!$public,'createdAt'=>time()];
    db()->odac_clients->createIndex(['id'=>1],['unique'=>true]);
    db()->odac_users->insertOne($u);
    db()->odac_clients->updateOne(['id'=>$id],['$setOnInsert'=>['id'=>$id,'name'=>$name,'email'=>$email,'phone'=>$phone,'company'=>'','createdAt'=>time()]],['upsert'=>true]);
    if($role==='administrador')db()->odac_settings->updateOne(['id'=>'security'],['$addToSet'=>['adminIds'=>$id]]);
    audit($public?'registro_cliente':'crear_usuario',$id,['role'=>$role]);return userPublic($u);
}
function reserveAdminRemoval(array $u):bool{
    if(!in_array($u['id'],adminIds(),true))return true;
    // Condición y retirada en UN documento: dos bajas simultáneas no eliminan al último administrador.
    $r=db()->odac_settings->updateOne(['id'=>'security','adminIds'=>$u['id'],'adminIds.1'=>['$exists'=>true]],['$pull'=>['adminIds'=>$u['id']]]);
    return $r->getMatchedCount()>0;
}
function restoreAdmin(array $u):void{$now=db()->odac_users->findOne(['id'=>$u['id']]);if($now&&$now['active']&&$now['role']==='administrador')db()->odac_settings->updateOne(['id'=>'security'],['$addToSet'=>['adminIds'=>$u['id']]]);}
function beginUserSession(array $u):void{session_regenerate_id(true);$_SESSION['userId']=$u['id'];$_SESSION['userVersion']=$u['version']??1;$_SESSION['startedAt']=time();$_SESSION['lastSeen']=time();$_SESSION['csrf']=bin2hex(random_bytes(24));}
function bootstrapAdmin(string $email):void{
    initSecurity();if(adminIds())throw new InvalidArgumentException('Ya existe un administrador. Créelo desde la pantalla Usuarios.');
    $email=emailInput($email);$id='initial-administrator';$hash=accessHash();if(!$hash)throw new RuntimeException('Sin contraseña inicial.');
    db()->odac_users->updateOne(['id'=>$id],['$setOnInsert'=>['id'=>$id,'name'=>'Administrador inicial','email'=>$email,'role'=>'administrador','active'=>true,'clientId'=>$id,'passwordHash'=>$hash,'version'=>1,'mustChangePassword'=>true,'createdAt'=>time()]],['upsert'=>true]);
    db()->odac_clients->updateOne(['id'=>$id],['$setOnInsert'=>['id'=>$id,'name'=>'Administrador inicial','email'=>$email,'phone'=>'','company'=>'','createdAt'=>time()]],['upsert'=>true]);
    db()->odac_settings->updateOne(['id'=>'security'],['$addToSet'=>['adminIds'=>$id]]);audit('administrador_inicial',$id);
    echo "Administrador inicial preparado. Use su correo y la contraseña del archivo privado.\n";
}
// END COMPONENT security
if(defined('OPENDESK_LIBRARY_ONLY')&&OPENDESK_LIBRARY_ONLY)return;
if(PHP_SAPI==='cli'){
$option=$argv[1]??'--help';
try{
if($option==='--init-admin'){bootstrapAdmin($argv[2]??'');exit;}
if($option==='--test'){
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$input=['id'=>'test-12345678','clientId'=>'cliente-01','subject'=>'  Pedido  ','description'=>'Llegó incompleto','requester'=>'Cliente','productId'=>'','priority'=>'Alta','category'=>'Reclamo','orderRef'=>'PED-01'];
check(ticketInput($input)['subject']==='Pedido','Normalización');
foreach([['priority','Urgente'],['category','Otra inventada'],['clientId','']] as [$k,$v]){try{$bad=$input;$bad[$k]=$v;ticketInput($bad);throw new RuntimeException('Aceptó campo inválido: '.$k);}catch(InvalidArgumentException $e){}}
check(queueScore(['priority'=>'Alta','dueAt'=>1900000000])<queueScore(['priority'=>'Media','dueAt'=>1700000000]),'Orden por prioridad');
$r=reportData([['status'=>'Resuelto','resolvedAt'=>20,'dueAt'=>30,'createdAt'=>0,'firstResponseAt'=>60],['status'=>'Resuelto','resolvedAt'=>40,'dueAt'=>30,'createdAt'=>0],['status'=>'Esperando cliente','dueAt'=>1,'createdAt'=>0]]);
check($r['compliance']===50.0,'SLA resolución');check($r['averageResponseMinutes']===1.0,'Primera respuesta');check($r['overdue']===1,'Esperando cliente consume SLA');check(reportData([])['compliance']===null,'Sin datos');
echo "OK: categoría, cliente, validación, prioridad, SLA y primera respuesta.\n";
exit;}
if($option==='--check'){echo 'PHP '.PHP_VERSION.' · Perfil '.appProfile().PHP_EOL;foreach(['mongodb','redis'] as $e)echo $e.': '.(extension_loaded($e)?phpversion($e):'FALTA').PHP_EOL;exit;}
if($option==='--diagnose'){$result=serviceDiagnostics();echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;exit($result['mongo']&&$result['redis']?0:2);}
if($option==='--schema'){echo json_encode(['validatedCollections'=>installMongoSchemas(db())],JSON_PRETTY_PRINT).PHP_EOL;exit;}
if($option==='--seed'){
$c=connectionConfig();
$db=new Database(new MongoDB\Driver\Manager($c['uri'],['serverSelectionTimeoutMS'=>5000]),$c['database']);
foreach(['odac_tickets','odac_clients','odac_products'] as $name)$db->selectCollection($name)->createIndex(['id'=>1],['unique'=>true]);
$db->odac_tickets->createIndex(['status'=>1,'priority'=>1,'dueAt'=>1]);
$db->odac_tickets->createIndex(['clientId'=>1,'createdAt'=>-1]);
$db->odac_tickets->createIndex(['productId'=>1,'createdAt'=>-1]);
echo "Colecciones e índices OpenDesk listos en el perfil ".$c['profile'].".\n";
$now=time();
$clients=[['demo-cliente-01','Cliente de ejemplo A','cliente.a@example.com','0000-0000','Tienda de ejemplo'],['demo-cliente-02','Cliente de ejemplo B','cliente.b@example.com','0000-0000',''],['demo-cliente-03','Cliente de ejemplo C','cliente.c@example.com','0000-0000','']];
foreach($clients as [$id,$name,$email,$phone,$company])$db->odac_clients->updateOne(['id'=>$id],['$setOnInsert'=>compact('id','name','email','phone','company')+['createdAt'=>$now]],['upsert'=>true]);
$products=[['PROD-001','Cafetera','Producto','Hogar','Modelo de ejemplo'],['PROD-002','Mochila','Producto','Accesorios','SKU de ejemplo'],['SERV-001','Entrega a domicilio','Servicio','Logística','Servicio de entrega']];
foreach($products as [$id,$name,$type,$category,$reference])$db->odac_products->updateOne(['id'=>$id],['$setOnInsert'=>compact('id','name','type','category','reference')+['status'=>'Disponible','createdAt'=>$now]],['upsert'=>true]);
$examples=[['demo-ticket-101','Mi pedido llegó incompleto','demo-cliente-01','PROD-001','Reclamo','Alta','En atención','Agente de ejemplo',3],['demo-ticket-102','Consulta sobre garantía','demo-cliente-02','PROD-002','Garantía','Media','Nuevo','',2],['demo-ticket-103','Cambio de dirección de entrega','demo-cliente-03','SERV-001','Problema con pedido','Baja','Esperando cliente','Agente de ejemplo',1],['demo-ticket-104','Solicitud de devolución','demo-cliente-01','PROD-002','Devolución','Media','Resuelto','Agente de ejemplo',5]];
foreach($examples as [$id,$subject,$clientId,$productId,$category,$priority,$status,$assignee,$age]){
 $created=$now-$age*3600;$client=$db->odac_clients->findOne(['id'=>$clientId]);
 $t=compact('id','subject','clientId','productId','category','priority','status','assignee')+['requester'=>$client['name'],'orderRef'=>'PED-DEMO','description'=>'Datos ficticios para probar el flujo de atención.','createdAt'=>$created,'dueAt'=>$created+(['Alta'=>4,'Media'=>24,'Baja'=>72][$priority])*3600,'history'=>[['date'=>$created,'text'=>'Solicitud de demostración registrada.']]];
 if($assignee){$t['firstResponseAt']=$created+600;$t['history'][]=['date'=>$created+600,'text'=>'Asignación al agente de ejemplo.'];}
 if($status==='Resuelto'){$t['resolvedAt']=$created+7200;$t['history'][]=['date'=>$created+7200,'text'=>'Devolución aprobada y cliente informado (ejemplo).'];}
 $db->odac_tickets->updateOne(['id'=>$id],['$setOnInsert'=>$t],['upsert'=>true]);
}
invalidate();
echo "Datos ficticios cargados sin sobrescribir registros existentes.\n";
exit;}
if($option==='--benchmark'){$result=benchmarkServices((int)($argv[2]??1000),(int)($argv[3]??5));echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;exit;}
echo "OpenDesk · php index.php --init-admin correo | --check | --diagnose | --schema | --test | --seed | --benchmark 1000 5\n";exit;
}catch(Throwable $e){fwrite(STDERR,"No se pudo completar. Revise extensiones, conexiones y permisos MongoDB/Redis.\n");exit(1);}}
if(isset($_GET['api'])){
if(!is_string($_GET['api'])||strlen($_GET['api'])>256){http_response_code(400);header('Content-Type: application/json');echo '{"error":"Ruta inválida."}';exit;}

$app=new HttpApp();
$base=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/index.php')),'/');
if($base && $base!=='.')$app->setBasePath($base);
session_name('ODAC_SESSION');
session_name('OPENDESKSESSID');
session_start(['cookie_path'=>rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/index.php')),'/').'/', 'cookie_httponly'=>true,'cookie_samesite'=>'Strict','cookie_secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'use_strict_mode'=>true]);
$_SESSION['csrf']??=bin2hex(random_bytes(24));
$app->addBodyParsingMiddleware();
$app->add(function(Request $req,$handler) {
    try { return $handler->handle($req); }
    catch(InvalidArgumentException $e) { return jsonResponse(new Response(),['error'=>$e->getMessage()],422); }
    catch(Throwable $e) { error_log('OpenDesk: '.get_class($e)); return jsonResponse(new Response(),['error'=>'No se pudo completar la operación. Revise MongoDB y reintente.'],503); }
});
// BEGIN COMPONENT routes
$app->add(function($req,$handler){
    $path=$req->getUri()->getPath();$public=in_array($path,['/api/login','/api/register','/api/session','/api/logout'],true);
    if(!in_array($req->getMethod(),['GET','HEAD','OPTIONS'],true)&&!hash_equals($_SESSION['csrf'],$req->getHeaderLine('X-CSRF-Token')))return jsonResponse(new Response(),['error'=>'Sesión vencida. Actualice la página.'],403);
    if(!$public){$u=liveUser();if(!$u)return jsonResponse(new Response(),['error'=>'Inicie sesión para continuar.'],401);$GLOBALS['currentUser']=$u;if($u['mustChangePassword']&&$path!=='/api/password')return deny(new Response(),'Cambie la contraseña temporal para continuar.');}
    return $handler->handle($req);
});
$app->get('/api/session',function($req,$res){$u=liveUser();return jsonResponse($res,['authenticated'=>(bool)$u,'user'=>$u?userPublic($u):null,'csrf'=>$_SESSION['csrf'],'profile'=>appProfile()]);});
$app->post('/api/register',function($req,$res){if(!authLimit('registration-ip:'.($_SERVER['REMOTE_ADDR']??''),10))return jsonResponse($res,['error'=>'Demasiados registros. Reintente en diez minutos.'],429);return jsonResponse($res,['ok'=>true,'user'=>createUser($req->getParsedBody(),true)],201);});
$app->post('/api/login',function($req,$res){
    $b=$req->getParsedBody();$email=emailInput($b['email']??'');
    if(!authLimit('login-ip:'.($_SERVER['REMOTE_ADDR']??''),30)||!authLimit('login-email:'.$email,10))return jsonResponse($res,['error'=>'Demasiados intentos. Espere diez minutos.'],429);
    $u=db()->odac_users->findOne(['email'=>$email]);$hash=$u['passwordHash']??accessHash();
    $password=loginPassword($b['password']??'');$ok=password_verify($password,$hash);
    if(!$ok||!$u||!$u['active']||($u['role']==='administrador'&&!in_array($u['id'],adminIds(),true)))return jsonResponse($res,['error'=>'Correo o contraseña incorrectos, o cuenta desactivada.'],401);
    if(password_needs_rehash($hash,PASSWORD_DEFAULT))db()->odac_users->updateOne(['id'=>$u['id'],'version'=>$u['version']],['$set'=>['passwordHash'=>password_hash($password,PASSWORD_DEFAULT)]]);
    beginUserSession($u);$GLOBALS['currentUser']=$u;audit('inicio_sesion',$u['id']);return jsonResponse($res,['ok'=>true,'user'=>userPublic($u),'csrf'=>$_SESSION['csrf']]);
});
$app->post('/api/logout',function($req,$res){$_SESSION=[];if(ini_get('session.use_cookies')){$c=session_get_cookie_params();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>$c['path'],'domain'=>$c['domain'],'secure'=>$c['secure'],'httponly'=>true,'samesite'=>'Strict']);}session_destroy();return jsonResponse($res,['ok'=>true]);});
$app->post('/api/password',function($req,$res){$u=actor();$b=$req->getParsedBody();if(!authLimit('password-change:'.$u['id'],10))return jsonResponse($res,['error'=>'Demasiados intentos. Espere diez minutos.'],429);if(!password_verify(loginPassword($b['currentPassword']??''),$u['passwordHash']))return deny($res,'La contraseña actual no coincide.');$p=passwordInput($b['password']??'');if(password_verify($p,$u['passwordHash']))throw new InvalidArgumentException('Use una contraseña diferente.');$r=db()->odac_users->updateOne(['id'=>$u['id'],'version'=>$u['version']],['$set'=>['passwordHash'=>password_hash($p,PASSWORD_DEFAULT),'mustChangePassword'=>false],'$inc'=>['version'=>1]]);if(!$r->getMatchedCount())return jsonResponse($res,['error'=>'La cuenta cambió. Inicie sesión nuevamente.'],409);$u['version']++;$u['mustChangePassword']=false;beginUserSession($u);audit('cambio_contraseña',$u['id']);return jsonResponse($res,['ok'=>true,'user'=>userPublic($u),'csrf'=>$_SESSION['csrf']]);});
$app->get('/api/health',function($req,$res){return jsonResponse($res,['mongo'=>true,'redis'=>(bool)redisClient(),'profile'=>appProfile()]);});
$app->get('/api/tickets',fn($req,$res)=>jsonResponse($res,visibleTickets()));
$app->get('/api/queue',function($req,$res){
    $u=actor();$key=connectionConfig()['namespace'].':queue:'.$u['id'].':'.$u['version'].':';$r=redisClient();
    try{if($r){$key.=($r->get(connectionConfig()['namespace'].':version')?:'0');$cache=$r->get($key);if($cache!==false){$items=json_decode($cache,true,512,JSON_THROW_ON_ERROR);if(!is_array($items))throw new RuntimeException('Caché inválida.');return jsonResponse($res,['items'=>$items,'source'=>'Redis','cachedAt'=>(int)$r->hGet($key.':meta','builtAt')]);}}}catch(Throwable $e){$r=null;}
    $items=array_values(array_filter(visibleTickets(),fn($t)=>$t['status']!=='Resuelto'));usort($items,fn($a,$b)=>queueScore($a)<=>queueScore($b));
    try{if($r)writeQueueCache($r,$key,$items);}catch(Throwable $e){}
    return jsonResponse($res,['items'=>$items,'source'=>'MongoDB']);
});
$app->get('/api/reports',fn($req,$res)=>isAdmin()?jsonResponse($res,reportData(visibleTickets())):deny($res));
$app->get('/api/agents',function($req,$res){if(actor()['role']==='cliente')return jsonResponse($res,[]);$filter=isAdmin()?['active'=>true,'role'=>['$in'=>['agente','administrador']]]:['id'=>actor()['id']];return jsonResponse($res,array_map(fn($u)=>['id'=>$u['id'],'name'=>$u['name']],db()->odac_users->find($filter)->toArray()));});
$app->post('/api/tickets',function($req,$res){
    $u=actor();$body=$req->getParsedBody();if($u['role']!=='administrador')$body['clientId']=$u['clientId'];$body['requester']=$u['name'];$b=ticketInput($body);
    $existing=db()->odac_tickets->findOne(['id'=>$b['id']],['projection'=>['_id'=>0]]);if($existing){if(!visibleTicket($b['id']))return deny($res);return jsonResponse($res,$existing);}
    $client=db()->odac_clients->findOne(['id'=>$b['clientId']]);if(!$client)throw new InvalidArgumentException('Cliente no encontrado.');
    if($b['productId']&&!db()->odac_products->findOne(['id'=>$b['productId']]))throw new InvalidArgumentException('Producto o servicio no encontrado.');
    $b['requester']=$client['name'];$h=policy();$t=$b+['status'=>'Nuevo','assignee'=>'','assigneeId'=>'','createdBy'=>$u['id'],'createdAt'=>time(),'dueAt'=>time()+$h[$b['priority']]*3600,'history'=>[['date'=>time(),'text'=>'Solicitud registrada por '.$u['name']]]];
    db()->odac_tickets->createIndex(['id'=>1],['unique'=>true]);db()->odac_tickets->updateOne(['id'=>$b['id']],['$setOnInsert'=>$t],['upsert'=>true]);invalidate();audit('crear_ticket',$b['id']);return jsonResponse($res,db()->odac_tickets->findOne(['id'=>$b['id']],['projection'=>['_id'=>0]]),201);
});
$app->patch('/api/tickets/{id}',function($req,$res,$args){
    $t=visibleTicket($args['id']);if(!$t)return jsonResponse($res,['error'=>'Ticket no encontrado.'],404);$b=$req->getParsedBody();$u=actor();if(!$b)throw new InvalidArgumentException('No hay cambios.');$note=cleanText($b['note']??'','nota',2000,false);$set=[];
    if($u['role']==='cliente'){if(array_diff(array_keys($b),['note']))return deny($res);if(!$note)throw new InvalidArgumentException('Escriba un comentario.');}
    else{
        if($t['status']==='Resuelto'&&!isAdmin())throw new InvalidArgumentException('El ticket ya está resuelto.');
        $status=$b['status']??$t['status'];if(!is_string($status)||!in_array($status,STATUSES,true))throw new InvalidArgumentException('Estado inválido.');
        $aid=cleanText($b['assigneeId']??($t['assigneeId']??''),'responsable',64,false);if(!isAdmin()&&$aid!==$u['id'])return deny($res,'El agente solo puede atender sus tickets.');
        $a=$aid?db()->odac_users->findOne(['id'=>$aid,'active'=>true,'role'=>['$in'=>['agente','administrador']]]):null;if($aid&&!$a)throw new InvalidArgumentException('Responsable inválido.');
        if($status!=='Nuevo'&&!$a)throw new InvalidArgumentException('Asigne un responsable.');if($status==='Resuelto'&&!$note)throw new InvalidArgumentException('Registre la solución.');
        $set=['status'=>$status,'assigneeId'=>$aid,'assignee'=>$a['name']??''];if(!isset($t['firstResponseAt'])&&($a||$note))$set['firstResponseAt']=time();if($status==='Resuelto')$set['resolvedAt']=time();
    }
    $event=['date'=>time(),'text'=>$u['name'].': '.($note?:'Actualización').' · '.($set['status']??$t['status'])];$update=['$push'=>['history'=>$event]];if($set)$update['$set']=$set;if(isset($set['status'])&&$set['status']!=='Resuelto'&&isset($t['resolvedAt']))$update['$unset']=['resolvedAt'=>''];
    $r=db()->odac_tickets->updateOne(['id'=>$t['id'],'history'=>$t['history']],$update);if(!$r->getMatchedCount())return jsonResponse($res,['error'=>'El ticket cambió. Actualice y reintente.'],409);invalidate();audit('actualizar_ticket',$t['id']);return jsonResponse($res,['ok'=>true]);
});
$app->delete('/api/tickets/{id}',function($req,$res,$args){if(!isAdmin())return deny($res);db()->odac_tickets->deleteOne(['id'=>$args['id']]);invalidate();audit('eliminar_ticket',$args['id']);return jsonResponse($res,['ok'=>true]);});
function clientFields(array $b):array{$c=['name'=>cleanText($b['name']??'','nombre',100),'email'=>cleanText($b['email']??'','correo',160,false),'phone'=>cleanText($b['phone']??'','teléfono',40,false),'company'=>cleanText($b['company']??'','empresa',120,false)];if($c['email']&&!filter_var($c['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Correo inválido.');if(!$c['email']&&!$c['phone'])throw new InvalidArgumentException('Indique correo o teléfono.');return $c;}
$app->get('/api/clients',fn($req,$res)=>jsonResponse($res,db()->odac_clients->find(actor()['role']==='cliente'?['id'=>actor()['clientId']]:[],['projection'=>['_id'=>0]])->toArray()));
$app->get('/api/products',fn($req,$res)=>jsonResponse($res,documents('odac_products')));
$app->post('/api/clients',function($req,$res){if(!isAdmin())return deny($res);$b=$req->getParsedBody();$c=clientFields($b);$c['id']=cleanText($b['id']??'','identificador',64);$c['createdAt']=time();db()->odac_clients->createIndex(['id'=>1],['unique'=>true]);if(db()->odac_clients->findOne(['id'=>$c['id']]))throw new InvalidArgumentException('Cliente existente.');db()->odac_clients->insertOne($c);audit('crear_cliente',$c['id']);unset($c['_id']);return jsonResponse($res,$c,201);});
$app->patch('/api/clients/{id}',function($req,$res,$args){if(!isAdmin())return deny($res);$c=clientFields($req->getParsedBody());$result=db()->odac_clients->updateOne(['id'=>$args['id']],['$set'=>$c]);if(!$result->getMatchedCount())return jsonResponse($res,['error'=>'Cliente no encontrado.'],404);audit('editar_cliente',$args['id']);return jsonResponse($res,['ok'=>true]);});
$app->delete('/api/clients/{id}',function($req,$res,$args){if(!isAdmin())return deny($res);if(db()->odac_users->countDocuments(['clientId'=>$args['id']])||db()->odac_tickets->countDocuments(['clientId'=>$args['id']]))throw new InvalidArgumentException('El cliente tiene una cuenta o tickets vinculados.');db()->odac_clients->deleteOne(['id'=>$args['id']]);audit('eliminar_cliente',$args['id']);return jsonResponse($res,['ok'=>true]);});
foreach(['POST','PATCH','DELETE'] as $method){$register=strtolower($method);$path='/api/products'.($method==='POST'?'':'/{id}');$app->$register($path,function($req,$res,$args=[])use($method){if(!isAdmin())return deny($res);$b=$method==='DELETE'?[]:$req->getParsedBody();$id=$method==='POST'?cleanText($b['id']??'','código',64):$args['id'];if($method==='DELETE'){if(db()->odac_tickets->countDocuments(['productId'=>$id]))throw new InvalidArgumentException('Hay tickets vinculados. Marque el producto como retirado.');db()->odac_products->deleteOne(['id'=>$id]);}else{$p=[];foreach(['name','type','category','reference','status'] as $k)$p[$k]=cleanText($b[$k]??'',$k,100);if(!in_array($p['status'],['Disponible','Suspendido','Retirado'],true))throw new InvalidArgumentException('Estado inválido.');if($method==='POST'){db()->odac_products->createIndex(['id'=>1],['unique'=>true]);if(db()->odac_products->findOne(['id'=>$id]))throw new InvalidArgumentException('Código existente.');db()->odac_products->insertOne(['id'=>$id,'createdAt'=>time()]+$p);}else{$result=db()->odac_products->updateOne(['id'=>$id],['$set'=>$p]);if(!$result->getMatchedCount())return jsonResponse($res,['error'=>'Producto no encontrado.'],404);}}audit('catalogo_'.$method,$id);return jsonResponse($res,['ok'=>true]);});}
$app->get('/api/users',fn($req,$res)=>isAdmin()?jsonResponse($res,array_map('userPublic',documents('odac_users'))):deny($res));
$app->post('/api/users',fn($req,$res)=>isAdmin()?jsonResponse($res,createUser($req->getParsedBody()),201):deny($res));
$app->patch('/api/users/{id}',function($req,$res,$args){
    if(!isAdmin())return deny($res);$u=db()->odac_users->findOne(['id'=>$args['id']]);if(!$u)return jsonResponse($res,['error'=>'Usuario no encontrado.'],404);$b=$req->getParsedBody();$role=$b['role']??$u['role'];$active=$b['active']??$u['active'];if(!in_array($role,USER_ROLES,true)||!is_bool($active))throw new InvalidArgumentException('Rol o estado inválido.');
    $name=isset($b['name'])?cleanText($b['name'],'nombre',100):$u['name'];$email=isset($b['email'])?emailInput($b['email']):$u['email'];$other=db()->odac_users->findOne(['email'=>$email]);if($other&&$other['id']!==$u['id'])throw new InvalidArgumentException('Ese correo ya está registrado.');
    $resetHash=isset($b['password'])?password_hash(passwordInput($b['password']),PASSWORD_DEFAULT):null;
    $removed=$u['role']==='administrador'&&(!$active||$role!=='administrador');if($removed&&!reserveAdminRemoval($u))return deny($res,'Debe conservar al menos un administrador activo.');
    try{$set=['role'=>$role,'active'=>$active,'name'=>$name,'email'=>$email];if(isset($b['password'])){$set['passwordHash']=$resetHash;$set['mustChangePassword']=true;}$r=db()->odac_users->updateOne(['id'=>$u['id'],'version'=>$u['version']],['$set'=>$set,'$inc'=>['version'=>1]]);if(!$r->getMatchedCount()){if($removed)restoreAdmin($u);return jsonResponse($res,['error'=>'La cuenta cambió. Actualice y reintente.'],409);}if($active&&$role==='administrador')db()->odac_settings->updateOne(['id'=>'security'],['$addToSet'=>['adminIds'=>$u['id']]]);}catch(Throwable $e){if($removed)restoreAdmin($u);throw $e;}
    audit(isset($b['password'])?'restablecer_contraseña':'editar_usuario',$u['id'],['role'=>$role,'active'=>$active]);return jsonResponse($res,['ok'=>true]);
});
$app->get('/api/settings',fn($req,$res)=>jsonResponse($res,['hours'=>policy()]));
$app->post('/api/settings',function($req,$res){if(!isAdmin())return deny($res);$hours=$req->getParsedBody()['hours']??[];foreach(SLA_HOURS as $p=>$default)if(!isset($hours[$p])||!is_int($hours[$p])||$hours[$p]<1||$hours[$p]>720)throw new InvalidArgumentException('Cada SLA debe tener entre 1 y 720 horas.');$hours=array_intersect_key($hours,SLA_HOURS);db()->odac_settings->updateOne(['id'=>'sla'],['$set'=>['hours'=>$hours]],['upsert'=>true]);audit('configurar_sla','sla',$hours);return jsonResponse($res,['ok'=>true]);});
$app->get('/api/audit',fn($req,$res)=>isAdmin()?jsonResponse($res,db()->odac_audit->find([],['sort'=>['date'=>-1],'limit'=>200,'projection'=>['_id'=>0]])->toArray()):deny($res));
// END COMPONENT routes
$app->run();exit;
}
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$meta=json_encode(['profile'=>appProfile(),'storageKey'=>storageKey()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$html=<<<'HTML_APP'
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172d2a"><title>OpenDesk · Atención al cliente</title><style>/* Fuentes del sistema: la aplicación también funciona sin Internet. */
.nuevo{background:#eef0f5;color:#687187}.en-atención{background:#e5edf9;color:#5075a8}.esperando-cliente{background:#fff3d7;color:#9a7a29}
:root{--ink:#20332e;--muted:#718079;--green:#276452;--bg:#f4f6f2;--line:#e4e9e2}*{box-sizing:border-box}body{margin:0;background:var(--bg);font:14px 'DM Sans',system-ui,sans-serif;color:var(--ink)}button,input,select,textarea{font:inherit}button{cursor:pointer}button:focus-visible,a:focus-visible{outline:3px solid #c1a851;outline-offset:3px}aside{position:fixed;inset:0 auto 0 0;width:235px;background:#172d2a;color:#d3dfd9;padding:36px 24px;display:flex;flex-direction:column}.brand{color:#fff;text-decoration:none;font:800 24px Manrope,sans-serif;display:flex;align-items:center;gap:9px}.logo{display:grid;place-items:center;background:#cfe5bc;color:#172d2a;border-radius:11px;width:35px;height:35px;font-size:25px}.ti{font-size:12px;color:#a9c5ae;align-self:flex-end;margin-bottom:5px}.workspace{font-size:10px;letter-spacing:1.8px;margin:42px 12px 25px;color:#99aea3}.workspace span{display:block;letter-spacing:0;font-size:13px;margin-top:9px;color:#d3dfd9}nav{display:grid;gap:8px}nav button{border:0;text-align:left;color:#aabeb3;background:transparent;padding:14px;border-radius:9px;font-size:18px}nav button span{font-size:14px;margin-left:12px}nav button.active{background:#2d4740;color:#e9f3e5}.aside-bottom{margin-top:auto;font-size:12px;line-height:2}.aside-bottom small{color:#819b8d}.dot{display:inline-block;background:#acd18f;width:7px;height:7px;border-radius:50%;margin-right:5px}main{margin-left:235px}header{height:82px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 40px;background:#fbfcfa}.breadcrumb{font-size:12px;color:var(--muted)}.breadcrumb span{padding:0 13px;color:#b5bfb6}.breadcrumb b{font-weight:500;color:var(--ink)}.header-right{display:flex;gap:20px;align-items:center}.avatar{display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:#e5eadc;color:#53694e;font-size:11px;font-weight:700}.subtle{border:0;background:transparent;color:#526b60;padding:8px}.connection{padding:10px 40px;font-size:12px;background:#e9efe4;color:#4f6c50}.connection.warn{background:#fff0d7;color:#8c6319}.page{padding:34px 40px 20px;max-width:1500px;margin:auto}.page-heading{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;gap:20px}.eyebrow{font-size:10px;font-weight:700;letter-spacing:2px;color:#7b8b76}h1{font:800 34px Manrope,sans-serif;letter-spacing:-1.3px;margin:10px 0}p{color:var(--muted);line-height:1.6;margin:0}button.primary{background:var(--green);color:white;border:0;border-radius:8px;padding:13px 19px;font-weight:600;white-space:nowrap}.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}.metric{padding:22px 24px;background:#fff;border:1px solid var(--line);border-radius:12px}.metric strong{font:700 32px Manrope,sans-serif;display:block;margin:12px 0 5px}.metric small{font-size:11px;color:var(--muted)}.metric-label{color:#677b6c;font-size:12px}.metric.accent{background:#e7eedb;border-color:#dce7ca}.panel{background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-bottom:22px}.panel-heading{padding:22px 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;border-bottom:1px solid var(--line)}h2{font:700 17px Manrope,sans-serif;margin:0}.panel-heading small{color:var(--muted);display:block;margin-top:6px;font-size:12px}.text-button{border:0;background:none;color:var(--green);font-size:12px;padding:6px}.toolbar{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}.toolbar input{min-width:250px;flex:1}.toolbar input,.toolbar select,form input,form select,form textarea{border:1px solid #dce3d9;background:white;border-radius:7px;padding:11px 12px;color:var(--ink)}table{border-collapse:collapse;width:100%;text-align:left;font-size:12px}thead{background:#f9faf7;color:#7a887d;font-size:10px;text-transform:uppercase;letter-spacing:1px}th{padding:14px 22px;font-weight:600}td{padding:19px 22px;border-top:1px solid #eff2eb}tbody tr:hover{background:#fafbf7}td.subject{font-weight:600;font-size:13px}td small{display:block;font-weight:400;margin-top:6px;font-size:10px;color:var(--muted)}.badge{padding:5px 9px;border-radius:5px;display:inline-block;font-size:10px;white-space:nowrap}.alta{background:#fde9e2;color:#ad513c}.media{background:#fff3d7;color:#9a7a29}.baja{background:#eaf0e4;color:#657c50}.abierto{background:#eef0f5;color:#687187}.en-progreso{background:#e5edf9;color:#5075a8}.resuelto{background:#e1f0e7;color:#41835d}.danger{color:#b25242}.grid-bottom{display:grid;grid-template-columns:1.25fr 1fr;gap:22px}.callout{background:#dfe8cf;border-radius:12px;padding:30px;position:relative;overflow:hidden}.callout h2{font-size:23px;max-width:320px;line-height:1.4}.callout p{color:#657752;margin:13px 0 24px;max-width:300px}.callout:after{content:'O';position:absolute;right:-15px;bottom:-50px;font:800 190px Manrope,sans-serif;color:#cfddba;pointer-events:none}.callout button{position:relative;z-index:1}.health-row{display:flex;justify-content:space-between;padding:17px 24px;border-top:1px solid var(--line);font-size:12px}.empty{padding:50px 24px;text-align:center;color:var(--muted)}footer{display:flex;justify-content:space-between;font-size:10px;color:#879584;margin:32px 0 10px}dialog{border:0;border-radius:15px;width:min(620px,94vw);padding:26px;box-shadow:0 24px 90px #10251c40;color:var(--ink);max-height:90vh}dialog::backdrop{background:#132c2366;backdrop-filter:blur(3px)}.modal-heading{display:flex;justify-content:space-between;align-items:center;margin-bottom:22px}.icon{border:0;background:#f0f3ec;width:30px;height:30px;border-radius:50%;font-size:22px;color:#61735f}form{display:grid;gap:16px}form label{display:grid;gap:7px;font-size:12px;font-weight:600}form textarea{min-height:95px;resize:vertical}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:15px}.form-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:5px}.secondary{background:#eef2e9;color:#4c654e;border:0;border-radius:8px;padding:12px 16px}.delete{background:#fcece7;color:#a6503e;border:0;border-radius:8px;padding:10px}.detail{background:#f5f7f0;border-radius:8px;padding:15px;margin-bottom:20px;line-height:1.6;white-space:pre-wrap}.timeline{border-left:2px solid #dce6d2;margin:15px 0 25px;padding-left:17px}.timeline div{margin-bottom:15px;font-size:12px;line-height:1.5}.timeline small{display:block;color:var(--muted);font-size:10px}#toast{position:fixed;bottom:24px;right:24px;padding:15px 22px;border-radius:9px;background:#203e31;color:white;max-width:420px;display:none;z-index:5}.table-scroll{overflow:auto}.report-summary{padding:25px;line-height:1.8}.bar-row{display:grid;grid-template-columns:75px 1fr 35px;gap:15px;align-items:center;margin:18px 0}.bar{height:9px;background:#edf1e7;border-radius:5px;overflow:hidden}.bar i{display:block;background:#7eaa79;height:100%}.loading{padding:45px;text-align:center;color:var(--muted)}@media(max-width:1000px){aside{width:190px;padding:28px 17px}main{margin-left:190px}.page{padding:28px 24px}header{padding:0 24px}.metrics{grid-template-columns:repeat(2,1fr)}.grid-bottom{grid-template-columns:1fr}}@media(max-width:650px){aside{position:static;width:100%;padding:18px}.brand{font-size:21px}.workspace,.aside-bottom{display:none}nav{display:flex;gap:2px;margin-top:15px;overflow:auto}nav button{padding:9px;font-size:0}nav button span{margin:0;font-size:11px;white-space:nowrap}main{margin:0}header{height:60px;padding:0 17px}.breadcrumb{font-size:10px}.header-right{gap:6px}.page{padding:25px 17px}.page-heading{align-items:flex-start;flex-direction:column}h1{font-size:28px}.connection{padding:10px 17px}.metric{padding:17px}.metric strong{font-size:28px}.form-row{grid-template-columns:1fr}th,td{padding:14px}footer span{display:none}}
</style></head>
<body><aside><a class="brand" href="#dashboard"><span class="logo">O</span>OpenDesk<span class="ti">AC</span></a><div class="workspace">GRUPO 3 <span>Atención al cliente</span></div><nav><button data-view="dashboard">◫ <span>Vista general</span></button><button data-view="tickets">▤ <span>Tickets</span></button><button data-view="clients">◎ <span>Clientes</span></button><button data-view="assets">▣ <span>Productos / servicios</span></button><button data-view="queue">◷ <span>Cola SLA</span></button><button data-view="reports">▥ <span>Reportes</span></button><button data-view="users">♙ <span>Usuarios</span></button><button data-view="settings">⚙ <span>Configuración</span></button><button data-view="audit">≡ <span>Auditoría</span></button></nav><div class="aside-bottom"><span class="dot"></span> Escuchar. Atender. Resolver.<br><small>Open source · Grupo 3</small></div></aside>
<main><header><div class="breadcrumb">Espacio de trabajo <span>/</span> <b id="crumb">Vista general</b></div><div class="header-right"><button id="sync" class="subtle">↻ Sincronizar <span id="pending"></span></button><button id="account" class="subtle">Mi cuenta</button><button id="logout" class="subtle">Salir</button><span class="avatar">AC</span></div></header><div id="connection" class="connection" role="status">Comprobando conexión…</div><section class="page"><div class="page-heading"><div><div class="eyebrow">EXPERIENCIA DEL CLIENTE</div><h1 id="title">Todo bajo control.</h1><p id="subtitle">Cada cliente, cada solicitud y cada solución en un solo lugar.</p></div><button id="create" class="primary">＋ Nuevo ticket</button></div><div id="content"></div><footer>OpenDesk Atención <span>Tickets claros. Productos / servicios con historia.</span></footer></section></main>
<dialog id="modal"><div class="modal-heading"><h2 id="modal-title"></h2><button id="close-modal" class="icon" aria-label="Cerrar">×</button></div><div id="modal-content"></div></dialog><div id="toast" role="status"></div><script nonce="__SCRIPT_NONCE__">window.OPEN_DESK=__META__;</script><script nonce="__SCRIPT_NONCE__">const $=s=>document.querySelector(s), esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const uuid=()=>typeof crypto.randomUUID==='function'?crypto.randomUUID():Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
const meta=window.OPEN_DESK||{profile:'local',storageKey:'local'}; let csrf='';let currentUser=null,users=[],agents=[],slaPolicy={Alta:4,Media:24,Baja:72},auditRows=[];
const titles={users:['Personas y permisos.','Crea cuentas y administra el acceso al sistema.','Usuarios'],settings:['Tiempos de atención.','Define el SLA para las solicitudes nuevas.','Configuración'],audit:['Cambios con trazabilidad.','Consulta las últimas 200 acciones registradas.','Auditoría'],clients:['Clientes, siempre cerca.','Consulta sus contactos y el historial de atención.','Clientes'],dashboard:['Todo bajo control.','Cada solicitud, cada producto o servicio y cada solución en un solo lugar.','Vista general'],tickets:['Solicitudes con rumbo.','Acompaña cada consulta o reclamo hasta su solución.','Tickets'],assets:['Productos / servicios con historia.','Relaciona cada consulta o reclamo con lo que ofreces.','Productos / servicios'],queue:['Lo urgente, primero.','Prioridad alta primero; dentro de cada prioridad, el SLA más cercano.','Cola SLA'],reports:['La atención en números.','Conoce los tiempos de respuesta y el cumplimiento de los acuerdos.','Reportes']};
let view='dashboard', tickets=[], assets=[], clients=[], localPending=[], health={mongo:false,redis:false}, queueSource='', connected=false, localDb;
let ready;
function openStore(identity){return new Promise((resolve,reject)=>{const r=indexedDB.open('opendesk-roles-'+meta.storageKey+'-'+identity,1);r.onupgradeneeded=()=>{r.result.createObjectStore('pending',{keyPath:'id'});r.result.createObjectStore('cache')};r.onsuccess=()=>{localDb=r.result;resolve()};r.onerror=()=>reject(r.error)})}
ready=openStore('anonymous');
async function setUser(user){if(currentUser?.id!==user.id||currentUser?.cacheVersion!==user.cacheVersion){tickets=[];assets=[];clients=[];users=[];agents=[];auditRows=[];localPending=[];connected=false;await ready;if(localDb)localDb.close();ready=openStore(user.id+'-v'+(user.cacheVersion||1));await ready}currentUser=user;localStorage.setItem('opendesk-last-'+meta.storageKey,JSON.stringify(user));if(!allowedViews().includes(view))view='dashboard';applyRole()}
const admin=()=>currentUser?.role==='administrador';
function allowedViews(){return !currentUser?['dashboard']:admin()?Object.keys(titles):currentUser.role==='agente'?['dashboard','tickets','clients','assets','queue']:['dashboard','tickets','assets','queue']}
function applyRole(){document.querySelectorAll('nav button').forEach(b=>b.hidden=!allowedViews().includes(b.dataset.view));$('#account').textContent=currentUser?currentUser.name+' · '+currentUser.role:'Mi cuenta';}
async function local(store,mode,op){await ready;return new Promise((resolve,reject)=>{const tx=localDb.transaction(store,mode),r=op(tx.objectStore(store));let result;r.onsuccess=()=>result=r.result;tx.oncomplete=()=>resolve(result);tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error)});}
const pending=async()=>{localPending=await local('pending','readonly',s=>s.getAll());return localPending};
async function api(path,options={}){const r=await fetch('index.php?api='+encodeURIComponent(path),{...options,headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},signal:AbortSignal.timeout(10000)});let b;try{b=await r.json()}catch{throw Error('La API no está disponible. Inicie el servidor PHP.')};if(!r.ok){const e=Error(b.error||'No se pudo completar.');e.status=r.status;if(r.status===401 && path!=='login'){authenticated=false;currentUser=null;tickets=[];assets=[];clients=[];users=[];agents=[];auditRows=[];localPending=[];view='dashboard';applyRole();render();loginForm();}throw e}return b}
function toast(message){$('#toast').textContent=message;$('#toast').style.display='block';clearTimeout(toast.timer);toast.timer=setTimeout(()=>$('#toast').style.display='none',5500)}
const date=t=>new Date(t*1000).toLocaleString('es-CR',{timeZone:'America/Costa_Rica',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
const badge=s=>`<span class="badge ${esc(s.toLowerCase().replaceAll(' ','-'))}">${esc(s)}</span>`;
function sla(t){if(t.status==='Resuelto')return t.resolvedAt<=t.dueAt?'Cumplido':'Fuera de SLA';let m=Math.ceil((t.dueAt-Date.now()/1000)/60),a=Math.abs(m);return m<0?`Vencido · ${Math.floor(a/60)} h ${a%60} min`:a>=1440?`${Math.floor(a/1440)} d ${Math.floor(a%1440/60)} h`:`${Math.floor(a/60)} h ${a%60} min`}
function reports(){const resolved=tickets.filter(t=>t.status==='Resuelto'),responses=tickets.filter(t=>t.firstResponseAt);return {open:tickets.length-resolved.length,resolved:resolved.length,overdue:tickets.filter(t=>t.status!=='Resuelto'&&t.dueAt<Date.now()/1000).length,soon:tickets.filter(t=>t.status!=='Resuelto'&&t.dueAt>=Date.now()/1000&&t.dueAt<Date.now()/1000+3600).length,compliance:resolved.length?Math.round(resolved.filter(t=>t.resolvedAt<=t.dueAt).length/resolved.length*100):null,response:responses.length?Math.round(responses.reduce((n,t)=>n+(t.firstResponseAt-t.createdAt)/60,0)/responses.length):null}}
function metrics(){const r=reports();return `<div class="metrics"><div class="metric"><span class="metric-label">Tickets abiertos</span><strong>${r.open}</strong><small>Pendientes de solución</small></div><div class="metric"><span class="metric-label">SLA por vencer</span><strong>${r.soon}</strong><small>En los próximos 60 minutos · ${r.overdue} vencidos</small></div><div class="metric accent"><span class="metric-label">Cumplimiento de SLA</span><strong>${r.compliance===null?'—':r.compliance+'%'}</strong><small>Sobre ${r.resolved} tickets resueltos</small></div><div class="metric"><span class="metric-label">Clientes registrados</span><strong>${clients.length}</strong><small>${assets.length} productos / servicios</small></div></div>`}
function ticketTable(list){return list.length?`<div class="table-scroll"><table><thead><tr><th>Ticket / asunto</th><th>Producto / servicio</th><th>Prioridad</th><th>Estado</th><th>SLA restante</th><th></th></tr></thead><tbody>${list.map(t=>`<tr><td class="subject">${esc(t.subject)}<small>#${esc(t.id.slice(0,8))} · ${esc(t.requester)}</small></td><td>${esc(t.productId||'Sin producto o servicio')}</td><td>${badge(t.priority)}</td><td>${badge(t.status)}</td><td class="${t.status!=='Resuelto'&&t.dueAt<Date.now()/1000?'danger':''}">${sla(t)}</td><td><button class="text-button" data-ticket="${esc(t.id)}">Abrir ↗</button></td></tr>`).join('')}</tbody></table></div>`:'<div class="empty">No hay tickets aquí. Crea una solicitud para comenzar.</div>'}
function filters(){return `<div class="toolbar"><input id="search" aria-label="Buscar" placeholder="Buscar asunto, cliente o producto o servicio…"><select id="filter-status" aria-label="Filtrar estado"><option value="">Todos los estados</option>${['Nuevo','En atención','Esperando cliente','Resuelto'].map(s=>`<option>${s}</option>`).join('')}</select><select id="filter-priority" aria-label="Filtrar prioridad"><option value="">Todas las prioridades</option><option>Alta</option><option>Media</option><option>Baja</option></select></div>`}
function render(){document.querySelectorAll('nav button').forEach(b=>b.classList.toggle('active',b.dataset.view===view));const [title,sub,crumb]=titles[view];$('#title').textContent=title;$('#subtitle').textContent=sub;$('#crumb').textContent=crumb;$('#create').hidden=!currentUser||(['clients','assets'].includes(view)&&!admin())||['settings','audit'].includes(view);$('#create').textContent=view==='users'?'＋ Nuevo usuario':view==='clients'?'＋ Nuevo cliente':view==='assets'?'＋ Nuevo producto o servicio':'＋ Nuevo ticket';let html='';
 if(view==='dashboard')html=metrics()+`<div class="panel"><div class="panel-heading"><div><h2>Solicitudes recientes</h2><small>Una mirada al trabajo de tu equipo de atención</small></div><button class="text-button" data-nav="tickets">Ver todos →</button></div>${ticketTable([...tickets].sort((a,b)=>b.createdAt-a.createdAt).slice(0,5))}</div><div class="grid-bottom"><div class="callout"><div class="eyebrow">CATÁLOGO DE ATENCIÓN</div><h2>Cada cliente tiene una historia.</h2><p>Revisa sus solicitudes y ofrece una atención con contexto.</p><button class="secondary" data-nav="clients">Explorar clientes ↗</button></div><div class="panel"><div class="panel-heading"><h2>Estado del sistema</h2></div><div class="health-row"><span>Almacenamiento MongoDB</span><b>${health.mongo?'Conectado':'Sin conexión'}</b></div><div class="health-row"><span>Caché Redis</span><b>${health.redis?'Disponible':'Contingencia MongoDB'}</b></div><div class="health-row"><span>Registro sin conexión</span><b>Cola local del navegador</b></div></div></div>`;
 if(view==='tickets')html=filters()+`<div class="panel" id="ticket-list">${ticketTable(tickets)}</div>`;
 if(view==='queue'){const list=tickets.filter(t=>t.status!=='Resuelto').sort((a,b)=>['Alta','Media','Baja'].indexOf(a.priority)-['Alta','Media','Baja'].indexOf(b.priority)||a.dueAt-b.dueAt);html=metrics()+`<div class="panel"><div class="panel-heading"><div><h2>Cola de atención</h2><small>SLA: Alta ${slaPolicy.Alta} h · Media ${slaPolicy.Media} h · Baja ${slaPolicy.Baja} h</small></div><span>${esc(queueSource||'Vista local')}</span></div>${ticketTable(list)}</div>`}
 if(view==='assets')html=`<div class="panel"><div class="panel-heading"><div><h2>Catálogo de productos y servicios</h2><small>Abre un producto o servicio para ver sus solicitudes e historial.</small></div><span>${assets.length} productos / servicios</span></div>${assets.length?`<div class="table-scroll"><table><thead><tr><th>Producto / servicio</th><th>Tipo / categoría</th><th>Referencia</th><th>Estado</th><th></th></tr></thead><tbody>${assets.map(a=>`<tr><td class="subject">${esc(a.name)}<small>${esc(a.id)}</small></td><td>${esc(a.type)}<small>${esc(a.category)}</small></td><td>${esc(a.reference)}</td><td>${esc(a.status)}</td><td><button class="text-button" data-asset="${esc(a.id)}">Ver producto o servicio ↗</button></td></tr>`).join('')}</tbody></table></div>`:'<div class="empty">Agrega tu primer producto o servicio al inventario.</div>'}</div>`;
 if(view==='clients')html=clientsPanel();
 if(view==='users')html=usersPanel();if(view==='settings')html=settingsPanel();if(view==='audit')html=auditPanel();
 if(view==='reports'){const r=reports();html=metrics()+`<div class="grid-bottom"><div class="panel"><div class="panel-heading"><h2>Distribución por prioridad</h2></div><div class="report-summary">${['Alta','Media','Baja'].map(p=>{const n=tickets.filter(t=>t.priority===p).length;return `<div class="bar-row"><span>${p}</span><div class="bar"><i style="width:${tickets.length?n/tickets.length*100:0}%"></i></div><b>${n}</b></div>`}).join('')}</div></div><div class="panel"><div class="panel-heading"><h2>Indicadores de atención</h2></div><div class="health-row"><span>Primera respuesta promedio</span><b>${r.response===null?'Sin registros':r.response+' min'}</b></div><div class="health-row"><span>Tickets fuera de plazo</span><b>${r.overdue}</b></div><div class="health-row"><span>Tickets resueltos</span><b>${r.resolved}</b></div><div class="health-row"><span>Total de solicitudes</span><b>${tickets.length}</b></div></div></div><div class="panel"><div class="report-summary"><h2>Exportar reporte</h2><p>Descarga el detalle de tickets para documentar la atención. El SLA se mide desde el registro en el servidor hasta la resolución. La primera respuesta se registra al asignar o comentar el ticket.</p><br><button class="secondary" id="export">↓ Descargar CSV</button></div></div>`}
 if(localPending.length && (view==='dashboard'||view==='tickets'))html=`<div class="panel"><div class="panel-heading"><div><h2>Pendientes de sincronizar (${localPending.length})</h2><small>Guardados en este navegador. Aún no se registran en MongoDB.</small></div></div><div class="report-summary">${localPending.map(t=>`<div style="margin-bottom:15px"><p><b>${esc(t.subject)}</b> · ${esc(t.requester)} · ${esc(t.priority)}</p>${t.syncError?`<p class="danger">${esc(t.syncError)}</p>`:''}<button class="text-button" data-pending="${esc(t.id)}">Editar pendiente</button><button class="text-button danger" data-discard="${esc(t.id)}">Descartar</button></div>`).join('')}</div></div>`+html;
 $('#content').innerHTML=html;bind();}
async function navigate(v){if(!allowedViews().includes(v))v='dashboard';view=v;try{if(admin()&&v==='users')users=await api('users');if(admin()&&v==='audit')auditRows=await api('audit');}catch(e){toast(e.message);return}location.hash=v;render();if(v==='queue'&&connected)api('queue').then(q=>{queueSource=q.source;render()}).catch(e=>toast(e.message))}
function bind(){document.querySelectorAll('[data-user]').forEach(b=>b.onclick=()=>userForm(users.find(u=>u.id===b.dataset.user)));if($('#sla-form'))$('#sla-form').onsubmit=async e=>{e.preventDefault();try{const hours=Object.fromEntries([...new FormData(e.target)].map(([k,v])=>[k,Number(v)]));await api('settings',{method:'POST',body:JSON.stringify({hours})});slaPolicy=hours;toast('SLA actualizado para nuevas solicitudes.')}catch(e){toast(e.message)}};document.querySelectorAll('[data-pending]').forEach(b=>b.onclick=()=>ticketForm(localPending.find(t=>t.id===b.dataset.pending)));document.querySelectorAll('[data-discard]').forEach(b=>b.onclick=async()=>{if(confirm('¿Descartar este ticket que aún no se ha enviado?')){await local('pending','readwrite',s=>s.delete(b.dataset.discard));await refresh();}});document.querySelectorAll('[data-client]').forEach(b=>b.onclick=()=>clientDetail(b.dataset.client));document.querySelectorAll('[data-ticket]').forEach(b=>b.onclick=()=>ticketDetail(b.dataset.ticket));document.querySelectorAll('[data-asset]').forEach(b=>b.onclick=()=>assetDetail(b.dataset.asset));document.querySelectorAll('[data-nav]').forEach(b=>b.onclick=()=>navigate(b.dataset.nav));if($('#search')){const filter=()=>{const q=$('#search').value.toLowerCase(),s=$('#filter-status').value,p=$('#filter-priority').value;$('#ticket-list').innerHTML=ticketTable(tickets.filter(t=>(!s||t.status===s)&&(!p||t.priority===p)&&[t.subject,t.requester,t.productId].join(' ').toLowerCase().includes(q)));document.querySelectorAll('[data-ticket]').forEach(b=>b.onclick=()=>ticketDetail(b.dataset.ticket))};['#search','#filter-status','#filter-priority'].forEach(s=>$(s).oninput=filter)}if($('#export'))$('#export').onclick=exportCsv}
function modal(title,html){if($('#modal').open)$('#modal').close();$('#modal-title').textContent=title;$('#modal-content').innerHTML=html;$('#modal').showModal()}
function options(items,selected=''){return items.map(x=>`<option ${x===selected?'selected':''}>${esc(x)}</option>`).join('')}
function ticketForm(existing){if(!currentUser){loginForm();return}if(!clients.length){toast('Registra primero un cliente con conexión.');navigate('clients');return}modal('Nueva solicitud',`<form id="ticket-form"><label>Asunto<input name="subject" required maxlength="160" placeholder="¿En qué podemos ayudarte?"></label><label>Descripción<textarea name="description" required maxlength="4000" placeholder="Describe el problema y qué has intentado."></textarea></label><div class="form-row"><label>Cliente<select name="clientId" required><option value="">Selecciona un cliente</option>${clients.filter(c=>admin()||c.id===currentUser.clientId).map(c=>`<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('')}</select></label><label>Prioridad<select name="priority">${options(['Media','Alta','Baja'])}</select></label></div><div class="form-row"><label>Categoría<select name="category">${options(['Consulta','Reclamo','Devolución','Garantía','Problema con pedido'])}</select></label><label>Pedido / referencia<input name="orderRef" maxlength="100" placeholder="Opcional"></label></div><label>Producto / servicio relacionado<select name="productId"><option value="">Sin producto o servicio asociado</option>${assets.map(a=>`<option value="${esc(a.id)}">${esc(a.id+' · '+a.name)}</option>`).join('')}</select></label><p>Si no hay conexión, la solicitud queda pendiente en este navegador. El SLA comienza cuando MongoDB confirma el registro.</p><div class="form-actions"><button class="primary">Registrar ticket</button></div></form>`);if(existing)for(const [key,value] of Object.entries(existing)){const field=$('#ticket-form').elements.namedItem(key);if(field)field.value=value;}$('#ticket-form').onsubmit=async e=>{e.preventDefault();const b={...Object.fromEntries(new FormData(e.target)),id:existing?.id||uuid()};b.requester=clients.find(c=>c.id===b.clientId)?.name||'';const button=e.target.querySelector('button');button.disabled=true;try{await local('pending','readwrite',s=>s.put(b));$('#modal').close();toast('Solicitud guardada. Sincronizando…');await synchronize()}catch(err){toast('No se pudo guardar: '+err.message)}finally{button.disabled=false}}}
function ticketDetail(id){const t=tickets.find(t=>t.id===id);const client=currentUser.role==='cliente';modal('Detalle de solicitud',`<h2>${esc(t.subject)}</h2><p>${esc(t.requester)} · ${esc(t.category)} · ${badge(t.priority)}</p><br><div class="detail">${esc(t.description)}</div><p>Pedido: ${esc(t.orderRef||'—')} · SLA: ${sla(t)}</p><div class="timeline">${t.history.map(h=>`<div>${esc(h.text)}<small>${date(h.date)}</small></div>`).join('')}</div>${client?`<form id="comment-form"><label>Comentario<textarea name="note" required maxlength="2000" placeholder="Añade información a tu solicitud."></textarea></label><button class="primary">Enviar comentario</button></form>`:t.status==='Resuelto'&&!admin()?'<p>Solicitud resuelta.</p>':`<form id="update-ticket"><div class="form-row"><label>Responsable<select name="assigneeId"><option value="">Sin asignar</option>${agents.map(a=>`<option value="${esc(a.id)}" ${a.id===(t.assigneeId||(admin()?'':currentUser.id))?'selected':''}>${esc(a.name)}</option>`).join('')}</select></label><label>Estado<select name="status">${options(['Nuevo','En atención','Esperando cliente','Resuelto'],t.status)}</select></label></div><label>Nota o solución<textarea name="note" maxlength="2000"></textarea></label><div class="form-actions">${admin()?'<button type="button" class="delete" id="delete-ticket">Eliminar</button>':''}<button class="primary">Guardar cambios</button></div></form>`}`);for(const sel of ['#comment-form','#update-ticket'])if($(sel))$(sel).onsubmit=e=>{e.preventDefault();mutate('tickets/'+encodeURIComponent(id),'PATCH',Object.fromEntries(new FormData(e.target)))};if($('#delete-ticket'))$('#delete-ticket').onclick=()=>{if(confirm('¿Eliminar el ticket y su historial?'))mutate('tickets/'+encodeURIComponent(id),'DELETE')}}
function assetForm(a){if(!admin())return;modal(a?'Editar producto o servicio':'Nuevo producto o servicio',`<form id="asset-form"><div class="form-row"><label>Código<input name="id" required maxlength="64" ${a?'readonly':''} value="${esc(a?.id||'')}" placeholder="PROD-001"></label><label>Nombre<input name="name" required maxlength="100" value="${esc(a?.name||'')}" placeholder="Nombre del producto o servicio"></label></div><div class="form-row"><label>Tipo<select name="type">${options(['Producto','Servicio'],a?.type)}</select></label><label>Categoría<input name="category" required maxlength="100" value="${esc(a?.category||'')}" placeholder="Hogar, ropa, soporte…"></label></div><label>Referencia / descripción breve<input name="reference" required maxlength="100" value="${esc(a?.reference||'')}" placeholder="Modelo, SKU o detalle del servicio"></label><label>Estado<select name="status">${options(['Disponible','Suspendido','Retirado'],a?.status)}</select></label><div class="form-actions"><button class="primary">Guardar</button></div></form>`);$('#asset-form').onsubmit=e=>{e.preventDefault();const b=Object.fromEntries(new FormData(e.target));mutate('products'+(a?'/'+encodeURIComponent(a.id):''),a?'PATCH':'POST',b)}}
function assetDetail(id){const a=assets.find(a=>a.id===id),list=tickets.filter(t=>t.productId===id);modal('Historial del producto o servicio',`<h2>${esc(a.name)}</h2><p>${esc(a.id)} · ${esc(a.type)} · ${esc(a.status)}</p><br><div class="detail">Categoría: ${esc(a.category)}<br>Referencia: ${esc(a.reference)}</div><h2>Solicitudes vinculadas (${list.length})</h2><div class="timeline">${list.map(t=>`<div><b>${esc(t.subject)}</b> · ${esc(t.status)}${t.history.map(h=>`<small>${date(h.date)} · ${esc(h.text)}</small>`).join('')}</div>`).join('')||'<p>No hay solicitudes vinculadas.</p>'}</div><div class="form-actions"><button class="delete" id="delete-asset">Eliminar</button><button class="primary" id="edit-asset">Editar</button></div>`);if(!admin()){$('#edit-asset').hidden=true;$('#delete-asset').hidden=true}$('#edit-asset').onclick=()=>{$('#modal').close();assetForm(a)};$('#delete-asset').onclick=()=>{if(confirm('¿Eliminar el producto o servicio? Solo es posible si no tiene tickets vinculados.'))mutate('products/'+encodeURIComponent(id),'DELETE')}}
async function mutate(path,method,b){if(!connected){toast('Esta operación necesita conexión. Puedes crear tickets sin conexión.');return}try{await api(path,{method,body:b?JSON.stringify(b):undefined});$('#modal').close();toast('Cambios guardados.');await refresh()}catch(e){toast(e.message)}}
async function refresh(){try{health=await api('health');if(!health.mongo)throw Error('MongoDB sin conexión');[tickets,assets,clients,agents]=await Promise.all([api('tickets'),api('products'),api('clients'),api('agents')]);slaPolicy=(await api('settings')).hours;connected=true;await local('cache','readwrite',s=>s.put({tickets,assets,clients},'snapshot'));}catch(e){connected=false;health.mongo=false;if(e.status===401){await local('cache','readwrite',s=>s.clear());localStorage.removeItem('opendesk-last-'+meta.storageKey);tickets=[];assets=[];clients=[];currentUser=null;users=[];agents=[];auditRows=[];localPending=[];view='dashboard';applyRole();render();return}const c=await local('cache','readonly',s=>s.get('snapshot'));tickets=c?.tickets||[];assets=c?.assets||[];clients=c?.clients||[]}const p=await pending();$('#pending').textContent=p.length?`(${p.length})`:'';$('#connection').textContent=connected?`${meta.profile==='atlas'?'Atlas':'Webmin · grupo3_db'} conectado · ${health.redis?'Redis disponible':'Redis sin conexión: consultas directas a MongoDB'} · ${p.length} pendientes de sincronizar`:`Sin conexión con MongoDB · Vista guardada en este navegador · ${p.length} tickets pendientes`;$('#connection').classList.toggle('warn',!connected||!health.redis);render()}
let syncing=false;async function synchronize(){if(syncing)return;syncing=true;try{for(const p of await pending()){if(p.syncError)continue;try{await api('tickets',{method:'POST',body:JSON.stringify(p)});await local('pending','readwrite',s=>s.delete(p.id))}catch(e){if(e.status===422){p.syncError=e.message;await local('pending','readwrite',s=>s.put(p));toast(`Revisa el pendiente: ${p.subject}`);continue}break}}await refresh()}finally{syncing=false}}
function exportCsv(){const cell=x=>'"'+String(x??'').replace(/^[\s]*[=+@\-]/,"'$&").replaceAll('"','""')+'"';const rows=[['ID','Asunto','Cliente','Producto / servicio','Prioridad','Estado','Responsable','Creación','Límite SLA','Resolución'],...tickets.map(t=>[t.id,t.subject,t.requester,t.productId,t.priority,t.status,t.assignee,date(t.createdAt),date(t.dueAt),t.resolvedAt?date(t.resolvedAt):''])];const u=URL.createObjectURL(new Blob(['\ufeff'+rows.map(r=>r.map(cell).join(';')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=u;a.download='opendesk-tickets.csv';a.click();URL.revokeObjectURL(u)}
document.querySelectorAll('nav button').forEach(b=>b.onclick=()=>navigate(b.dataset.view));$('#create').onclick=()=>view==='users'?userForm():view==='clients'?clientForm():view==='assets'?assetForm():ticketForm();$('#close-modal').onclick=()=>{if(authenticated&&!currentUser?.mustChangePassword)$('#modal').close()};$('#sync').onclick=synchronize;addEventListener('online',()=>authenticated?synchronize():startSession());addEventListener('hashchange',()=>{const v=location.hash.slice(1);if(titles[v]&&v!==view)navigate(v)});view=titles[location.hash.slice(1)]?location.hash.slice(1):'dashboard';ready.then(startSession).catch(e=>{toast('El navegador no permite almacenamiento local: '+e.message);render()});setInterval(()=>{if(!$('#modal').open && authenticated)synchronize()},30000);if('serviceWorker'in navigator)navigator.serviceWorker.register('index.php?asset=sw',{scope:'./'}).catch(()=>{});

let authenticated=false;
function clientsPanel(){return `<div class="panel"><div class="panel-heading"><div><h2>Directorio de clientes</h2><small>Contacto e historial de atención</small></div><span>${clients.length} clientes</span></div>${clients.length?`<div class="table-scroll"><table><thead><tr><th>Cliente</th><th>Contacto</th><th>Empresa</th><th>Tickets</th><th></th></tr></thead><tbody>${clients.map(c=>`<tr><td class="subject">${esc(c.name)}</td><td>${esc(c.email)}<small>${esc(c.phone)}</small></td><td>${esc(c.company||'—')}</td><td>${tickets.filter(t=>t.clientId===c.id).length}</td><td><button class="text-button" data-client="${esc(c.id)}">Ver cliente ↗</button></td></tr>`).join('')}</tbody></table></div>`:'<div class="empty">Registra el primer cliente para comenzar a atender solicitudes.</div>'}</div>`}
function clientForm(c){if(!admin())return;modal(c?'Editar cliente':'Nuevo cliente',`<form id="client-form"><label>Nombre<input name="name" required maxlength="100" value="${esc(c?.name||'')}"></label><div class="form-row"><label>Correo<input name="email" type="email" maxlength="160" value="${esc(c?.email||'')}"></label><label>Teléfono<input name="phone" maxlength="40" value="${esc(c?.phone||'')}"></label></div><label>Empresa (opcional)<input name="company" maxlength="120" value="${esc(c?.company||'')}"></label><p>Indica al menos un correo o teléfono para el seguimiento.</p><div class="form-actions"><button class="primary">Guardar cliente</button></div></form>`);$('#client-form').onsubmit=e=>{e.preventDefault();const b={...Object.fromEntries(new FormData(e.target)),id:c?.id||uuid()};mutate('clients'+(c?'/'+encodeURIComponent(c.id):''),c?'PATCH':'POST',b)}}
function clientDetail(id){const c=clients.find(c=>c.id===id),list=tickets.filter(t=>t.clientId===id);modal('Historial del cliente',`<h2>${esc(c.name)}</h2><p>${esc(c.company)}</p><div class="detail">${esc(c.email)}<br>${esc(c.phone)}</div><h2>Solicitudes (${list.length})</h2><div class="timeline">${list.map(t=>`<div><b>${esc(t.subject)}</b> · ${esc(t.category)} · ${esc(t.status)}<small>${date(t.createdAt)}</small></div>`).join('')||'<p>No tiene tickets registrados.</p>'}</div><div class="form-actions"><button class="delete" id="delete-client">Eliminar</button><button class="primary" id="edit-client">Editar cliente</button></div>`);if(!admin()){$('#edit-client').hidden=true;$('#delete-client').hidden=true}$('#edit-client').onclick=()=>{$('#modal').close();clientForm(c)};$('#delete-client').onclick=()=>{if(confirm('¿Eliminar el cliente? Solo se permite si no tiene tickets.'))mutate('clients/'+encodeURIComponent(id),'DELETE')}}
async function startSession(){try{const s=await api('session');csrf=s.csrf;authenticated=s.authenticated;if(authenticated){await setUser(s.user);if(currentUser.mustChangePassword){render();passwordForm(true);return}await local('cache','readwrite',c=>c.put(true,'wasAuthenticated'));await synchronize()}else{const prior=JSON.parse(localStorage.getItem('opendesk-last-'+meta.storageKey)||'null');if(prior){await setUser(prior);await local('cache','readwrite',c=>c.clear())}localStorage.removeItem('opendesk-last-'+meta.storageKey);currentUser=null;tickets=[];assets=[];clients=[];users=[];agents=[];auditRows=[];localPending=[];view='dashboard';applyRole();$('#connection').textContent='Inicia sesión con tu cuenta.';render();loginForm()}}catch(e){const prior=JSON.parse(localStorage.getItem('opendesk-last-'+meta.storageKey)||'null');if(!e.status&&prior&&!prior.mustChangePassword){await setUser(prior);if(await local('cache','readonly',c=>c.get('wasAuthenticated'))){authenticated=true;await refresh();return}}render();loginForm();toast('No se pudo consultar el acceso. Revise MongoDB o la conexión.')}}
function loginForm(){if($('#modal').open)$('#modal').close();modal('Bienvenido a OpenDesk',`<form id="login-form"><p>Accede a tu espacio de atención.</p><label>Correo<input name="email" type="email" autocomplete="username" required></label><label>Contraseña<input type="password" name="password" autocomplete="current-password" required></label><button class="primary">Iniciar sesión</button><button type="button" class="secondary" id="register">Crear cuenta de cliente</button></form>`);$('#register').onclick=()=>{$('#modal').close();registerForm()};$('#login-form').onsubmit=async e=>{e.preventDefault();try{const b=await api('login',{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(e.target)))});csrf=b.csrf;authenticated=true;await setUser(b.user);$('#modal').close();if(currentUser.mustChangePassword){render();passwordForm(true);return}await local('cache','readwrite',c=>c.put(true,'wasAuthenticated'));await synchronize()}catch(e){toast(e.message)}}}
function registerForm(){modal('Crear cuenta de cliente',`<form id="register-form"><label>Nombre<input name="name" required maxlength="100"></label><label>Correo<input name="email" type="email" required maxlength="160"></label><label>Teléfono (opcional)<input name="phone" maxlength="40"></label><label>Contraseña<input name="password" type="password" minlength="12" required autocomplete="new-password"></label><label>Confirmar contraseña<input name="confirm" type="password" required autocomplete="new-password"></label><p>Mínimo 12 caracteres. El registro crea una cuenta de cliente.</p><button class="primary">Crear mi cuenta</button><button type="button" class="secondary" id="back-login">Volver al acceso</button></form>`);$('#back-login').onclick=()=>{$('#modal').close();loginForm()};$('#register-form').onsubmit=async e=>{e.preventDefault();const b=Object.fromEntries(new FormData(e.target));if(b.password!==b.confirm){toast('Las contraseñas no coinciden.');return}delete b.confirm;try{await api('register',{method:'POST',body:JSON.stringify(b)});$('#modal').close();toast('Cuenta creada. Inicia sesión.');loginForm()}catch(e){toast(e.message)}}}
function passwordForm(required=false){if(!currentUser)return loginForm();modal(required?'Cambia tu contraseña temporal':'Mi contraseña',`<form id="password-form"><p>${required?'Debes cambiar la contraseña antes de continuar.':'El cambio cerrará tus otras sesiones.'}</p><label>Contraseña actual<input name="currentPassword" type="password" required autocomplete="current-password"></label><label>Nueva contraseña<input name="password" type="password" minlength="12" required autocomplete="new-password"></label><label>Confirmar nueva contraseña<input name="confirm" type="password" required autocomplete="new-password"></label><button class="primary">Guardar contraseña</button></form>`);$('#password-form').onsubmit=async e=>{e.preventDefault();const b=Object.fromEntries(new FormData(e.target));if(b.password!==b.confirm){toast('Las contraseñas no coinciden.');return}delete b.confirm;try{const r=await api('password',{method:'POST',body:JSON.stringify(b)});csrf=r.csrf;await setUser(r.user);$('#modal').close();await local('cache','readwrite',c=>c.put(true,'wasAuthenticated'));toast('Contraseña actualizada.');await synchronize()}catch(e){toast(e.message)}}}
function usersPanel(){return `<div class="panel"><div class="panel-heading"><h2>Cuentas individuales</h2></div><div class="table-scroll"><table><thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th></th></tr></thead><tbody>${users.map(u=>`<tr><td>${esc(u.name)}<small>${esc(u.email)}</small></td><td>${esc(u.role)}</td><td>${u.active?'Activa':'Desactivada'}${u.mustChangePassword?' · Cambio pendiente':''}</td><td><button class="text-button" data-user="${esc(u.id)}">Administrar</button></td></tr>`).join('')}</tbody></table></div></div>`}
function userForm(u){if(!admin())return;modal(u?'Administrar cuenta':'Nuevo usuario',`<form id="user-form"><label>Nombre<input name="name" value="${esc(u?.name||'')}" required maxlength="100"></label><label>Correo<input name="email" value="${esc(u?.email||'')}" type="email" required maxlength="160"></label><label>Rol<select name="role">${options(['cliente','agente','administrador'],u?.role)}</select></label>${u?`<label>Estado<select name="active"><option value="true" ${u.active?'selected':''}>Activa</option><option value="false" ${!u.active?'selected':''}>Desactivada</option></select></label>`:''}<label>${u?'Nueva contraseña temporal (opcional)':'Contraseña temporal'}<input name="password" type="password" minlength="12" ${u?'':'required'} autocomplete="new-password"></label><p>${u?'Una modificación cerrará las sesiones de esta cuenta.':'La persona deberá cambiar su contraseña al entrar.'}</p><button class="primary">Guardar usuario</button></form>`);$('#user-form').onsubmit=async e=>{e.preventDefault();const b=Object.fromEntries(new FormData(e.target));if(u){b.active=b.active==='true';if(!b.password)delete b.password}try{await api('users'+(u?'/'+encodeURIComponent(u.id):''),{method:u?'PATCH':'POST',body:JSON.stringify(b)});$('#modal').close();toast('Cuenta guardada.');await navigate('users')}catch(e){toast(e.message)}}}
function settingsPanel(){return `<div class="panel"><div class="report-summary"><h2>SLA de resolución</h2><p>Las horas se aplican únicamente a tickets nuevos. Esperando cliente consume tiempo.</p><br><form id="sla-form">${['Alta','Media','Baja'].map(p=>`<label>${p} (horas)<input name="${p}" type="number" min="1" max="720" value="${slaPolicy[p]}" required></label>`).join('')}<button class="primary">Guardar política</button></form></div></div>`}
function auditPanel(){return `<div class="panel"><div class="table-scroll"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Registro</th></tr></thead><tbody>${auditRows.map(a=>`<tr><td>${date(a.date)}</td><td>${esc(a.actorName)}</td><td>${esc(a.action)}</td><td>${esc(a.target)}</td></tr>`).join('')}</tbody></table></div></div>`}
$('#account').onclick=()=>{if($('#modal').open)$('#modal').close();passwordForm()};
$('#logout').onclick=async()=>{if((await pending()).length){toast('Sincroniza los tickets pendientes antes de salir.');return}try{await api('logout',{method:'POST'});authenticated=false;await local('cache','readwrite',s=>s.clear());localStorage.removeItem('opendesk-last-'+meta.storageKey);currentUser=null;localPending=[];tickets=[];assets=[];clients=[];users=[];agents=[];auditRows=[];view='dashboard';applyRole();render();await startSession()}catch(e){toast(e.message)}};
$('#modal').addEventListener('cancel',e=>{if(!authenticated||currentUser?.mustChangePassword)e.preventDefault()});
</script></body></html>

HTML_APP;
echo str_replace(['__META__','__SCRIPT_NONCE__'],[$meta,$scriptNonce],$html);

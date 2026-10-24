<?php
declare(strict_types=1);

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

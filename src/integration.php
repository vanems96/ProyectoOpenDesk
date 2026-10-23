<?php
declare(strict_types=1);

function redisNamespace(string $identity):string {
    $prefix=setting('REDIS_PREFIX','grupo3:opendesk:');
    if(!preg_match('/^grupo3:[a-zA-Z0-9:_-]+:$/D',$prefix))throw new InvalidArgumentException('REDIS_PREFIX debe empezar con grupo3: y terminar con dos puntos.');
    return $prefix.substr(hash('sha256',$identity),0,16);
}

// El factory opcional permite probar errores de conexión sin servicios reales.
function openRedis(?callable $factory=null):?object {
    try {
        if(!$factory&&!extension_loaded('redis'))return null;
        $r=$factory?$factory():new Redis();
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
    $r=redisClient();if(!$r)return $result;
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

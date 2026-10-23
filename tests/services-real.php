<?php
declare(strict_types=1);
// SOLO una base de pruebas: escribir y borrar documentos sintéticos de esta ejecución.
if(getenv('OPENDESK_TEST_DATABASE')!=='yes'){fwrite(STDERR,"Requiere OPENDESK_TEST_DATABASE=yes y una base aislada.\n");exit(1);}
define('OPENDESK_LIBRARY_ONLY',true);
require __DIR__.'/../src/app.php';
$diagnostic=serviceDiagnostics();if(!$diagnostic['mongo']||!$diagnostic['redis'])throw new RuntimeException('Servicios de prueba no disponibles.');
installMongoSchemas(db());$id='synthetic-'.bin2hex(random_bytes(12));$r=redisClient();$key=connectionConfig()['namespace'].':test:'.$id;
try{
    $invalidRejected=false;
    try{db()->odac_tickets->insertOne(['id'=>$id,'priority'=>'Inventada']);}catch(MongoDB\Driver\Exception\BulkWriteException $e){$invalidRejected=$e->getCode()===121||str_contains(strtolower($e->getMessage()),'validation');}
    if(!$invalidRejected)throw new RuntimeException('JSON Schema no rechazó documento inválido.');
    $t=['id'=>$id,'subject'=>'Caso sintético','description'=>'Prueba de MongoDB y Redis','clientId'=>'synthetic-client','priority'=>'Alta','status'=>'Nuevo','createdAt'=>time(),'dueAt'=>time()+3600,'history'=>[['date'=>time(),'text'=>'Prueba']]];
    db()->odac_tickets->insertOne($t);$found=db()->odac_tickets->findOne(['id'=>$id]);if(!$found)throw new RuntimeException('Lectura MongoDB falló.');
    db()->odac_tickets->updateOne(['id'=>$id],['$set'=>['status'=>'En atención']]);
    if(db()->odac_tickets->findOne(['id'=>$id])['status']!=='En atención')throw new RuntimeException('Actualización falló.');
    writeQueueCache($r,$key,[$t]);foreach([$key=>1,$key.':order'=>4,$key.':meta'=>5] as $k=>$type)if($r->type($k)!==$type||$r->ttl($k)<=0)throw new RuntimeException('Tipo o TTL Redis incorrecto.');
    $version=(int)$r->get(connectionConfig()['namespace'].':version');invalidate();
    if((int)$r->get(connectionConfig()['namespace'].':version')<=$version)throw new RuntimeException('Invalidación no incrementó versión.');
    $oldPort=getenv('REDIS_PORT');
    try{putenv('REDIS_PORT=1');if(redisClient()!==null)throw new RuntimeException('Redis inaccesible no fue rechazado.');if(!db()->odac_tickets->findOne(['id'=>$id]))throw new RuntimeException('MongoDB no continuó disponible.');}
    finally{putenv($oldPort===false?'REDIS_PORT':'REDIS_PORT='.$oldPort);}
    $mongoFailure=false;
    try{$bad=new MongoDB\Driver\Manager('mongodb://127.0.0.1:1',['serverSelectionTimeoutMS'=>300,'connectTimeoutMS'=>300]);$bad->executeCommand('opendesk_ci',new MongoDB\Driver\Command(['ping'=>1]));}
    catch(MongoDB\Driver\Exception\Exception $e){$mongoFailure=true;}
    if(!$mongoFailure)throw new RuntimeException('No se reprodujo el fallo de conexión MongoDB.');
    $report=benchmarkServices(100,3);foreach($report['operations'] as $op)if(count($op['samplesMs'])!==3||!isset($op['medianMs']))throw new RuntimeException('Medición incompleta.');
    echo json_encode(['status'=>'passed','diagnostic'=>$diagnostic,'failureChecks'=>['redisUnavailable'=>true,'mongoStillAvailable'=>true,'mongoConnectionError'=>true,'versionInvalidation'=>true],'benchmark'=>$report],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}finally{db()->odac_tickets->deleteOne(['id'=>$id]);foreach([$key,$key.':order',$key.':meta'] as $k)$r->del($k);}

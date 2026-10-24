<?php
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

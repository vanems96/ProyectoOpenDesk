<?php
declare(strict_types=1);
// src/app.php contiene también el HTML. Sincronizar los bloques PHP editables.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$path=__DIR__.'/../src/app.php';$source=file_get_contents($path);
foreach(['runtime','domain','integration','security','routes'] as $component){
    $body=preg_replace('/^<\?php\s*(?:declare\(strict_types=1\);\s*)?/','',file_get_contents(__DIR__.'/../src/'.$component.'.php'));
    $pattern='~// BEGIN COMPONENT '.$component.'\R.*?// END COMPONENT '.$component.'~s';
    if(!preg_match($pattern,$source))throw new RuntimeException('Falta marcador de componente.');
    $source=preg_replace_callback($pattern,fn()=>'// BEGIN COMPONENT '.$component."\n".rtrim($body)."\n// END COMPONENT ".$component,$source);
}
if(in_array('--check',$argv,true)){if(file_get_contents($path)!==$source){fwrite(STDERR,"src/app.php desactualizado. Ejecute php scripts/sync-app.php.\n");exit(1);}}
else file_put_contents($path,$source);
echo "Componentes sincronizados.\n";

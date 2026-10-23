<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$argv=array_merge([__FILE__,'--schema'],array_slice($argv,1)?:[]);
require __DIR__.'/../src/app.php';

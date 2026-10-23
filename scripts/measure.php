<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$argv=array_merge([__FILE__,'--benchmark'],array_slice($argv,1)?:["1000", "5"]);
require __DIR__.'/../src/app.php';

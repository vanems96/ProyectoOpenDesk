<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$target=__DIR__.'/../dist';if(!is_dir($target)&&!mkdir($target,0700,true))exit(1);
if(!copy(__DIR__.'/../src/app.php',$target.'/index.php'))exit(1);
echo "Exportado dist/index.php sin configuración privada.\n";

<?php
// Invocar desde cron: php /ruta/al/proyecto/scripts/escalate.php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$argv[1]='--escalate';require __DIR__.'/../src/app.php';

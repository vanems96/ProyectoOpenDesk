<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
fwrite(STDERR,"Contraseña privada por entrada estándar. No guardarla en Git ni en el historial de comandos.\n");
$value=rtrim(fgets(STDIN)?:'',"\r\n");
if(preg_match_all('/./us',$value)<12||strlen($value)>72||str_contains($value,"\0")){fwrite(STDERR,"Contraseña inválida.\n");exit(1);}
echo password_hash($value,PASSWORD_DEFAULT).PHP_EOL;

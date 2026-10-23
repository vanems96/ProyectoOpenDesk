<?php
declare(strict_types=1);
// Copiar fuera del directorio público y configurar solo en el servidor.
// Nunca guardar credenciales reales en este archivo de ejemplo.
return [
    'profile'=>getenv('OPENDESK_PROFILE')?:'local',
    'local'=>['uri'=>getenv('MONGODB_URI')?:'mongodb://127.0.0.1:27017','database'=>getenv('MONGODB_DATABASE')?:'opendesk'],
    'atlas'=>[], // Configurar URI y database privados si se usa Atlas.
    'redis'=>['REDIS_PREFIX'=>getenv('REDIS_PREFIX')?:'grupo3:opendesk:','REDIS_USERNAME'=>getenv('REDIS_USERNAME')?:'','REDIS_HOST'=>getenv('REDIS_HOST')?:'127.0.0.1','REDIS_PORT'=>getenv('REDIS_PORT')?:'6379','REDIS_PASSWORD'=>getenv('REDIS_PASSWORD')?:'','REDIS_DATABASE'=>getenv('REDIS_DATABASE')?:'0'],
    'accessHash'=>getenv('APP_PASSWORD_HASH')?:'',
];

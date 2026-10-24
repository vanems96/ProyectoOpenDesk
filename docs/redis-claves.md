# Mapa de claves Redis

La aplicación conserva MongoDB como fuente de datos. Redis almacena datos temporales y el diagnóstico comprueba conexión, tipos y TTL. Si Redis no responde, la cola consulta MongoDB.

`namespace` comienza con `grupo3:opendesk:` e incorpora una huella del perfil, conexión y base. No incluye la URI ni la contraseña en texto claro. Puede añadirse un subprefijo privado por entorno.

| Clave | Tipo | TTL | Uso |
|---|---|---|---|
| `<namespace>:version` | string contador | Sin caducidad deliberada | Invalidar colas al modificar tickets |
| `<namespace>:queue-workflow-v1:<usuario>:<versionCuenta>:<versionDatos>` | string JSON | 15 segundos | Instantánea de la cola autorizada |
| La clave anterior más `:order` | sorted set | 15 segundos | Orden por prioridad y vencimiento |
| La clave anterior más `:meta` | hash | 15 segundos | `count` y `builtAt` |
| `<namespace>:auth:<hash>` | string contador | 600 segundos | Límites de intentos |
| `<namespace>:diagnostic:<id>` y variantes | string sorted set hash | Temporal | Diagnóstico que limpia únicamente sus propias claves |

La lectura de una cola cacheada vuelve a comprobar la autorización actual contra MongoDB. Una reasignación retira el acceso del agente anterior incluso si existe una instantánea antigua. Las marcas de lectura de avisos y los tickets viven en MongoDB.

No ejecutar vaciados globales del Redis compartido. Para detener el entorno Docker sin borrar la base, usar `docker compose down` sin la opción de eliminar volúmenes. La aplicación no necesita limpiar el servidor Redis para recuperarse.

# Decisiones técnicas S2 y S3

## Dos motores con responsabilidades distintas

MongoDB es la fuente principal de documentos de negocio. Redis guarda datos
temporales. Alternativas: usar tres motores o solo MongoDB. Se eligieron dos
para justificar la caché y su invalidación sin introducir SQLite artificialmente.
MongoDB local y Atlas son perfiles alternativos, no motores adicionales.

## Prefijo Redis y tres estructuras

Todas las claves nuevas empiezan por `grupo3:opendesk:`; se agrega un hash
de la identidad de conexión. `REDIS_PREFIX` permite un subprefijo, pero debe
conservar `grupo3:`. No se realizan vaciados globales del Redis compartido.

- String: JSON de tickets abiertos (TTL 15 s), versiones y contadores.
- Sorted set: orden de identificadores por prioridad y vencimiento (TTL 15 s).
- Hash: cantidad y fecha de construcción de la instantánea (TTL 15 s).
  La API consume su fecha como `cachedAt` cuando devuelve la cola desde Redis.

Alternativa: contar incrementos como otra estructura; se descarta porque
un contador Redis también es un string. Los metadatos se separan del JSON
para mostrar la procedencia temporal sin mantener una copia de negocio.
Un cambio de ticket incrementa la versión; la clave anterior expira sola.
Las claves incluyen usuario y versión de cuenta para separar sus alcances.

Cambiar el prefijo no borra claves antiguas ni cambia los documentos MongoDB.
Se mantiene la identidad anterior de IndexedDB para no ocultar tickets locales
pendientes al migrar el prefijo de Redis. El Redis compartido necesita permisos
para strings, hashes y sorted sets; un PING exitoso no acredita esos permisos.

## Conexión Redis comprobada

Se comprueban connect, autenticación (incluida ACL), select y PING. Antes se
ignoraban retornos false y podía declararse una conexión no utilizable.
Si un paso falla, el cliente queda ausente y la aplicación usa MongoDB.
El diagnóstico prueba además los tipos y TTL con claves aleatorias que elimina
al terminar. No imprime contraseñas ni URI. MongoDB sigue siendo necesario
para autorizar usuarios; Redis no permite operar si falla la base principal.

## JSON Schema en MongoDB

La validación PHP mejora los mensajes de la API, pero no protege escrituras
hechas por otras herramientas. Se agregan validadores strict/error de
usuarios, tickets, clientes y catálogo. La herramienta comprueba primero
documentos incompatibles, sin borrarlos; si los hay, pide revisión antes de
cambiar validadores. Activar el esquema es una operación explícita del operador.
Una caída a mitad del proceso puede dejar validadores aplicados parcialmente;
es repetible tras corregir el fallo. No hay una transacción global de DDL.

## Medición experimental

Se miden tres operaciones: lectura MongoDB de la cola, lectura equivalente
desde Redis y agregación MongoDB por prioridad. Cada una ejecuta al menos
tres muestras; se conservan todas y se reporta mediana. Se calienta la caché
antes de medir y se alterna el orden de las dos lecturas. El P95 anterior
de una sola comparación no cubría el requisito de tres medianas.
Redis incluye deserialización JSON y MongoDB materializa el cursor. Esto
compara lectura de datos disponibles, no toda una petición HTTP autenticada.
Se registra volumen, página, memoria, fecha y explain con executionStats.

Los datos son sintéticos, aislados por un identificador aleatorio de ejecución.
La limpieza elimina solo esa ejecución y esa clave Redis. Si el proceso se
interrumpe abruptamente pueden quedar documentos sintéticos; no se hace una
limpieza global automática. El operador puede revisar el campo run.
No se inventan números ni se extrapola CI como rendimiento del Webmin real.

## Artefacto PHP único

El despliegue mantiene un PHP exportable y configuración privada separada.
Los componentes editables se sincronizan con `scripts/sync-app.php`. CI
comprueba su coincidencia. Alternativa: mantener copias independientes;
se descarta porque sus reglas podrían divergir de las pruebas.

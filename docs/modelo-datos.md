# Modelo de datos v1

| Colección | Datos |
|---|---|
| odac_users | id único, correo único, nombre, rol, active, clientId, passwordHash, version, mustChangePassword |
| odac_clients | id, nombre, correo, teléfono, empresa |
| odac_products | id, nombre, tipo, categoría, referencia, estado |
| odac_tickets | id, clientId, productId, subject, description, category, orderRef, priority, status, assigneeId, createdBy, createdAt, dueAt, firstResponseAt, resolvedAt, history |
| odac_settings | security con adminIds; sla con hours por prioridad |
| odac_audit | id, actorId, actorName, date, action, target, details |
| odac_auth_limits | id, attempts, expiresAt para contingencia sin Redis |
| odac_benchmark_tickets | run y registros sintéticos temporales de medición |

Tickets se relacionan con contactos, catálogo y usuarios por identificadores.
history es un arreglo integrado. Fechas Unix en segundos. Usuarios y contactos
son registros diferentes; editar una cuenta no sincroniza su contacto.
Un cliente con cuentas o tickets no se borra; un producto con tickets tampoco.
Las cuentas se desactivan, no se eliminan. El administrador puede borrar tickets.

Claves Redis: `<namespace>:version`,
`<namespace>:queue:<userId>:<versionCuenta>:<versionDatos>` (JSON, TTL 15 s),
la misma clave con `:order` (conjunto ordenado, TTL 15 s), y
la misma clave con `:meta` (hash de count/builtAt, TTL 15 s), y
`<namespace>:auth:<hash>` (contador de intentos, TTL 600 s).
El namespace identifica perfil, conexión y base sin publicar su URI.
En el Redis compartido comienza obligatoriamente con `grupo3:opendesk:`
(o un subprefijo autorizado que conserve `grupo3:`). La identidad local del
navegador se mantiene independiente de ese prefijo para preservar pendientes.
IndexedDB conserva `pending` y `cache` por conexión, usuario y versión.

`php scripts/mongo-schema.php` comprueba compatibilidad e instala validadores
JSON Schema de las cuatro colecciones de negocio e índices iniciales. No
requiere modificar contactos ni tickets de producción para ejecutar pruebas.

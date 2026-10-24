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
`<namespace>:queue-workflow-v1:<userId>:<versionCuenta>:<versionDatos>` (JSON, TTL 15 s),
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


## Ampliación: seguimiento y equipos

- `odac_users`: `departmentId` (ventas/garantias/entregas/reclamos), `supervisor` (booleano). Supervisor conserva el rol agente y solo accede a tickets asignados.
- `odac_tickets`: `departmentId`, `waitingFor`, `lastActivityAt`, `closedAt`, `rating` (1..5), `ratingComment`, `slaStage`, `escalatedAt`, `escalationReason`. Campos opcionales para preservar documentos previos.
- `history[]`: incorpora `id`, `actorId`, `kind`, `visibility` y `recipients[]`. El evento y la modificación de negocio se guardan en la misma operación condicional.
- `odac_settings`: documento `escalation` con `rules.enabled` y `rules.warningMinutes`.
- `odac_notification_reads`: `id` único compuesto por usuario y evento, `readAt`. Los avisos se derivan de historial y autorización actual; Redis no es su única copia.

La interfaz usa cuatro estados comprensibles y conserva códigos históricos. Ver [reglas y compatibilidad](mejoras-tickets-equipos.md). Los tickets anteriores sin departamento se interpretan como Reclamos. IndexedDB conserva los pendientes existentes; las vistas antiguas se descartan al iniciar sesión y no se consultan sin autorización actual.

# Tickets, empleados y equipos

## Pantallas y rutas

- `/Opendesk/#tickets`: estado, empleado responsable, departamento, turno de respuesta, SLA y seguimiento del ticket.
- `/Opendesk/#users`: el administrador define área y condición de supervisor de cada empleado, crea cuentas y restablece acceso.
- `/Opendesk/#teams`: ventas, garantías, entregas y reclamos; empleados y casos abiertos por área.
- `/Opendesk/#notifications`: avisos personales con lectura persistente.
- `/Opendesk/#settings`: horas SLA para tickets nuevos y regla de escalamiento (activa/pausada, minutos de aviso).
- `/Opendesk/#reports`: primera respuesta pública, cumplimiento, satisfacción y exportación.

## Acceso y trazabilidad

Hay tres roles: cliente, agente y administrador. Supervisor es una responsabilidad del agente de un área, no un cuarto rol. Un agente solo consulta y modifica tickets cuyo `assigneeId` coincide con su cuenta. Haber creado un ticket no concede acceso. El administrador puede gestionar todos. Cada cliente accede a sus propias solicitudes. Los contactos del agente se limitan a los clientes de sus tickets.

El detalle siempre consulta al servidor. La cola Redis revalida cada resultado contra MongoDB; una caché antigua no devuelve un ticket reasignado. El navegador descarta las vistas antiguas al iniciar sesión, conservando las solicitudes pendientes de sincronizar. Sin conexión se pueden registrar solicitudes pendientes, pero no consultar copias de tickets que podrían haber cambiado de responsable.

Las notas internas se filtran en las respuestas del cliente: listado, detalle, cola y avisos. Los cambios se realizan con comparación del historial para detectar ediciones simultáneas. El historial registra actor, fecha, tipo, visibilidad y destinatarios. Reasignar requiere motivo. No se puede desactivar, cambiar de rol o mover de área una cuenta con casos abiertos sin reasignarlos primero.

## Estados y turnos

La interfaz muestra: **Pendiente de atención → En proceso → Solución propuesta → Cerrado**. El turno de respuesta (empleado/cliente) se muestra separado del estado y del responsable.

Se conservan los códigos históricos de MongoDB: `Nuevo`, `En atención`, `Esperando cliente`, `Resuelto`; `Esperando cliente` se presenta como En proceso con turno Cliente. Se incorpora `Solución propuesta`. No se borran tickets ni se convierten cierres históricos en propuestas. Los documentos anteriores sin área se interpretan como Reclamos hasta que el administrador organice sus equipos.

El agente propone una solución pública. El cliente confirma el cierre, califica de 1 a 5 y puede comentar; puede rechazar la propuesta o reabrir con motivo. El administrador puede cerrar directamente con solución y justificación, sin inventar una valoración del cliente. Reabrir elimina la valoración final anterior y los tiempos finales para medir el nuevo ciclo. Primera respuesta significa primera respuesta pública de empleado, no asignación ni nota privada.

## Asignación y escalamiento

La propuesta de asignación considera agentes activos del mismo departamento, cuenta todos sus casos sin cerrar (incluidas soluciones pendientes de confirmación) y desempata por identificador. El administrador revisa y guarda; una propuesta no asigna por sí sola.

Regla inicial: avisar y escalar 60 minutos antes del vencimiento, y marcar nuevamente al vencer. Se busca el supervisor activo del área con menor carga; una reasignación al supervisor retira el acceso al empleado anterior. Si ya lo atiende un supervisor del área, se conserva. Si no existe supervisor disponible, el aviso llega a administradores. La derivación al supervisor también genera un aviso de asignación para el cliente. Los eventos se deduplican por etapa y actualización condicional del ticket. Una solución propuesta detiene el escalamiento del SLA de resolución mientras espera confirmación.

La evaluación se ejecuta al consultar tickets, cola o notificaciones. Para ejecutarla sin visitas:

```sh
php /home/grupo3/public_html/Opendesk/index.php --escalate
# cron del usuario, cada cinco minutos:
*/5 * * * * /bin/flock -n /home/grupo3/tmp/opendesk-escalation.lock /home/grupo3/bin/php /home/grupo3/public_html/Opendesk/index.php --escalate >> /home/grupo3/tmp/opendesk-escalation.log 2>&1
```

El archivo de log queda fuera de public_html. El script `scripts/escalate.php` ofrece el mismo comando en el repositorio. Configurar cron es un paso de despliegue; que exista el script no demuestra que el trabajo esté programado.

## Datos y notificaciones

MongoDB mantiene usuarios/equipos, tickets, historial, valoraciones, reglas, auditoría y marcas de lectura (`odac_notification_reads`). Los avisos se derivan de eventos del historial guardados junto con la actualización: no dependen de Redis ni de un envío separado que pueda perderse. Solo aparecen a destinatarios con acceso actual al ticket; la reasignación también retira avisos anteriores del empleado. Redis acelera la cola, no guarda la única copia de las solicitudes.

Los avisos son **dentro de la aplicación**, con actualización periódica de 30 segundos. No se envían correos ni SMS. Las credenciales permanecen en configuración privada fuera del directorio público y fuera de Git.

## Validación

`php tests/roles.php` cubre acceso entre agentes, reasignación con Redis obsoleto, notas privadas, áreas, sugerencias por carga, primera respuesta, avisos/leídos, cierre y valoración, reapertura, reglas y deduplicación del escalamiento. GitHub Actions ejecuta además integración con MongoDB/Redis reales y recorrido Chromium de asignación, propuesta, privacidad, avisos, valoración y equipos. Las pruebas con motores reales usan una base explícitamente aislada.

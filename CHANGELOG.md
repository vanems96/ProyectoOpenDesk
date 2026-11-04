# Changelog

## Corrección documental posterior a v1.0.0 - 04/11/2026

- Video opcional e instalación externa realizada, según aclaración de Joan.
- Se conserva inmutable v1.0.0. Esta aclaración actualiza main y las notas de Release, sin atribuir datos de instalación no comunicados.


## v1.0.0 · 03/11/2026

- Cierre técnico del MVP de atención al cliente: roles, equipos, tickets, SLA, notificaciones internas, satisfacción y auditoría.
- README ampliado con instalación Docker paso a paso, Windows/Linux, primer administrador, prueba por roles, persistencia y diagnóstico de errores.
- Sincronización PHP compatible con saltos de línea Windows/Linux y exportación Webmin con logo y VERSION.
- Presentación final editable y PDF, matriz de requisitos y paquete de distribución sin secretos.
- Evidencias HTTPS con cinco recorridos, 78 comprobaciones y mediana de 4,718 s, conservando los ensayos internos anteriores.
- Video no realizado e instalación por un compañero pendiente: limitaciones académicas explícitas, sin afirmar cumplimiento inexistente.


## Sin versión nueva · 03/11/2026

- Medición del flujo publicado por HTTPS: cinco recorridos, 27 peticiones por recorrido y 78 comprobaciones aprobadas; mediana total de 4,718 s.
- Muestras originales, medianas en segundos e informe PDF; cola desde MongoDB 0,202 s y desde Redis 0,171 s, sin extrapolar a renderizado o concurrencia.
- Ejecutores HTTP con credenciales por entorno y autorización explícita para datos sintéticos; los cinco tickets creados quedaron cerrados.
- Presentación final de 14 diapositivas actualizada con las evidencias HTTPS y gráfico editable. Se conservan el borrador S4 y las mediciones internas históricas.

## v0.9.0 23/10/2026 Release candidate

- Instalación desde cero con Docker Compose, PHP 8.3, MongoDB 7 y Redis 7.
- Imagen de aplicación con extensión MongoDB y cliente Redis socket, configuración privada por entorno y puertos limitados a localhost.
- Verificación automatizada desde un checkout limpio, carga idempotente con mongosh y diagnóstico de los motores.
- README completo, mapa de claves Redis, ficha portal.yml, roadmap y formulario de instalación por otra persona.
- Guion de exposición en vivo, capturas sintéticas y borrador de presentación final. El video no se realiza por solicitud del equipo.
- Departamentos y supervisores, sugerencia de asignación por carga, acceso exclusivo al agente asignado y notas internas.
- Solución propuesta, cierre con valoración, reapertura, notificaciones internas y escalamiento configurable.
- Validación de documentos desde PHP, conservación de pendientes y fechas visibles dd/mm/aaaa.

Esta versión candidata mantiene pendiente la prueba de instalación realizada por una persona que no configuró el sistema. No sustituye la versión final v1.0.0 del curso.

## v0.3.0 23/10/2026

- Integración de MongoDB y Redis en Webmin mediante cliente PHP socket RESP2.
- Pruebas de conexión fallida, datos inválidos y contingencia ante caída de Redis.
- Tres operaciones medidas con tres ejecuciones y medianas en CI y Webmin.
- Decisiones técnicas y bitácora S3 con límites y evidencias.

## v0.2.0 22/10/2026

- Flujo principal de tickets, CRUD, roles y controles de acceso verificados.
- Carga sintética reproducible e idempotente, variables de ejemplo y pruebas Chromium.
- Integración mediante pull requests y bitácora S2.

## v0.1.0 16/10/2026

- MVP PHP de atención al cliente con roles, tickets, catálogo, contactos y SLA.
- Persistencia MongoDB, caché Redis, licencia MIT y configuración privada excluida de Git.
- Arquitectura, modelo de datos, estructura de carpetas y bitácora S1.

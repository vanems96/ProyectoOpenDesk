# Changelog

## v0.9.0 23/10/2026 Release candidate

- Instalación desde cero con Docker Compose, PHP 8.3, MongoDB 7 y Redis 7.
- Imagen de aplicación con extensión MongoDB y cliente Redis socket, configuración privada por entorno y puertos limitados a localhost.
- Verificación automatizada desde un checkout limpio, carga idempotente con mongosh y diagnóstico de los motores.
- README completo, mapa de claves Redis, ficha portal.yml, roadmap y formulario de instalación por otra persona.
- Guion y automatización de la futura demo, capturas sintéticas y borrador de presentación final. El video se deja pendiente por solicitud del equipo.
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

# Pruebas y evidencia

Revisión: 24/10/2026. Se distinguen almacenamiento simulado, servicios reales
aislados y comprobaciones del servidor compartido. Ninguna prueba aislada se
presenta como una ejecución de producción.

## Comprobaciones locales reproducibles

- `php tests/roles.php`: 179 comprobaciones de autenticación, CRUD, permisos,
  asignación, departamentos, SLA, respuestas, reapertura, CSRF y caché simulada.
- `php tests/integration-tools.php`: 50 comprobaciones de namespace,
  conexiones, ACL, tipos Redis, validadores y cálculo de medianas.
- `php scripts/sync-app.php --check`: sincronía de componentes y aplicación.
- `python tests/release-docs.py`: archivos, enlaces, ficha portal, PowerPoint
  y evidencias de instalación del candidato S4.

Las primeras dos se repitieron satisfactoriamente el 24/10/2026. Las versiones
históricas de las bitácoras pueden mencionar los conteos que tenían entonces.

## CI con MongoDB y Redis reales

PHP QA usa MongoDB 7 y Redis 7 efímeros. `tests/services-real.php`
instala validadores nativos, exige el rechazo de un documento inválido por
MongoDB, verifica CRUD sintético, tres estructuras Redis, TTL e invalidación,
fallo de Redis con contingencia MongoDB y error de conexión MongoDB. El flujo
Chromium comprueba interfaz, roles y CRUD. Exige una base aislada; no se ejecuta
la prueba destructiva sobre los datos del servidor.

- [PHP QA aprobada](https://github.com/vanems96/ProyectoOpenDesk/actions/runs/40068146220).
- [Fallos conservados](mediciones/ci-fallos-2026-10-22.json).
- [CRUD en navegador](evidencias/crud-navegador.json).
- [Instalación limpia aprobada](https://github.com/vanems96/ProyectoOpenDesk/actions/runs/40068146148).

La instalación Docker comprueba motores, esquemas, cargas repetibles PHP y
mongosh, persistencia tras reinicio, primer acceso y archivos privados por HTTP.
No sustituye la instalación por otra persona requerida en S4.

## Webmin y mediciones

MongoDB local y Redis por socket se verificaron en Webmin. Las
[mediciones del servidor](mediciones/webmin-2026-10-23.md) conservan tres
operaciones, tres ejecuciones por operación, medianas, explain y agregación.
Son tiempos con datos sintéticos y caché caliente, no tiempos HTTP completos,
resultados de Atlas ni pruebas concurrentes.

OpenDesk valida desde PHP las escrituras de negocio. La activación de los
validadores nativos en el MongoDB compartido permanece pendiente de collMod
en [#13](https://github.com/vanems96/ProyectoOpenDesk/issues/13). La prueba
nativa aprobada en CI no implica que ese permiso exista en Webmin.

No se garantizan transacciones globales entre todas las colecciones ni
capacidad medida para grandes volúmenes. Véanse [decisiones](decisiones.md)
y [roadmap](roadmap.md). No se publican conexiones ni credenciales reales.

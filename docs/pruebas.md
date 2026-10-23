# Pruebas y evidencia

`php tests/roles.php`: 110 comprobaciones con almacenamiento en memoria;
asignación, resolución, reapertura, roles, aislamiento entre clientes, CRUD,
SLA, CSRF, sesiones, entradas inválidas, caché simulada y contingencia.
`php index.php --test`: reglas de dominio sin conexión a bases.

La revisión de desarrollo también ejecutó 53 comprobaciones HTTP y 34 de
interfaz con DOM simulado sobre el artefacto separado; no son prueba de
producción ni de navegación visual. No se incluyen conexiones privadas ni
fixtures de producción. El motor MongoDB real debe validarse en servidor.

`php tests/integration-tools.php`: comprobaciones adicionales de conexión,
retornos false, ACL, namespace compartido, tres estructuras, esquemas y mediana.
`php scripts/sync-app.php --check` compara componentes y artefacto desplegable.

Ejecutar `php scripts/measure.php 1000 5` con MongoDB y Redis configurados.
Guardar salida y ambiente en docs/mediciones/; aún no hay resultados reales
publicados. Probar caída de Redis, caída de MongoDB, desconexión de navegador,
sincronización y acceso de dos clientes distintos en producción de pruebas.
No se garantizan transacciones globales entre todas las colecciones ni
paginación para grandes volúmenes. Esas limitaciones deben medirse y registrarse.

El workflow PHP QA levanta servicios efímeros para `tests/services-real.php`:
activa validadores, verifica rechazo MongoDB, CRUD sintético, tipos/TTL Redis,
tres operaciones medidas y explain. Exige OPENDESK_TEST_DATABASE=yes y una
base aislada; no se ejecuta sobre producción. Una definición de workflow no
acredita éxito: consultar su resultado y artifact antes de marcar evidencia.

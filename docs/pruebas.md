# Pruebas y evidencia

`php tests/roles.php`: 110 comprobaciones con almacenamiento en memoria;
asignación, resolución, reapertura, roles, aislamiento entre clientes, CRUD,
SLA, CSRF, sesiones, entradas inválidas, caché simulada y contingencia.
`php index.php --test`: reglas de dominio sin conexión a bases.

La revisión de desarrollo también ejecutó 53 comprobaciones HTTP y 34 de
interfaz con DOM simulado sobre el artefacto separado; no son prueba de
producción ni de navegación visual. No se incluyen conexiones privadas ni
fixtures de producción. El motor MongoDB real debe validarse en servidor.

Ejecutar `php index.php --benchmark 1000 30` con MongoDB y Redis configurados.
Guardar salida y ambiente en docs/mediciones/; aún no hay resultados reales
publicados. Probar caída de Redis, caída de MongoDB, desconexión de navegador,
sincronización y acceso de dos clientes distintos en producción de pruebas.
No se garantizan transacciones globales entre todas las colecciones ni
paginación para grandes volúmenes. Esas limitaciones deben medirse y registrarse.

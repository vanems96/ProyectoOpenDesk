# ProyectoOpenDesk

Sistema web de tickets para **Atención al Cliente**, desarrollado en PHP con
MongoDB y caché Redis. Licencia [MIT](LICENSE).

## Problema y usuarios

Una empresa necesita centralizar consultas, reclamos, garantías, devoluciones
y problemas de pedidos para asignar responsables y conservar el seguimiento.
Clientes presentan casos; agentes atienden sus solicitudes asignadas;
administradores gestionan cuentas, catálogo, asignaciones, SLA y reportes.

## MVP

Incluye acceso individual, tres roles, creación y seguimiento de tickets,
clientes, productos y servicios, historial, SLA, CSV, auditoría, datos sintéticos
y creación de tickets pendientes en el navegador cuando no hay conexión.
El administrador puede reabrir casos resueltos y restablecer contraseñas.

No incluye pagos, inventario, adjuntos, correo SMTP, recuperación por enlace,
MFA, chat, calendario laboral ni sincronización automática Webmin–Atlas.

## Arquitectura

- PHP ejecuta API, permisos, sesiones y reglas de atención.
- MongoDB guarda cuentas, contactos, catálogo, tickets, historial y auditoría.
- Redis conserva temporalmente la cola priorizada y contadores de acceso.
- IndexedDB mantiene vista local y solicitudes pendientes por usuario.
- MongoDB local y Atlas son **perfiles alternativos**, no dos motores distintos
  que compartan funciones. No se utiliza SQLite.

Diagrama: [arquitectura](docs/arquitectura/README.md).
Modelo: [colecciones y claves](docs/modelo-datos.md).

## Requisitos

PHP 8.2 o superior y extensión `mongodb` 1.19+ o 2.x. Para la caché se necesita
extensión PHP `redis` y un servidor Redis disponible. Si Redis falla, las
operaciones normales consultan MongoDB. La prueba de rendimiento sí exige Redis.
Node.js solo es necesario para comprobar sintaxis JavaScript durante desarrollo.
No se necesita Composer, Slim ni vendor para la aplicación entregada.

## Instalación

1. Clonar el repositorio.
2. Copiar `config.example.php` a un lugar **fuera de la raíz web** y configurar
   URI, base, Redis y hash inicial. Nunca subir el archivo privado a Git.
3. Establecer `OPENDESK_PRIVATE_CONFIG` con la ruta absoluta de ese archivo.
   En Webmin se usa por defecto `/home/grupo3/.config/opendesk/config.php`.
4. Preparar `accessHash` con un hash de una contraseña elegida privadamente.
   `scripts/password-hash.php` lee la contraseña por entrada estándar. Su
   salida es privada y se guarda en `accessHash` o `APP_PASSWORD_HASH`.
5. Ejecutar los comandos siguientes; sustituir el correo por uno propio.

```bash
export OPENDESK_PRIVATE_CONFIG=/ruta/privada/config.php
php index.php --check
php scripts/diagnose.php
php scripts/mongo-schema.php
php index.php --init-admin administrador@example.test
php index.php --test
php -S 127.0.0.1:8080
```

En PowerShell, la variable se establece con
`$env:OPENDESK_PRIVATE_CONFIG='C:/ruta/privada/config.php'`.
Abrir `http://127.0.0.1:8080/`. Ingresar con el correo inicial y la contraseña
privada elegida; el sistema exige cambiarla. No repetir `--init-admin` si ya
existe un administrador: las cuentas siguientes se crean desde Usuarios.
`.env.example` documenta las variables; **no existe carga automática de .env**.
En producción utilizar HTTPS y desactivar `display_errors` en PHP del servidor.

### Despliegue de un solo PHP en Webmin

```bash
php scripts/export-webmin.php
```

Copiar `dist/index.php` a la carpeta pública del sitio. Este archivo contiene
la aplicación completa, pero **no las conexiones privadas**. La configuración
queda fuera de `public_html`. No copiar `.git`, tests, datos, ZIP ni backups
a la carpeta pública. La exportación no modifica la base ni borra cuentas.
Para desplegar directamente el código del repositorio, conservar `index.php`
y la carpeta `src/`; el exportador facilita la instalación de un archivo.

## Datos de prueba y uso

```bash
php index.php --seed
```

Se crean ejemplos ficticios con identificadores `demo-`; no se importan datos
personales reales ni se crean agentes reales. Asignar los tickets a cuentas
activas desde el administrador. Ver [datos](data/README.md).

También puede ejecutarse `php scripts/seed.php`. JSON Schema se activa
explícitamente con `php scripts/mongo-schema.php`, después de un respaldo y
la revisión de compatibilidad; no borra documentos existentes. Si detecta datos
incompatibles, se detiene. El diagnóstico conecta a ambos motores y verifica
los tres tipos Redis y sus TTL; no basta con comprobar la extensión PHP.

Flujo: crear cuenta de cliente → registrar consulta → asignar agente → guardar
En atención → registrar nota de solución → Resuelto → consultar reportes.
SLA inicial: Alta 4 h, Media 24 h y Baja 72 h continuas. Esperando cliente no
pausa el plazo; cambios de política afectan tickets nuevos.

## Pruebas y medición

```bash
php tests/roles.php
php tests/integration-tools.php
php scripts/sync-app.php --check
php index.php --test
php scripts/measure.php 1000 5
```

Las pruebas de roles usan almacenamiento en memoria y no requieren servicios.
No sustituyen pruebas contra MongoDB y Redis reales. La medición ejecuta tres
operaciones (cola MongoDB, lectura equivalente en Redis y agregación MongoDB),
al menos tres muestras por operación, reporta mediana, memoria, fecha y explain.
Usa una colección aislada y elimina solo los registros de su ejecución.
No se publican cifras inventadas: los resultados reales están pendientes.
Ver [validación](docs/pruebas.md) y [seguridad](SECURITY.md).

Protocolo y tabla: [mediciones S3](docs/mediciones/protocolo.md).
Pruebas reales y fallos: [validación de servicios](docs/validacion-servicios.md).
CI incluye un job con MongoDB/Redis efímeros y conserva evidencia como artifact
solo si pasa. No equivale a medir el servidor Webmin.

Redis compartido: `REDIS_PREFIX=grupo3:opendesk:` por defecto. Todas las claves
nuevas conservan `grupo3:`. Se usan strings, sorted sets y hashes con TTL;
la metadata de cola se devuelve como `cachedAt`. No se usan vaciados globales.
Si hay ACL, configurar `REDIS_USERNAME` y `REDIS_PASSWORD` solo en privado.

## Organización

`src/`: aplicación y componentes verificables; `docs/`: diseño, modelo y
seguimientos; `data/`: descripción de datos sintéticos; `scripts/`: utilidades;
`tests/`: comprobaciones. `src/app.php` es el artefacto completo. Los archivos
de componentes permiten probar sus reglas. Tras editarlos, ejecutar
`php scripts/sync-app.php`; CI detecta un artefacto desactualizado. El módulo
`src/integration.php` contiene diagnósticos, esquemas, caché y mediciones.

## Equipo y seguimiento

Repositorio del equipo: vanems96/ProyectoOpenDesk. Rama de trabajo de Joan:
`Joan`. Cada integrante debe aceptar su acceso y aportar commits desde su
cuenta. La publicación no crea contribuciones en nombre de otros integrantes.
Ver [S1](docs/seguimientos/S1.md) y [backlog](docs/backlog.md).

## Capturas y próximos pasos

Las capturas públicas de esta versión están pendientes de revisión para
evitar publicar correos o contactos reales. Guion: [demo](docs/demo.md).
Pendientes: comprobar Redis en Webmin, medir con servicios reales, instalación
desde cero por otro integrante, evidencias, Issues asignados y release.
La entrega final del curso apunta a `v1.0.0`; esta preparación no afirma
que todos sus requisitos estén completados.

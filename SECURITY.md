# Seguridad

Nunca publicar config.php, .env, credenciales, tokens, URI privadas, el hash
de acceso inicial ni datos personales reales. El ejemplo de configuración
no incluye credenciales funcionales. Las contraseñas presentes en las pruebas
son sintéticas y no se usan en despliegues.

La configuración se carga desde fuera de la raíz web. La API no enumera
variables de entorno ni expone hashes. Sesiones: 30 minutos de inactividad,
8 horas absolutas, HttpOnly, SameSite Strict y Secure bajo HTTPS. Las
escrituras requieren CSRF y permisos en servidor. Contraseñas de 12 caracteres
mínimos y máximo 72 bytes; cambio obligatorio de contraseñas temporales.

Los respaldos y ZIP también deben quedar fuera del directorio público. Si
una credencial se publica accidentalmente, hay que rotarla; borrarla en un
commit posterior no la elimina del historial.

La caché del navegador no está cifrada por la aplicación. No equivale a una
frontera segura frente a quien controla el equipo. No hay MFA ni SMTP.
El código no sustituye la seguridad y los permisos del servidor y las bases.
No publique información sensible en Issues; comunique hallazgos al equipo
por un canal privado ya acordado.

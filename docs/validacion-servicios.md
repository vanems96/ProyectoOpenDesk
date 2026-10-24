# Validación S2 y S3 en Webmin

## Actualización

Antes de actualizar, sincronizar pendientes del navegador y respaldar MongoDB.
Exportar con php scripts/export-webmin.php y reemplazar solo el index.php
público. Se mantiene /home/grupo3/.config/opendesk/config.php fuera de public_html.
El prefijo Redis cambia a grupo3: sin eliminar claves de otros proyectos.

Desde la carpeta de la aplicación desplegada:

```bash
php index.php --check
php index.php --diagnose
php index.php --schema
php index.php --seed
php index.php --benchmark 1000 5
```

--check solo comprueba extensiones. --diagnose conecta a ambos motores,
ejecuta MongoDB ping y verifica tipos y TTL Redis con claves propias.
Devuelve código 0 si ambos pasan y 2 si alguno falla. No imprime secretos.
Si Redis falla, revisar extensión PHP web/CLI, host, puerto, servicio, ACL,
contraseña y base lógica; no publicar credenciales ni abrir Redis a Internet.

--schema consulta documentos incompatibles antes de instalar validadores.
Si falla, revisar registros con un administrador sin borrarlos. Puede
necesitar permisos de collMod/create/index. No activa cambios automáticamente
en cada petición web. --seed agrega solo datos sintéticos, sin sobrescribir.
Las pruebas destructivas tests/services-real.php son exclusivamente para una
base aislada; nunca marcar producción como OPENDESK_TEST_DATABASE=yes.

## Casos manuales

1. Cliente A crea ticket; Cliente B no lo consulta ni actualiza.
2. Administrador asigna a agente activo y guarda En atención.
3. Agente asignado registra Solución propuesta; el cliente confirma el cierre y valora. Otro agente no lo ve ni lo atiende.
4. Administrador reabre; se conserva historial y se retira resolvedAt.
5. Datos inválidos (prioridad, categoría, responsable) se rechazan sin stack trace.
6. En base aislada, insertar un ticket inválido directamente en MongoDB debe
   fallar con validación de documento tras instalar el esquema.
7. Abrir Cola SLA dos veces: observar MongoDB y después Redis; verificar
   cachedAt por API y TTL. Actualizar ticket e inspeccionar invalidación.
8. Simular Redis inaccesible solo en la configuración de pruebas, no deteniendo
   el Redis compartido. Reiniciar el proceso PHP de prueba para recargarla:
   tickets deben seguir funcionando desde MongoDB.
9. Simular MongoDB inaccesible en otro proceso de pruebas: API debe rechazar
   acceso/operaciones con error controlado, sin autorizar por caché solamente.
10. Cortar red del navegador: guardar un ticket y recuperar conexión; confirmar
    una sola creación y el comienzo del SLA al confirmar en MongoDB.

Registrar por caso fecha, entorno, ejecutor, esperado, observado y evidencia.
No marcar como aprobado un caso que no se ejecutó. No incluir contactos reales.

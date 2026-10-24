# Demostración de OpenDesk

Video público: https://github.com/vanems96/ProyectoOpenDesk/releases/download/v0.9.0/OpenDesk_demo_v0.9.0.mp4

Duración objetivo de tres a cinco minutos, con subtítulos integrados y sin locución. La grabación muestra Chromium usando la aplicación real contra MongoDB y Redis en un entorno Docker aislado. No muestra cuentas, contraseñas ni conexiones privadas de Webmin. Los tiempos de instalación no forman parte de la grabación del flujo.

## Recorrido

1. Problema y panel del administrador.
2. Usuarios, departamentos y agente de Garantías.
3. Registro del cliente y solicitud de garantía.
4. Sugerencia por carga y asignación confirmada por administrador.
5. Vista del agente, nota interna y solución propuesta.
6. Avisos del cliente, confirmación y valoración.
7. Reportes, escalamiento y uso de Redis.

## Reproducir la grabación

En una base Docker nueva y explícitamente de prueba, preparar el entorno del README y luego instalar Node.js y Chromium:

```bash
npm ci
npx playwright install chromium
OPENDESK_TEST_DATABASE=yes node tests/demo/video.cjs
```

En PowerShell establecer `$env:OPENDESK_TEST_DATABASE='yes'` antes del comando Node. El script crea un administrador sintético y requiere que todavía no exista uno en esa base. No ejecutarlo contra el despliegue público. El archivo fuente está en `tests/demo/video.cjs` y el workflow de S4 guarda video, capturas, diagnóstico y metadata de duración como evidencia. Los datos ficticios de la grabación no son credenciales recomendadas para producción.

## Exposición en vivo

Mantener preparado un ticket sin asignar y cuentas separadas para cliente, agente y administrador. Completar el flujo en vivo sin modificar tickets anteriores del grupo. Si no hay conexión, usar el video como respaldo y explicar que es una grabación de pruebas, no una conexión en vivo.

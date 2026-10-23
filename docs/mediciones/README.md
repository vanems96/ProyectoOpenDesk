# Mediciones

- [Protocolo](protocolo.md): tres operaciones, al menos tres muestras y mediana.
- [Primera evidencia real en CI](ci-2026-10-22.md): tabla e interpretación.
- [JSON original](ci-2026-10-22.json): evidencia descargada del job aprobado.
- [Fallos e invalidación](ci-fallos-2026-10-22.json): segunda ejecución
  aprobada, con Redis inaccesible, MongoDB aún disponible y conexión MongoDB fallida.

El despliegue Webmin todavía necesita diagnóstico y medición propios.
Registrar entorno, fecha, volumen, muestras, mediana, plan, memoria y límites.
No publicar configuración, URI ni secretos.

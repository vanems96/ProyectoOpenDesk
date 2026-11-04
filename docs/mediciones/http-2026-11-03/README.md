# OpenDesk — prueba completa del flujo publicado
Fecha: 03/11/2026 · Costa Rica

URL: https://grupo3.crnube.org/Opendesk/

## Resultado
5 de 5 recorridos terminados; 78 de 78 comprobaciones aprobadas. Mediana del recorrido automatizado: 4,718 s. Cada recorrido incluye 27 peticiones HTTPS, tres autenticaciones y comprobaciones positivas y negativas.

Se incluyó crear → asignar → nota privada → responder → respuesta del cliente → proponer solución → confirmar cierre con valoración sintética → consultar otra vez → verificar cola, notificaciones y reporte. No es tiempo de resolución por personas.

## Mediciones HTTPS en segundos
| Operación | E1 | E2 | E3 | E4 | E5 | Mediana |
|---|---:|---:|---:|---:|---:|---:|
| Iniciar sesión cliente | 0,207 | 0,207 | 0,226 | 0,207 | 0,227 | 0,207 |
| Listar tickets | 0,489 | 0,442 | 0,424 | 0,433 | 0,453 | 0,442 |
| Crear ticket | 0,226 | 0,168 | 0,145 | 0,247 | 0,147 | 0,168 |
| Asignar empleado | 0,147 | 0,147 | 0,153 | 0,142 | 0,146 | 0,147 |
| Responder al cliente | 0,169 | 0,209 | 0,157 | 0,145 | 0,145 | 0,157 |
| Proponer solución | 0,144 | 0,152 | 0,142 | 0,144 | 0,146 | 0,144 |
| Confirmar cierre y valorar | 0,188 | 0,151 | 0,145 | 0,146 | 0,209 | 0,151 |
| Consultar reportes | 0,140 | 0,141 | 0,146 | 0,165 | 0,140 | 0,141 |
| Cola tras invalidación (MongoDB) | 0,140 | 0,206 | 0,142 | 0,202 | 0,225 | 0,202 |
| Cola repetida (Redis) | 0,167 | 0,171 | 0,171 | 0,144 | 0,205 | 0,171 |

## Recorridos completos
| Ejecución | Segundos | Peticiones | Resultado |
|---|---:|---:|---|
| 1 | 4,718 | 27 | Cerrado · valoración DEMO 4/5 |
| 2 | 4,939 | 27 | Cerrado · valoración DEMO 4/5 |
| 3 | 4,528 | 27 | Cerrado · valoración DEMO 4/5 |
| 4 | 4,695 | 27 | Cerrado · valoración DEMO 4/5 |
| 5 | 5,347 | 27 | Cerrado · valoración DEMO 4/5 |

## Método y alcance
- Entorno real publicado en Webmin: MongoDB local grupo3_db y Redis disponibles.
- 27 tickets al inicio; cinco casos sintéticos creados y cerrados; 32 al terminar, 0 abiertos. Los tickets previos se conservaron.
- Peticiones HTTPS secuenciales con conexión persistente por cuenta; tiempo desde envío hasta lectura completa de la respuesta. Reloj monotónico de alta resolución.
- Autenticación y CSRF reales; se usaron cuentas de demostración existentes. No se guardan contraseñas, cookies ni tokens en los resultados.
- El tiempo de cada login tabulado corresponde a POST login; la preparación de sesión también está incluida en el total del recorrido.
- El total incluye comprobaciones adicionales y respuestas negativas esperadas; no se obtiene sumando medianas por operación.
- La consulta de cola inmediatamente posterior a crear devolvió source=MongoDB y la siguiente source=Redis en las cinco ejecuciones. No se detuvo Redis ni se vaciaron bases.
- Cada ejecución usó un ticket abierto en la cola; el listado total creció de 28 a 32 tickets. El resto estaba cerrado.
- El flujo no incluye dibujo de la interfaz, tecleo, razonamiento de un empleado, espera comercial ni carga concurrente.
- Comprobación visual adicional: los cinco casos aparecen Cerrado en la interfaz real. Esa observación no se usa como tiempo de renderizado.

## Descarga inicial
HTML y logo, cinco conexiones HTTPS nuevas: mediana 1,691 s; HTML solo 0,762 s. HTML 57.583 bytes y logo 504.846 bytes. Incluye DNS/TLS/red/servidor; excluye inicialización de JavaScript, consultas de arranque y renderizado. No es tiempo de pantalla lista.

## Controles funcionales y de seguridad
- Autenticación y rol correctos.
- Un cliente distinto recibió 404 al consultar el ticket.
- El cliente recibió 403 al intentar asignarlo.
- La nota interna se ocultó al cliente y se conservó para administración.
- Calificación 6/5 rechazada con HTTP 422.
- Empleado sin acceso a Usuarios: HTTP 403.
- Cierre guardado, calificación DEMO 4/5 y closedAt verificados en consulta posterior.
- Ticket cerrado desaparece de la cola; notificaciones y reportes se consultaron correctamente.
- Son 20 respuestas negativas esperadas en los cinco recorridos; no son errores inesperados.

## Interpretación para la PPT
Las medianas HTTPS de la cola fueron 0,202 s desde MongoDB y 0,171 s desde Redis. La diferencia observada no demuestra una mejora universal ni reemplaza un ensayo de carga.
La cifra anterior de aproximadamente 2,6× pertenece a operaciones internas aisladas; no debe presentarse como aceleración del aplicativo completo.

## Reproducción autorizada

Los ejecutores públicos leen las credenciales únicamente del entorno privado. La prueba funcional crea tickets sintéticos y los cierra; exige `--allow-write`. No modifica ni borra tickets anteriores. Las valoraciones DEMO cuentan en los reportes generales: usar un entorno separado si no se desea mezclar evidencia sintética con estadísticas del negocio.

```bash
python scripts/measure-http.py --base-url https://SERVIDOR/OPENDESK/ --output /RUTA/PRIVADA/resultados --runs 5 --allow-write
python scripts/measure-http-download.py --base-url https://SERVIDOR/OPENDESK/ --output /RUTA/PRIVADA/resultados --runs 5
```

Variables privadas requeridas: `OD_BENCH_ADMIN_EMAIL`, `OD_BENCH_ADMIN_PASSWORD`, `OD_BENCH_CLIENT_EMAIL`, `OD_BENCH_AGENT_EMAIL`, `OD_BENCH_OTHER_CLIENT_EMAIL`, `OD_BENCH_DEMO_PASSWORD`. Las cuentas cliente, empleado y otro cliente deben ser cuentas de prueba existentes y el empleado pertenecer a Reclamos. No publicar los valores ni archivos de entorno.

El ejecutor admite entre tres y cinco repeticiones para mantener una carga moderada y respetar los límites de autenticación. Cada ejecución debe terminar con código 0; ante un fallo, conserva las muestras parciales y el error. Revisar los casos creados antes de repetir. Las contraseñas, cookies y CSRF nunca se guardan en las evidencias.

## Evidencia y muestras

- [Informe PDF](OpenDesk_resultados_prueba_completa.pdf).
- [Resultados completos JSON](resultados.json).
- [Muestras HTTP CSV](muestras_http.csv).
- [Ciclos completos CSV](ciclos_completos.csv).
- [Descarga inicial JSON](descarga_inicial.json).

En [Tickets](https://grupo3.crnube.org/Opendesk/#tickets), buscar `QA MEDICIÓN COMPLETA · 20261103-193100`. Los cinco casos quedaron cerrados y conservan historial y valoración sintética.

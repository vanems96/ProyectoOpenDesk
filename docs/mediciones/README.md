# Mediciones

## Aplicativo publicado por HTTPS · 03/11/2026

[Prueba completa y resultados](http-2026-11-03/README.md): cinco recorridos automatizados de cliente, administrador y empleado, con 27 peticiones por recorrido y 78 comprobaciones aprobadas. Mediana total: **4,718 s**. La cola dio **0,202 s** desde MongoDB y **0,171 s** desde Redis.

Incluye red y procesamiento del servidor. Excluye tiempo humano, dibujo final de la interfaz y carga concurrente. El volumen total pasó de 27 a 32 tickets; los cinco nuevos son sintéticos, están cerrados y conservan valoraciones DEMO. La descarga de HTML y logo se informa por separado: **1,691 s** de mediana, sin equivaler a pantalla lista.

## Operaciones internas históricas

- [Webmin · 23/10/2026](webmin-2026-10-23.md): tres operaciones de motor, 100 documentos sintéticos y medianas en milisegundos.
- [CI · 22/10/2026](ci-2026-10-22.md): medición en contenedores efímeros.
- [JSON CI](ci-2026-10-22.json).
- [Fallos e invalidación en CI](ci-fallos-2026-10-22.json).
- [Protocolo de operaciones internas](protocolo.md).

Los resultados históricos se conservan. La comparación interna aproximada de 2,6× no representa la aceleración del aplicativo completo ni se extrapola a HTTP, Atlas o usuarios simultáneos. Cada evidencia identifica su entorno y unidad.

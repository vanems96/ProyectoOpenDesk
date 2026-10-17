# Fuente de datos

Generador sintético integrado: `php index.php --seed`. Crea tres clientes,
tres productos o servicios y cuatro tickets de ejemplo mediante upsert, sin
sobrescribir identificadores existentes. Los correos de ejemplo no representan
personas reales. No crea usuarios de agentes. Asignar responsables reales solo
en el entorno privado de pruebas. No publicar exportaciones de producción.

`example-ticket.json` muestra el esquema con información ficticia. Benchmark
genera datos adicionales en odac_benchmark_tickets, separados por ejecución,
y los elimina al finalizar normalmente.

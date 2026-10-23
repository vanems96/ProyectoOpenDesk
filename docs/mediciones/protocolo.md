# Protocolo S3 de mediciones reales

## Preparación

Usar un entorno de pruebas autorizado con PHP, extensiones mongodb/redis,
MongoDB y Redis. Conservar configuración fuera de la raíz web. No ejecutar
pruebas destructivas sobre documentos de producción. La medición usa su
propia colección odac_benchmark_tickets y solo elimina la ejecución creada.

```bash
php scripts/diagnose.php
php scripts/measure.php 1000 5 > resultado-privado.json
```

La segunda orden sale con error si no puede medir, sin fingir un resultado.
No basta con crear un archivo vacío mediante redirección: revisar el código
de salida del comando y que existan las tres operaciones en el JSON.
Repetir con volúmenes razonables para el servidor; los límites son 100 a
100000 documentos y de 3 a 1000 repeticiones por operación.

## Operaciones

| Nombre JSON | Operación | Qué demuestra |
|---|---|---|
| mongo_queue | Filtrar la ejecución y estado Nuevo, ordenar por prioridad y vencimiento, devolver hasta 100 | Lectura de cola con índice compuesto |
| redis_queue | Leer y deserializar el mismo resultado preparado | Comparación con caché caliente |
| mongo_aggregation | Agrupar documentos por prioridad y contar | Aggregation pipeline sobre el volumen de prueba |

Cada operación contiene samplesMs y medianMs. Se requieren tres ejecuciones
como mínimo por operación; cinco por defecto. Explain queda en mongoExplain.
Revisar winningPlan, totalDocsExamined, totalKeysExamined y nReturned. No
afirmar uso de índice sin comprobar el plan que devolvió el motor.

## Registro de resultados

Copiar solo evidencia revisada a docs/mediciones; no publicar configuración,
credenciales ni datos personales. El JSON no incluye URI ni contraseñas,
pero el plan puede incluir nombres internos de base; revisarlos antes de subir.

| Operación | Muestra 1 ms | Muestra 2 ms | Muestra 3 ms | Mediana ms |
|---|---|---|---|---|
| Cola MongoDB | Pendiente | Pendiente | Pendiente | Pendiente |
| Cola Redis | Pendiente | Pendiente | Pendiente | Pendiente |
| Agregación MongoDB | Pendiente | Pendiente | Pendiente | Pendiente |

Agregar las demás muestras si se hicieron cinco. Registrar CPU/RAM, servidor,
PHP/extensiones, versiones de MongoDB/Redis, ubicación de servicios, volumen,
fecha y carga concurrente. Interpretar las medianas: si Redis resulta más
lento, registrarlo igualmente y explicar las condiciones. No calcular mejora
porcentual si la base de comparación es cero ni generalizar una ejecución.

Las muestras de GitHub Actions validan el procedimiento en contenedores
efímeros; no acreditan la latencia ni disponibilidad de Webmin o Atlas.

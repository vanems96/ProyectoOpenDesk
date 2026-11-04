# Entrega de OpenDesk v1.0.0

Fecha: 03/11/2026. Autores: Joan Enrique Chavarría Peraza, Mariela Morales Segura y Emily Muñoz Rodríguez. Grupo 3, viernes, II-2026.

La versión **v1.0.0 cierra técnicamente el MVP**. El 04/11/2026 Joan aclaró que el video es opcional y confirmó que otra persona ya realizó la instalación. Se actualiza el estado según esa confirmación del equipo, sin atribuirle las pruebas de CI ni inventar datos personales o evidencias no proporcionadas. [Estado verificable](estado-v1.0.0.json).

## Requisitos y evidencia

| Requisito del enunciado | Estado | Dónde comprobarlo |
|---|---|---|
| Repositorio público, licencia y estructura | Listo | [MIT](../../LICENSE), [.gitignore](../../.gitignore), [README](../../README.md), src/docs/data/scripts |
| Aportes individuales | Excepción comunicada por Joan | [S1](../seguimientos/S1.md); no se inventan commits de otros autores |
| Problema, usuarios y MVP | Listo | [README](../../README.md) |
| Arquitectura y datos por motor | Listo | [Arquitectura](../arquitectura/README.md), [modelo](../modelo-datos.md), [Redis](../redis-claves.md) |
| Backlog con responsable e hitos | Organizado y cierre revisado | [Backlog](../backlog.md), [Issues](https://github.com/vanems96/ProyectoOpenDesk/issues) |
| Datos sintéticos reproducibles | Listo | [PHP](../../scripts/seed.php), [mongosh](../../scripts/seed-mongosh.js) |
| Conexión a los motores | Probada | [Pruebas](../pruebas.md), [diagnóstico Webmin](../mediciones/webmin-2026-10-23.json) |
| Flujo y CRUD desde la interfaz | Probado | [Evidencia Chromium](../evidencias/crud-navegador.json), [HTTPS](../mediciones/http-2026-11-03/README.md) |
| Variables de ejemplo y secretos privados | Listo en distribución | [.env.example](../../.env.example), [seguridad](../../SECURITY.md); no distribuir .env ni configuración privada |
| Ramas y PR | Listo | [Historial de PR](https://github.com/vanems96/ProyectoOpenDesk/pulls?q=is%3Apr+is%3Amerged) |
| Errores, datos inválidos y contingencia | Probado en entornos separados | [Pruebas](../pruebas.md), [fallos CI](../mediciones/ci-fallos-2026-10-22.json) |
| Tres operaciones, tres o más muestras y mediana | Listo | [HTTP con cinco muestras](../mediciones/http-2026-11-03/README.md), [motores](../mediciones/webmin-2026-10-23.md) |
| Decisiones técnicas | Listo | [Decisiones](../decisiones.md) |
| Redis: tres tipos, TTL, invalidación, contingencia | Implementado y probado | [Mapa](../redis-claves.md), [servicios reales](../../tests/services-real.php) |
| MongoDB: colecciones, índices, explain y agregación | Implementado y medido | [Modelo](../modelo-datos.md), [JSON de medición](../mediciones/webmin-2026-10-23.json) |
| JSON Schema | PHP en Webmin; nativo en CI | [S3](../seguimientos/S3.md), [Issue #13](https://github.com/vanems96/ProyectoOpenDesk/issues/13). collMod del servidor sigue reprogramado |
| README instalable desde cero | Preparado y probado automáticamente | [Pasos 1–13](../../README.md), workflow de instalación limpia |
| Instalación por alguien que no lo configuró | **Realizada, confirmada por Joan el 04/11/2026** | [Registro para el compañero](../instalacion/validacion-externa.md) |
| Video de 3–5 minutos | **Opcional; no realizado, sin pendiente de alcance** | [Demo](../demo/README.md), portal.yml sin enlace inventado |
| Presentación final y guion | Listo | [PPT](../presentacion/OpenDesk_exposicion_final_v1.0.0.pptx), [PDF](../presentacion/OpenDesk_exposicion_final_v1.0.0.pdf), [guion](../presentacion/guion.md) |
| Changelog y Release | Cierre técnico v1.0.0 | [Cambios](../../CHANGELOG.md), [Release](https://github.com/vanems96/ProyectoOpenDesk/releases/tag/v1.0.0) |
| Roadmap a un mes, seis meses y posible TFG | Listo | [Roadmap](../roadmap.md) |
| Bitácoras S1 a S4 | Listo, con estados explícitos | [S1](../seguimientos/S1.md), [S2](../seguimientos/S2.md), [S3](../seguimientos/S3.md), [S4](../seguimientos/S4.md) |
| Ficha portal.yml | Lista; video vacío | [Ficha](../../portal.yml) |
| PDF único para Aula Virtual | Preparado por separado | Proyecto_Final_Grupo_3.pdf con portada, enlaces, cuentas de prueba y presentación. No contiene credenciales de servidor/Atlas/GitHub |
| Entrega al Aula, exposición y participación | Acción del equipo | Entregar antes de las 18:00 del día de exposición; cámaras y participación de los tres autores |

## Archivos de distribución

- Release de GitHub: código fuente, paquete Webmin, presentación editable, presentación PDF e informe de mediciones.
- Paquete Webmin: index.php, assets/, VERSION y licencia, sin secretos. Su configuración se conserva fuera de public_html.
- Paquete privado del Aula: un único PDF con datos académicos y credenciales de aplicación de prueba. No subir ese PDF al repositorio público.

## Confirmación actualizada y trazabilidad

La confirmación del equipo del 04/11/2026 sustituye el estado anterior: instalación externa realizada y video opcional. El nombre de quien instaló, la fecha efectiva y sus capturas no fueron comunicados; se conservan como datos no informados, sin negar la ejecución confirmada. La subida al Aula y la exposición son acciones del equipo, cuya realización no se certifica desde el repositorio.

El enunciado establece que no se cuentan commits posteriores al tag de entrega. Una corrección posterior requiere acordar con el docente qué versión entregará el grupo; no mover silenciosamente v1.0.0 ni otras etiquetas publicadas.

## Ampliación final de Auditoría - 04/11/2026

Joan autorizó actualizar la etiqueta y Release v1.0.0 para incluir selección de 10/20/30/50 registros, paginación y PDF de la vista seleccionada. [Uso y pruebas](../auditoria.md). Esta autorización explícita sustituye la intención anterior de conservar esa etiqueta sin cambios para esta entrega. El commit anterior permanece identificado en CHANGELOG y las notas de Release.

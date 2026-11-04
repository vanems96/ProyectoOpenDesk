# Auditoría: páginas y PDF

Actualización: 04/11/2026. Incluida en la entrega final v1.0.0 por autorización expresa de Joan para actualizar la etiqueta existente.

## Dónde se utiliza

[Aplicación: Auditoría](https://grupo3.crnube.org/Opendesk/#audit). Requiere cuenta administradora. Clientes, agentes y supervisores no tienen acceso a este módulo ni a su exportación.

1. Abrir Auditoría en el menú.
2. Elegir Registros por página: 10, 20, 30 o 50. El valor inicial es 10.
3. Usar Anterior/Siguiente para recorrer los 200 eventos más recientes. Al cambiar el tamaño vuelve a la primera página.
4. Pulsar Descargar vista en PDF. El archivo contiene exactamente los IDs de la página visible y conserva su orden, aunque aparezcan eventos nuevos durante la descarga.

El contador muestra el rango y el total cargado. Si no hay eventos se muestra un mensaje y la descarga queda deshabilitada. El PDF usa páginas A4 horizontales, encabezados repetidos, texto ajustado y numeración. Fechas dd/mm/aaaa HH:mm, zona America/Costa_Rica. Los caracteres españoles se conservan; otros caracteres sin representación Windows-1252 se transliteran.

## Qué se descarga

Fecha, usuario, acción y registro. No se exportan el objeto details, contraseñas, configuración ni datos de sesión. El PDF es de la página seleccionada, no de toda la base ni de las páginas restantes. El límite existente de consulta es 200 eventos recientes y se informa en la interfaz.

## Código y seguridad

- Interfaz y selección: src/app.php, funciones auditPanel, visibleAuditRows y downloadAuditPdf.
- Consulta: GET /api/audit, orden descendente por date e id, máximo 200.
- Descarga: POST /api/audit/pdf, cuerpo JSON con ids. Acepta entre 1 y 50 identificadores únicos, validados y existentes; exige sesión administradora y CSRF.
- El servidor vuelve a consultar los registros por sus IDs; no confía en textos enviados desde el navegador. Un ID desaparecido devuelve 409 para actualizar la vista.
- Generación: auditPdfDocument en src/domain.php. PHP nativo con iconv, sin HTML interpretado, JavaScript PDF ni llamadas a terceros. Escapa delimitadores PDF y utiliza longitudes y offsets en bytes.
- Respuesta: application/pdf, attachment y Cache-Control: no-store. La descarga no crea eventos adicionales ni cambia registros.

## Verificación

php tests/audit-pdf.php ejecuta las 179 comprobaciones base y 40 comprobaciones específicas adicionales: restricciones por rol, CSRF, límites, duplicados, tipos inválidos, IDs desconocidos, tamaños 10/20/30/50, MIME, ausencia de caché y exclusión de details. También se inspeccionó con un lector PDF una muestra de 50 registros con acentos y textos largos, comprobando paginación y contenido completo. GitHub Actions incluye esta suite.

La autorización de actualización de v1.0.0 se refiere a esta entrega final. Se documenta en la Release el commit anterior y el nuevo; no se presenta como si la etiqueta nunca hubiera cambiado.

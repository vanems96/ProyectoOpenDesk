# Guion de presentación final

Borrador editable: [OpenDesk presentación S4](OpenDesk_presentacion_S4.pptx). Doce diapositivas con notas del presentador. La exposición del docente dura entre veinte y veinticinco minutos, más cinco de preguntas.

| Bloque | Diapositivas | Tiempo propuesto | Desarrollo |
|---|---|---|---|
| Problema y usuarios | 1 y 2 | 2 minutos | Necesidad de seguimiento, público y alcance del MVP |
| Arquitectura | 3 | 4 minutos | Qué dato vive en MongoDB y Redis, por qué y qué queda en IndexedDB |
| Demo en vivo | 4 a 7 | 8 minutos | Cliente registra, administrador asigna, agente responde, cliente valora |
| Pruebas y mediciones | 8 a 10 | 4 minutos | Resultados Webmin, fallos, pruebas y reproducción de instalación |
| Lecciones y cierre | 11 y 12 | 3 minutos | Roadmap, límites, versión candidata y requisitos pendientes |

Total propuesto: veintiún minutos más preguntas. La demo se realiza en vivo, no se reemplaza por las diapositivas. Cada integrante debe explicar su parte con conocimiento del código, conforme al enunciado. Este guion no asigna intervenciones a personas sin confirmación del equipo.

## Preparación de la demo

Crear o elegir cuentas ficticias para administrador, agente y cliente. Preparar un ticket sin responsable y usar ventanas o perfiles separados para evitar confundir sesiones. No proyectar contraseñas, URI privadas, consola del servidor ni archivos de configuración.

## Preguntas técnicas que conviene practicar

- Por qué MongoDB guarda el historial integrado en el ticket.
- Cómo Redis usa sus tres estructuras, TTL e invalidación.
- Cómo se retira el acceso a un empleado después de reasignar.
- Qué diferencia existe entre nota privada y respuesta pública.
- Qué sucede si falla Redis o MongoDB.
- Cómo se distingue una medición pequeña de una prueba de carga.
- Qué validación existe en PHP y cuál depende de permisos administrativos en MongoDB.
- Cómo otra persona instala y verifica la aplicación desde cero.

## Pendientes declarados

El video no se realiza por solicitud del equipo. La instalación por otra persona sigue pendiente de evidencia humana; la prueba automatizada no se firma en su nombre. Los autores del proyecto figuran en el README y en portal.yml conforme a la lista proporcionada por Joan. El PDF del Aula Virtual y la versión v1.0.0 corresponden a la entrega final, no a este borrador S4.

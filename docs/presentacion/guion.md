# Guion de presentación final

Actualizado: 03/11/2026. [Presentación final editable](OpenDesk_exposicion_final_v1.0.0.pptx): 14 diapositivas con notas, gráfico editable y enlaces a la prueba HTTPS. Se conserva el [borrador histórico S4](OpenDesk_presentacion_S4.pptx), de 12 diapositivas. La exposición del docente dura entre veinte y veinticinco minutos, más cinco de preguntas.

| Bloque | Diapositivas | Tiempo propuesto | Desarrollo |
|---|---|---|---|
| Problema, usuarios y alcance | 1–4 | 2,5 minutos | Necesidad, público, roles y MVP |
| Arquitectura | 5–7 | 5 minutos | Qué dato vive en MongoDB, Redis e IndexedDB |
| Demo en vivo | 8 | 8,5 minutos | Cliente registra, administrador asigna, empleado propone y cliente confirma y valora |
| Pruebas y mediciones | 9–10 | 3,5 minutos | Resultados HTTPS, muestras en segundos y alcance |
| Lecciones y roadmap | 11–12 | 3 minutos | Decisiones, un mes, seis meses y posible TFG |
| Conclusiones | 13 | 0,5 minutos | Funcionalidad y evidencia comprobadas |
| Preguntas y enlaces | 14 | 5 minutos adicionales | Informe, muestras, bitácoras y aplicación |

Total propuesto: veintitrés minutos más cinco de preguntas. La demo se realiza en vivo, no se reemplaza por las diapositivas. Cada integrante debe explicar su parte con conocimiento del código, conforme al enunciado. Este guion no asigna intervenciones a personas sin confirmación del equipo.

## Cifras actualizadas para las diapositivas 9 y 10

Las 229 comprobaciones locales históricas se componen de 179 de negocio y seguridad más 50 de herramientas. El ensayo HTTPS adicional del 03/11/2026 completó cinco recorridos y 78 comprobaciones; no sumar ambos conteos como si fueran una única suite.

| Medición | Mediana de cinco ejecuciones |
|---|---:|
| Listado de tickets por HTTPS | 0,442 s |
| Cola reconstruida desde MongoDB | 0,202 s |
| Cola repetida con origen Redis | 0,171 s |
| Recorrido automatizado con 27 peticiones y tres roles | 4,718 s |

El recorrido incluye preparación de sesiones, autenticación, creación, asignación, notas privadas, respuestas, propuesta, cierre, controles negativos, avisos y reporte. El total no se calcula sumando medianas. Incluye red y servidor; excluye tiempo humano, renderizado y usuarios simultáneos. La cola comparada tenía un caso abierto y el volumen total pasó de 27 a 32 tickets. Los cinco nuevos quedaron cerrados con valoración DEMO.

La descarga de HTML y logo, medida aparte con conexiones nuevas, dio 1,691 s. No equivale a pantalla lista: excluye inicialización de JavaScript, API de arranque y dibujo.

La mejora interna aproximada de 2,6× del ensayo anterior correspondía a operaciones de motor en milisegundos. No describe la velocidad HTTPS del aplicativo completo.

[Informe y reproducción](../mediciones/http-2026-11-03/README.md) · [Muestras CSV](../mediciones/http-2026-11-03/muestras_http.csv) · [Ciclos CSV](../mediciones/http-2026-11-03/ciclos_completos.csv).

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

El equipo confirmó el 03/11/2026 que no hay video. La instalación por otra persona sigue pendiente de evidencia humana; la prueba automatizada no se firma en su nombre. Los autores figuran en el README y portal.yml. La versión técnica v1.0.0 incluye la presentación final y el PDF del Aula se entrega por separado, con sus cuentas de prueba. La publicación no acredita los dos requisitos académicos pendientes.

# Validación de instalación por otra persona

Cualquier persona que no haya configurado antes OpenDesk puede realizar esta comprobación. Debe empezar con una carpeta nueva y seguir únicamente el README, sin reutilizar archivos privados del servidor del equipo.

## Procedimiento

1. Registrar sistema operativo y versiones de Docker y Docker Compose.
2. Clonar el repositorio y anotar el commit o tag utilizado.
3. Seguir la sección Instalación desde cero del README, incluyendo el cambio de contraseña inicial.
4. Ejecutar diagnóstico, carga repetible de datos, prueba PHP y exportación Webmin según el README.
5. Registrar una cuenta de cliente desde la interfaz, crear un ticket, asignarlo desde el administrador y completar una solución con valoración.
6. Detener y volver a iniciar los contenedores sin eliminar volúmenes y comprobar que los datos permanecen.
7. Registrar los errores encontrados y si las soluciones del README fueron suficientes.

## Registro a completar por quien realiza la prueba

- Estado: PENDIENTE de ejecución por una persona externa a la configuración.
- Persona o identificador autorizado:
- Fecha en dd/mm/aaaa:
- Sistema operativo:
- Docker y Docker Compose:
- Commit o tag:
- Hora de inicio y fin:
- Pasos completados:
- Errores y cambios necesarios en el README:
- Evidencias adjuntas (sin contraseñas ni archivos privados):
- Resultado: aprobado / requiere correcciones.

La prueba automatizada de instalación limpia complementa este registro, pero no demuestra que otra persona siguió el README. No se debe firmar ni marcar este documento como aprobado antes de realizar la prueba.

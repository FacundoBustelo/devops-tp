# Recorrido manual de Postman (E6)

Importar [colección](Prex.postman_collection.json) y [environment](Local.postman_environment.json). Seleccionar Prex local, ajustar base_url al puerto instalado y completar password localmente (`contraseña-local` si se utilizó LocalUserSeeder). Revisar email; el seeder no reemplaza usuarios previos. Mantener GIPHY_API_KEY solo en el servidor. Evitar variables globales/colección con los mismos nombres que puedan ocultar el environment. No exportar valores reales de password/token/IDs.

Ejecutar las solicitudes manualmente con Send. La automatización del login guarda `access_token` en la variable `token` del environment; copiar manualmente `user.id` a `user_id` tras un login 200 con token no vacío. El script posterior al login limpia el token anterior si la respuesta no permite guardarlo. Las operaciones protegidas heredan Bearer `{{token}}`; login usa No Auth. No hay scripts de prerequest, tests automáticos ni encadenamiento.

1. Ejecutar login y comprobar 200, `expires_in=1800` y token guardado. Copiar manualmente `user.id` de la respuesta a `user_id` y guardar el environment.
2. Ejecutar búsqueda y comprobar 200. Copiar un `data[].id` a `gif_id`, conservando exactamente mayúsculas y minúsculas. Si no hay resultados, cambiar `query` y repetir manualmente.
3. Ejecutar consulta y comprobar 200 y el mismo ID.
4. Revisar `alias`, `gif_id` y `user_id`; ejecutar creación y comprobar 201.
5. Ejecutar la solicitud de duplicado con los mismos valores y comprobar 409.

Los cuerpos son JSON editables con variables del environment. Si password o alias contienen comillas, barras invertidas o saltos de línea, editar el cuerpo con el escape JSON correspondiente. Volver a ejecutar con el mismo usuario/GIF puede producir 409 en creación; elegir otro GIF para comprobar un nuevo 201. Cada creación y duplicado consulta GIPHY, por lo que proveedor indisponible puede impedir el 409.

Registrar fecha, base_url sin secretos, login 200, almacenamiento automático del token, carga manual de user_id y gif_id, búsqueda 200 e ID exacto, consulta 200, creación 201 y duplicado 409; confirmar que el alias original se conserva mediante la evidencia en MySQL. No adjuntar token, password ni API Key. Estado: **pendiente de ejecución manual por el usuario**; validación local de JSON y del script del token no sustituye esta comprobación. Ejecución automatizada de Postman queda fuera del alcance.

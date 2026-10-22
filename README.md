# Trabajo Práctico Integrador de DevOps — Universidad de Palermo

API PrexChallenge: Laravel 13.35.0, Passport 13.9.0, PHP 8.4.26 y MySQL 8.4.11. Ofrece autenticación, búsqueda y consulta de GIFs con GIPHY, favoritos y auditoría en MySQL, con arquitectura hexagonal.

La fase 1 incorpora [Swagger y OpenAPI](docs/openapi/validation.md). La fase 3 incorpora imágenes autocontenidas, construcción multietapa y ejecución sin root ([evidencia](docs/docker/validation.md)). La fase 4 incorpora CI y el procedimiento de publicación versionada en Docker Hub ([validación y límites](docs/cicd/validation.md)). La activación en GitHub, el ruleset y la publicación real siguen pendientes; no se incorpora despliegue ni observabilidad ampliada.

Se usa GitHub Flow con ramas cortas y revisión por Pull Request, Conventional Commits y versiones SemVer `vMAJOR.MINOR.PATCH`. `main` debe permanecer estable.

## Instalación nueva

Requisitos: Git, Docker Engine, Docker Compose v2 con soporte de `--wait`, curl, acceso a Internet y una clave GIPHY válida. PHP, Composer y MySQL se ejecutan dentro de Docker.

```bash
git clone https://github.com/FacundoBustelo/devops-tp.git
cd devops-tp
# Preparación explícita: genera APP_KEY y dos contraseñas independientes.
# El script se niega a reemplazar un .env existente.
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/work" -w /work \
  --entrypoint php \
  php:8.4.26-fpm-alpine3.24@sha256:78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a \
  scripts/init-env.php
```

También puede ejecutarse `php scripts/init-env.php` con PHP CLI local. Completar `GIPHY_API_KEY` en `.env`, sin imprimirla ni incorporarla al repositorio. Revisar `HTTP_PORT=8080`, `DB_FORWARD_PORT=3306` y `APP_URL`; elegir otros puertos si están ocupados. Conservar `.env` fuera de la imagen y respaldarlo de forma segura. `init` exige `APP_KEY` y nunca ejecuta `key:generate`.

```bash
docker compose up -d --build --wait
docker compose ps -a
curl --fail http://127.0.0.1:8080/up
```

El archivo principal es `docker-compose.yml`. Su ejecución no monta el checkout: PHP contiene código, dependencias de producción, OpenAPI y la vista/paquete Swagger; Nginx contiene su configuración y `public/`. Los assets Swagger se sirven por las rutas Laravel existentes.

El orden de inicio es MySQL saludable → `init` exitoso → FPM escuchando → Nginx. `init` ejecuta migraciones pendientes, genera el par Passport únicamente si faltan ambas claves y reutiliza el cliente OAuth personal activo compatible con `users` (incluyendo provider nulo compatible). Un par incompleto falla sin sobrescribirlo. En `APP_ENV=local`, crea el usuario `user@example.test` con password `contraseña-local` solo si no existe; no restaura usuarios borrados ni cambia contraseñas. No consulta GIPHY durante la preparación.

La salud SQL de MySQL ejecuta `SELECT 1`. La salud de FPM abre una conexión TCP local con PHP: comprueba que escucha, sin probar Laravel ni MySQL. No se usa `ping.path` porque necesita FastCGI ([manual de FPM](https://www.php.net/manual/en/install.fpm.configuration.php)). Nginx comprueba `/up` con `wget`: recorre HTTP → FastCGI → Laravel y espera 200. `/up` no consulta MySQL. La red de PHP permite salida hacia GIPHY; FPM no publica puertos y los puertos HTTP/MySQL se publican solo en loopback.

## Imágenes, versiones y desarrollo

El Dockerfile tiene targets `dependencies`, `preparation`, `runtime`, `development-dependencies`, `development` y `nginx`, sobre una base PHP oficial Alpine fijada por versión y digest. Instala desde `composer.lock` antes de copiar código para reutilizar la capa de dependencias. `runtime` excluye Composer, Git, los ejecutables unzip/phpize, cabeceras de PHP, Tinker, PHPUnit y Pint. Las utilidades básicas de BusyBox permanecen en la base Alpine. PHP e `init` usan `www-data` (82), Nginx usa `nginx` (101), escuchando en 8080. Los propietarios se preparan durante el build; el entrypoint solo configura umask y termina con `exec`. El código pertenece a root y el usuario de PHP puede escribir en `storage/`, `bootstrap/cache/` y `/tmp`.

Se pueden parametrizar `IMAGE_REPOSITORY`, `APP_VERSION` y `VCS_REF` en `.env`, por ejemplo `IMAGE_REPOSITORY=mi-registro/prex`, `APP_VERSION=1.2.3`, `VCS_REF=<commit>`. Las imágenes se llaman `<repositorio>-php:1.2.3`, `<repositorio>-php-dev:1.2.3` y `<repositorio>-nginx:1.2.3`. Las etiquetas OCI registran versión y revisión. Construir localmente no publica imágenes.

El override de herramientas es explícito:

```bash
docker compose -f docker-compose.yml -f compose.dev.yaml build app
docker compose -f docker-compose.yml -f compose.dev.yaml run --rm --no-deps app composer check-platform-reqs
```

Para editar código en vivo, agregar también `-f compose.source.yaml`. Monta directorios de fuentes de solo lectura, conservando vendor y directorios de ejecución de la imagen; no persiste dependencias ni cachés. Los cambios de `composer.json`/lock requieren reconstruir el target de desarrollo. Para generar OpenAPI, usar la imagen de desarrollo sin el override de fuentes y copiar el resultado al checkout como se indica debajo.

## Configuración y persistencia

Solo persisten `mysql_data` y `passport_keys`. Passport carga sus claves mediante `Passport::loadKeysFrom(storage_path('passport'))`; los archivos quedan en `storage/passport/oauth-private.key` y `storage/passport/oauth-public.key` con permisos 600. El volumen se inicializa con propietario 82 desde la imagen. No persisten el resto de `storage/`, vendor, vistas compiladas ni `bootstrap/cache/`. La aplicación no almacena archivos subidos por usuarios: favoritos y auditoría quedan en MySQL. Sesiones y caché usan las tablas existentes de base de datos; logs usan el canal `stderr`.

Compose pasa variables explícitas a PHP; la contraseña root de MySQL llega únicamente a MySQL. `.dockerignore` limita el contexto a entradas de construcción y excluye `.env`, claves, SQLite locales y credenciales. No pasar secretos mediante build args.

```bash
docker compose down                  # conserva ambos volúmenes
docker compose up -d --wait          # recrea los contenedores y ejecuta init
```

**`docker compose down -v` elimina base, clientes, tokens, favoritos, auditoría y claves Passport.** Reservarlo para instalaciones descartables. Cambiar las contraseñas de `.env` no rota credenciales de un volumen MySQL existente.

Esta fase no migra instalaciones anteriores: conservar sus volúmenes, `.env` y claves en el checkout. Antes de adoptar el nuevo Compose con esos datos hace falta una migración explícita y respaldada de las claves antiguas al volumen dedicado. No levantar esta configuración sobre un proyecto previo como parte de las pruebas.

Si una inicialización falla, consultar `docker compose logs init`, corregir la causa y repetir `up -d --build --wait`. Nunca usar `migrate:fresh` para recuperarla. Ante un par Passport incompleto, restaurar ambas claves del mismo respaldo en el volumen, con propietario 82 y modo 600; no regenerar una sola clave. Si se reemplaza deliberadamente el par, los tokens existentes dejan de ser válidos.

`TRUSTED_PROXIES` acepta IP/CIDR explícitos de proxies controlados separados por comas; vacío no confía en ninguno. No admite comodines ni /0. El proxy debe reemplazar los headers de forwarding recibidos; no se confía en X-Forwarded-Host.

## Recorrido de la API

Importar la [colección Postman](docs/postman/Prex.postman_collection.json) y el [environment local](docs/postman/Local.postman_environment.json). Completar base_url, email y password con los valores locales. Ver [guía Postman](docs/postman/README.md).

1. Login: `POST /api/v1/login`, espera 200 con access_token, expires_in=1800 y user.id. Conservar token y user_id sin publicarlos.
2. Buscar: `GET /api/v1/gifs?QUERY=cats`, con Bearer, espera 200. Elegir un ID de los resultados.
3. Consultar: `GET /api/v1/gifs/{id}`, espera 200 con ese ID.
4. Crear favorito: `POST /api/v1/favorites` con GIF_ID, ALIAS y USER_ID del actor, espera 201; repetir devuelve 409.

Búsqueda, consulta y favorito requieren clave válida y disponibilidad de GIPHY. El token dura 30 minutos. Los secretos se mantienen en el servidor o en valores locales de Postman, sin exportarlos.

## Arquitectura y servicios

`app/Domain` contiene valores, DTOs, errores y puertos `Authenticator`, `GifCatalog`, `FavoriteGifRepository` e `InteractionRepository`. `app/Application` contiene login, búsqueda, consulta, preparación/persistencia de favoritos y redacción/registro de interacciones. `app/Infrastructure` implementa Passport, HTTP GIPHY, repositorios Eloquent, controladores y coordinación transaccional. Los controladores delegan; no consultan la base directamente.

| Servicio | Método y ruta | Entradas | Éxito |
|---|---|---|---|
| Login público | POST `/api/v1/login` | JSON email, password | 200; token de 1800 segundos y user.id |
| Buscar | GET `/api/v1/gifs` | QUERY; LIMIT 1–50 (25); OFFSET 0–4999 (0) | 200, incluso vacío |
| Consultar | GET `/api/v1/gifs/{id}` | ID textual exacto | 200 |
| Favorito | POST `/api/v1/favorites` | JSON GIF_ID, ALIAS, USER_ID | 201 |

Los últimos tres requieren Bearer. Enviar Accept y, para POST, Content-Type `application/json`. QUERY: no vacío, hasta 50 caracteres; ALIAS: no vacío, hasta 100; IDs externos: hasta 128 caracteres. USER_ID debe coincidir con el actor autenticado. Duplicado por usuario/GIF: 409 sin cambiar alias. Validación 422; credenciales/token 401; propietario 403; ausencia 404; proveedor incompatible 502, indisponibilidad/cuota 503, timeout 504. Login: cinco peticiones/minuto/IP; sexta 429 con Retry-After. Timeouts GIPHY: conexión 2 s / total 5 s, sin reintentos.

Favoritos consulta GIPHY antes de la transacción. Dentro de ella inserta favorito, serializa respuesta y persiste auditoría sanitizada; tras commit marca el contexto confirmado. Fallos revierten ambas escrituras y permiten auditar el error final fuera de la transacción. Toda interacción `/api` se captura con actor verificado, servicio, request/response sanitizados, status, IP, fecha y UUID del servidor. No se registran headers/cookies; cuerpos opacos o streaming se omiten.

## Swagger y OpenAPI

Con Compose iniciado, abrir [Swagger UI](http://127.0.0.1:8080/docs). El documento se sirve en [`/docs/openapi.json`](http://127.0.0.1:8080/docs/openapi.json). Ajustar el puerto a `HTTP_PORT` de `.env`. Documentación y assets se sirven desde la aplicación y el paquete instalado, en el mismo origen; no requieren una clave GIPHY para visualizarse.

1. Expandir **POST /api/v1/login**, pulsar **Try it out**, completar email y password del usuario local y pulsar **Execute**. Las credenciales de instalación figuran en la sección «Instalación nueva»; no están incluidas como ejemplos OpenAPI.
2. Con la respuesta **200**, copiar `access_token` y anotar `user.id`.
3. Pulsar **Authorize**, pegar solo el token, sin `Bearer`, y confirmar **Authorize**. Swagger agrega el header `Authorization: Bearer` a las tres operaciones protegidas. Login sigue siendo público.
4. Ejecutar la búsqueda con `QUERY`, copiar un ID para consultar el GIF y crear el favorito con `GIF_ID`, `ALIAS` y `USER_ID` igual al `user.id` del login. Estas operaciones necesitan una clave GIPHY válida en el servidor. El mismo usuario/GIF vuelve a producir **409**.

El token vence a los 30 minutos y la autorización se pierde al recargar o cerrar la página. Volver a ejecutar login y Authorize cuando sea necesario. No se configuran flujos OAuth ni se persiste el token en el navegador. El validador remoto de Swagger está desactivado.

La fuente editable son los atributos PHP de los cuatro controladores en `app/Infrastructure/Http/` y los componentes en [`app/Infrastructure/OpenApi/`](app/Infrastructure/OpenApi/). El archivo [`docs/openapi/openapi.json`](docs/openapi/openapi.json) se versiona y se sirve sin regenerarlo por petición. Después de cambiar atributos, regenerar explícitamente y revisar el diff:

```bash
(
  set -eu
  docs_container=$(docker compose -f docker-compose.yml -f compose.dev.yaml run -d --no-deps app composer docs:generate)
  trap 'docker rm "$docs_container" >/dev/null' EXIT
  docs_status=$(docker wait "$docs_container")
  docker logs "$docs_container"
  test "$docs_status" -eq 0
  docker cp "$docs_container":/var/www/html/docs/openapi/openapi.json docs/openapi/openapi.json
)
git diff -- docs/openapi/openapi.json
docker compose -f docker-compose.yml -f compose.dev.yaml run --rm --no-deps -e APP_ENV=testing -e LOG_CHANNEL=null -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: app vendor/bin/phpunit --filter OpenApiDocumentationTest
```

Compose asigna un nombre nuevo al contenedor de generación; se utiliza su ID para esperar, copiar y retirarlo. Un contenedor anterior llamado `prex-docs` no interfiere. Si la generación falla, se muestran sus logs y se conserva el documento del checkout.

Las pruebas generan en un directorio temporal, comprueban la validación de swagger-php sin advertencias, comparan el resultado con el archivo versionado, resuelven las referencias internas y contrastan las cuatro operaciones con las rutas registradas, normalizando HEAD. También comprueban login público, Bearer en las otras tres operaciones y publicación de UI, JSON y assets.

Como validación adicional, con Node.js 22 y npm disponibles en el host, ejecutar Redocly CLI con versión fijada y el verificador HTTP por Nginx:

```bash
npm exec --yes --package=@redocly/cli@1.34.5 -- redocly lint docs/openapi/openapi.json
node scripts/verify-docs.mjs http://127.0.0.1:8080
```

El último comando acepta la URL base para usar otro puerto. Comprueba `/up`, `/docs`, la especificación y todos los assets referenciados, sus tipos de contenido y su coincidencia con el documento versionado y el paquete instalado. No envía credenciales ni consulta GIPHY.

## Pruebas

Usar un archivo de entorno de prueba con credenciales propias y un nombre de proyecto, imágenes y puertos distintos a los del entorno habitual. Estos ejemplos suponen `/tmp/prex-test.env`, `IMAGE_REPOSITORY=prex-test`, HTTP_PORT=18093 y DB_FORWARD_PORT=13393. Prepararlo con el script en una carpeta temporal y copiar allí `.env.example`; no modificar `.env` actual.

```bash
# SQLite: las pruebas bloquean HTTP externo y usan claves efímeras.
docker compose --env-file /tmp/prex-test.env -p prex-test -f docker-compose.yml -f compose.dev.yaml build app
docker compose --env-file /tmp/prex-test.env -p prex-test -f docker-compose.yml -f compose.dev.yaml run --rm --no-deps -e APP_ENV=testing -e LOG_CHANNEL=null -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e SESSION_DRIVER=array app vendor/bin/phpunit --display-phpunit-notices
docker compose --env-file /tmp/prex-test.env -p prex-test -f docker-compose.yml -f compose.dev.yaml run --rm --no-deps app vendor/bin/pint --test
# MySQL: proyecto exclusivo, nunca aplicar al proyecto habitual.
docker compose --env-file /tmp/prex-test.env -p prex-mysql-test -f docker-compose.yml -f compose.dev.yaml -f compose.audit-test.yaml up -d --wait mysql
docker compose --env-file /tmp/prex-test.env -p prex-mysql-test -f docker-compose.yml -f compose.dev.yaml -f compose.audit-test.yaml run --rm app vendor/bin/phpunit -c phpunit.mysql.xml --display-phpunit-notices
docker compose --env-file /tmp/prex-test.env -p prex-mysql-test -f docker-compose.yml -f compose.dev.yaml -f compose.audit-test.yaml down
```

La CI genera `APP_KEY`, `DB_PASSWORD` y `MYSQL_ROOT_PASSWORD` efímeros por job para la interpolación de Compose, incluso en `build` y `config`. Usa un archivo temporal con `--env-file`, proyectos e imágenes exclusivos y pruebas con HTTP externo simulado. Al terminar elimina sus contenedores, redes, volúmenes y archivo temporal; no utiliza `.env` ni credenciales reales del checkout.

La suite MySQL fuerza `prex_audit_test` y rechaza otras bases antes de migrar. Comprueba integridad, rollback y concurrencia real.

Para probar recreación sin montar código, levantar el Compose principal bajo un proyecto exclusivo. Los verificadores escriben una tabla temporal y un archivo de prueba sensible en `/tmp`; extraer este último antes de recrear y restaurarlo después, sin publicarlo. Solo los volúmenes de datos y claves persisten:

```bash
docker compose --env-file /tmp/prex-test.env -p prex-test exec app php scripts/verify-runtime.php write
docker compose --env-file /tmp/prex-test.env -p prex-test exec app php scripts/verify-login.php write
docker compose --env-file /tmp/prex-test.env -p prex-test cp app:/tmp/login-verification.key /tmp/login-verification.key
chmod 600 /tmp/login-verification.key
docker compose --env-file /tmp/prex-test.env -p prex-test down
docker compose --env-file /tmp/prex-test.env -p prex-test up -d --wait
# Dentro de los 30 minutos de validez; restaurar como el usuario de PHP:
docker compose --env-file /tmp/prex-test.env -p prex-test exec -T app sh -c 'cat > /tmp/login-verification.key' < /tmp/login-verification.key
docker compose --env-file /tmp/prex-test.env -p prex-test exec app php scripts/verify-runtime.php read
docker compose --env-file /tmp/prex-test.env -p prex-test exec app php scripts/verify-login.php read
docker compose --env-file /tmp/prex-test.env -p prex-test exec app php scripts/verify-runtime.php cleanup
docker compose --env-file /tmp/prex-test.env -p prex-test exec app php scripts/verify-login.php cleanup
```

Con Node.js disponible y vendor del lock en el host, `node scripts/verify-docs.mjs http://127.0.0.1:18093` comprueba `/up`, Swagger, JSON y los cuatro assets a través de Nginx, comparando bytes con las fuentes. Revisar además archivos estáticos y el bloqueo de `/.env`, `/composer.json`, `/artisan`, `/vendor/autoload.php` y claves. Registrar resultados reales, sin inferirlos a partir de los comandos.

## CI y publicación de imágenes

`.github/workflows/ci.yml` corre en cada PR hacia `main` y puede reutilizarse mediante `workflow_call`. Calidad ejecuta Pint, las seis pruebas existentes de OpenAPI y las pruebas de protección del publicador. PHPUnit corre en una matriz SQLite/MySQL, usando el target `development`, las configuraciones existentes y `compose.audit-test.yaml`. El job final **CI Required** exige éxito de calidad y de toda la matriz, aunque haya fallos, cancelaciones o jobs omitidos. No hay filtros por archivos que dejen PRs sin check.

Se pueden repetir los jobs sin preparar `.env` (Docker Engine, Buildx, Compose moderno y Python 3; las pruebas de imágenes finales también necesitan Node.js 22 o superior):

```bash
bash scripts/ci/check.sh quality
bash scripts/ci/check.sh sqlite
bash scripts/ci/check.sh mysql
```

Después de revisar e integrar el código, configurar en GitHub las variables de Actions `DOCKERHUB_NAMESPACE` (usuario u organización, en minúsculas) y `DOCKERHUB_USERNAME` (usuario que autentica), y el secreto `DOCKERHUB_TOKEN` con permiso de escritura sobre ambos repositorios. Crear previamente los repositorios **públicos** `<namespace>/devops-tp-php` y `<namespace>/devops-tp-nginx`. El token solo se entrega al paso de autenticación del job de publicación y se usa un directorio Docker temporal con logout al finalizar. CI y sus PRs no reciben ese secreto.

Ejecutar CI en GitHub y configurar el ruleset de `main` para exigir Pull Request y el check **CI Required**, con la rama actualizada antes de integrar y sin bypass que permita omitirlo. Comprobar con un PR fallido que GitHub bloquea el merge y con su corrección que lo habilita. **Stop-the-Line solo será efectivo después de configurar y comprobar ese requisito.** La presencia del YAML por sí sola no protege `main`.

`.github/workflows/release.yml` se dispara al publicar tags `v*` y rechaza los que no sean `vMAJOR.MINOR.PATCH` estables, sin ceros iniciales, prereleases ni metadatos. Verifica que el commit pertenezca al historial actual de `main` y ejecuta CI antes del job de publicación. La pertenencia admite commits anteriores de `main`; no exige que el tag apunte a su punta. Crear y publicar un tag constituye la operación de release y requiere la autorización posterior del responsable; no se ejecutó durante esta implementación.

El job verifica que ambos repositorios sean públicos y que ninguna de las dos versiones exista. Construye una sola vez cada target `runtime` y `nginx` para `linux/amd64` con `--load`, asignando versión sin `v` y SHA completo en etiquetas OCI. Arranca esas imágenes con Compose `--no-build --pull never`, prueba inicialización, salud, acceso SQL sin root, directorios escribibles, Swagger y assets. Luego autentica, vuelve a comprobar disponibilidad y publica **esas mismas imágenes locales** con `docker push`, sin reconstrucción ni etiqueta `latest`:

```text
<namespace>/devops-tp-php:MAJOR.MINOR.PATCH
<namespace>/devops-tp-nginx:MAJOR.MINOR.PATCH
```

La release registra versión, SHA completo, plataforma, identificadores de imágenes probadas y digests publicados en logs y en el resumen de Actions. El archivo temporal `release-images.json` enlaza las imágenes probadas con el publicador; este rechaza cambios de identificador. Las releases se serializan y no se cancelan automáticamente. Docker Hub no ofrece una transacción conjunta para los dos pushes: si uno falla después del otro, la release queda **fallida y parcial**. No reejecutar esa versión ni borrar tags para reutilizarla; investigar y preparar una versión nueva con autorización. Los checks de existencia rechazan tags ya publicados, pero no son una escritura atómica: reservar estos repositorios a este flujo y habilitar [tags inmutables en Docker Hub](https://docs.docker.com/docker-hub/repos/manage/hub-images/immutable-tags/) para impedir carreras con escritores externos.

La [evidencia de fase 4](docs/cicd/validation.md) distingue las comprobaciones locales de las pendientes en GitHub y Docker Hub, e incluye un comando para probar las imágenes finales sin publicarlas. No hay despliegue automático.

## Diagramas y diagnóstico

[Índice UML y DER](docs/diagrams/README.md): fuentes PlantUML, SVG y relaciones físicas/lógicas diferenciadas.

Usar `docker compose logs --tail=100 init app nginx mysql`, `php artisan migrate:status` y `php artisan route:list`. Composer y las herramientas de pruebas requieren el override de desarrollo. Evitar compartir `.env`, Compose interpolado, tokens o logs sin revisar.

Un 503 GIPHY requiere revisar configuración y respuesta del proveedor sin imprimir la clave. Una respuesta observada no demuestra cuota futura. La auditoría de interacciones y su fallback en stderr no sustituyen la futura observabilidad ni garantizan recuperación durable durante una caída MySQL. Los IDs de GIPHY se conservan como texto y login usa tokens personales Passport.

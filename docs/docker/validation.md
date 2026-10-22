# Fase 3 — evidencia de ejecución

Validación realizada el **22 de octubre de 2026**, sobre Linux/amd64. Base del cambio: `a2cbcc5` (merge de OpenAPI en `main`), rama `chore/docker-optimization`, todavía sin commits de esta fase al realizar la validación. Se ejecutó `fetch --prune`, fast-forward de `main` y creación de la rama; no hubo push, PR, CI/CD ni publicación de imágenes.

## Implementación observada

- `docker-compose.yml` reemplaza `compose.yaml`. Conserva MySQL, init, PHP-FPM y Nginx; el principal no monta fuentes. `compose.dev.yaml` selecciona herramientas y `compose.source.yaml` permite montar directorios de fuentes de solo lectura.
- Targets de dependencias y preparación separados del runtime. Composer instala desde el lock por manifiestos; runtime incluye aplicación, vendor de producción, vista Swagger y OpenAPI. Nginx incluye configuración y `public/`, escuchando en 8080, sin volumen compartido de estáticos.
- PHP/init corren como UID/GID **82**; Nginx como **101**. Código y vendor no son escribibles por PHP; sí los directorios de ejecución. El entrypoint usa `umask` y `exec`, sin su, chown ni chmod recursivos al iniciar.
- Runtime no ofrece los ejecutables Composer, Git, unzip, phpize, php-config, gcc, make ni autoconf; no contiene paquetes Tinker, PsySH, PHPUnit o Pint. BusyBox conserva sus utilidades básicas, incluyendo applets accesibles por su binario. Tinker/PsySH pasaron a desarrollo; las **126 versiones y referencias de paquetes del lock se conservaron** (94 paquetes de producción y 32 de desarrollo). Composer actualizó también la URL canónica de phpdotenv.
- Solo persisten MySQL y el volumen `passport_keys` montado en `storage/passport/`. Sesiones/caché usan MySQL y los logs el canal existente stderr. APP_KEY se genera mediante preparación explícita y se inyecta en ejecución. PHP no recibe MYSQL_ROOT_PASSWORD.

## Aislamiento

Se usaron credenciales nuevas, un APP_KEY nuevo y `/tmp/devops-phase3.env` con modo 600. Solo se reutilizó la clave GIPHY existente, sin imprimirla. Los proyectos fueron:

| Proyecto | Uso | Puertos publicados |
|---|---|---|
| `devops-phase3-validation` | Runtime, HTTP, inicialización, persistencia y GIPHY | 127.0.0.1:18093 y 127.0.0.1:13393 |
| `devops-phase3-sqlite` | PHPUnit SQLite y Pint | Ninguno |
| `devops-phase3-mysql` | Suite MySQL con compose.audit-test.yaml, base prex_audit_test | Ninguno |
| `devops-phase3-source` | Override opcional de fuentes, pruebas OpenAPI con SQLite | Ninguno |

Los volúmenes llevan los prefijos de esos proyectos. No se ejecutaron comandos de migración, copia de claves, down ni eliminación de volúmenes contra `devops-tp` ni contra los otros entornos existentes. Sus secretos y volúmenes no se migraron. Al terminar se retiraron los contenedores y redes de los cuatro proyectos de prueba con down sin -v; sus volúmenes quedaron conservados y los proyectos previos continuaron en ejecución. El cambio de fuentes en este checkout sigue siendo visible para cualquier contenedor antiguo que ya lo tenga montado; adoptar las imágenes nuevas con datos anteriores requiere una migración explícita de claves, fuera de esta validación.

## Resultados

| Comprobación ejecutada | Resultado observado |
|---|---|
| Build runtime, development y nginx | Exitosos sobre Alpine; Composer check-platform-reqs exitoso en preparación y desarrollo. No hizo falta fallback Debian. |
| Extensiones PHP | bcmath, curl, intl, mbstring, opcache, pcntl, pdo_mysql, pdo_sqlite, sodium y zip presentes, además de las extensiones base. |
| Caché por manifiestos | Se agregó temporalmente un comentario al provider y se construyó `devops-phase3-cache-probe:0.0.0-dev`: la instrucción composer install de dependencies quedó CACHED. Se restauró el archivo byte a byte. |
| Arranque sin bind mounts | MySQL saludable → init Exited(0) → app saludable → nginx saludable. PHP/init solo montan passport_keys; Nginx no monta volúmenes. |
| Salud | SQL SELECT 1; conexión TCP local a FPM; wget /up HTTP 200. TCP solo acredita listener y /up no prueba MySQL. |
| PHPUnit SQLite | **67 tests, 2692 assertions**, exit 0. |
| PHPUnit MySQL | **70 tests, 2718 assertions**, exit 0, incluyendo concurrencia con procesos independientes. |
| Pint --test en development | **PASS, 117 archivos**, exit 0. |
| Override opcional de fuentes | OpenApiDocumentationTest: **6 tests, 328 assertions**, exit 0. |
| scripts/verify-docs.mjs por Nginx | /up, /docs, /docs/openapi.json y cuatro assets: HTTP 200, tipos correctos y coincidencia byte a byte con documento y paquete locales. |
| Estáticos | /robots.txt y /favicon.ico: HTTP 200 y contenido idéntico al checkout. |
| Rutas privadas | /.env, /composer.json, /artisan, /vendor/autoload.php, /storage/oauth-private.key, /storage/passport/oauth-private.key y /storage/logs/laravel.log: HTTP 404. |
| Preparación de entorno | APP_KEY de 32 bytes, contraseñas DB independientes, .env modo 600. Segundo intento: exit 1 sin reemplazar el archivo. |
| Repetir init | Exit 0, conservando APP_KEY, hashes de ambas claves, ID del cliente personal y aceptación del token emitido. |
| Par Passport incompleto | Se apartó solo la clave pública en el volumen aislado. Init exit 1 con mensaje de par incompleto; hash de la privada conservado. Se restauró la pública original. |
| APP_KEY vacía | Init ejecutado con override APP_KEY vacío: exit 1 antes de migraciones, sin generar un reemplazo. |
| Recreación | down sin -v y up --wait: marcador MySQL, APP_KEY, ambas claves, cliente y el mismo token de 30 minutos conservados. Se verificó antes de vencer el token con el guard Passport real. Los archivos temporales del verificador se transfirieron en memoria fuera del contenedor; no se agregó persistencia de storage. |
| GIPHY real | scripts/verify-giphy.mjs: login **200**, búsqueda cats **200**, consulta del GIF elegido **200**, favorito **201**. Sin impedimento externo observado. El favorito seguía en MySQL tras las recreaciones finales. |
| Secretos en imágenes | Inspección de config/history y exportación de las tres imágenes: **37 capas únicas y 20923 archivos**. Sin valores conocidos de APP_KEY/DB passwords/GIPHY, claves Passport de la instalación, .env o SQLite locales incorporados. La inspección cubre esos secretos conocidos; no constituye una garantía sobre secretos arbitrarios desconocidos. |

Comandos representativos ejecutados (los logs temporales originales están en `/tmp/devops-phase3-*.log` durante esta sesión):

```bash
docker compose --env-file /tmp/devops-phase3.env -p devops-phase3-validation -f docker-compose.yml build app nginx
docker compose --env-file /tmp/devops-phase3.env -p devops-phase3-validation -f docker-compose.yml -f compose.dev.yaml build app
docker compose --env-file /tmp/devops-phase3.env -p devops-phase3-validation -f docker-compose.yml up -d --wait
node scripts/verify-docs.mjs http://127.0.0.1:18093
node scripts/verify-giphy.mjs http://127.0.0.1:18093

docker compose --env-file /tmp/devops-phase3.env -p devops-phase3-sqlite -f docker-compose.yml -f compose.dev.yaml run --rm --no-deps -e APP_ENV=testing -e LOG_CHANNEL=null -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e SESSION_DRIVER=array app vendor/bin/phpunit --cache-directory=/tmp/phpunit-cache --display-phpunit-notices
docker compose --env-file /tmp/devops-phase3.env -p devops-phase3-sqlite -f docker-compose.yml -f compose.dev.yaml run --rm --no-deps app vendor/bin/pint --test
docker compose --env-file /tmp/devops-phase3.env -p devops-phase3-mysql -f docker-compose.yml -f compose.dev.yaml -f compose.audit-test.yaml run --rm -e LOG_CHANNEL=null app vendor/bin/phpunit -c phpunit.mysql.xml --cache-directory=/tmp/phpunit-cache --display-phpunit-notices
```

La suite MySQL se ejecutó después de levantar su propio mysql saludable con el mismo proyecto y los mismos overrides. El recorrido GIPHY se realizó una vez sobre una base nueva; repetirlo con el mismo GIF puede devolver 409 por el favorito ya creado.

## Tamaños e identificadores

`docker image inspect .Size`, bytes locales sin compresión, **misma plataforma linux/amd64**. Las etiquetas nuevas usan APP_VERSION=0.0.0-dev y VCS_REF=a2cbcc5-dirty; representan el código de esta rama sin commit, no una release publicada.

| Imagen | Bytes | MB decimales |
|---|---:|---:|
| Actual devops-tp-app:latest | 602866967 | 602.87 |
| Nueva PHP runtime | 176719819 | 176.72 |
| Nueva PHP development | 249997344 | 250.00 |
| Nueva Nginx | 62396554 | 62.40 |

El runtime PHP ocupa un 70.7% menos que la imagen PHP anterior. **La comparación no tiene el mismo contenido:** la anterior excluía código/vendor y dependía del bind mount; la nueva incluye aplicación y dependencias de producción. No se midieron rendimiento, tiempo de arranque comparativo ni tamaño total de un despliegue con sus datos. Las capas compartidas tampoco deben sumarse como si fueran almacenamiento independiente.

Identificadores de configuración locales, no digests de manifiestos publicados (RepoDigests vacío):

```text
anterior:    sha256:f04172b8733c9faa198d9915be09f10792abf9178cd3cde7b7084fbecc50ec92
runtime:     sha256:5864b111e6cafb0a54242c755716dfc4c5269915e00dba211bfded9bbb2ee5da
development: sha256:635e55c210b766ad835fd4a89554e5e18416870ab5c9f354516e83afd57c2471
nginx:       sha256:cfa7774431a4b4e751a2b1193c38dd89ca8f26d514a27ad757b9fa7268b75517
```

Bases fijadas, digests de los índices usados en FROM/Compose:

```text
php:8.4.26-fpm-alpine3.24
sha256:78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a
composer:2.10.3
sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac
nginx:1.30.5-alpine
sha256:0985e772fb9f729e6fa0980da05fca5d9c468e870eed43071545afa9d2e27d94
mysql:8.4.11
sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242
```

PHP Alpine usado en amd64: manifiesto `sha256:a8091f6d2623b0b23a78a81c71b10e71545e72ba37176030445f347cd5b1bdee`, revisión oficial `79320e28b93fdc720d6f07d150709310cf54e757`, Alpine 3.24. La imagen anterior provenía de php:8.4.26-fpm-bookworm, índice `sha256:6bfef8e416977aa41f48e3e42a40c1e08050d24e4a938c6edb421400bff24601`.

## Problemas encontrados y ajustes al plan

- Inicialmente Nginx quedó solo en la red interna: sus checks internos pasaban pero el puerto no era accesible desde el host. Se restauró la conexión a la red no interna y se repitieron con éxito las pruebas HTTP.
- Las pruebas heredadas dependían de claves del checkout al resolver guards, incluso al usar actingAs. Se incorporó una ayuda de claves efímeras para la suite y sus workers; nunca se copiaron claves reales a las imágenes de pruebas.
- La primera ejecución SQLite heredó APP_ENV=local del servicio, prevaleciendo sobre la configuración PHPUnit, y falló la comprobación de entorno sin seeding. El comando de prueba ahora pasa APP_ENV=testing explícitamente. Las ejecuciones finales completas indicadas arriba pasaron.
- Se reclasificó Tinker como herramienta de desarrollo y se retiraron del filesystem runtime herramientas heredadas de la base. Esto amplía la separación de dependencias sin actualizar versiones de paquetes.
- El montaje opcional de desarrollo usa directorios de fuentes, en vez de montar todo el checkout. Permite trabajar sin volúmenes persistentes de vendor/storage/cache ni ajustes recursivos de permisos.
- FPM avisa que ignora user/group del pool al ejecutarse sin root; UID/GID del proceso ya es 82. No se instalaron clientes FastCGI para salud. El alcance de ping.path se comprobó en el [manual oficial de FPM](https://www.php.net/manual/en/install.fpm.configuration.php).

No se migró el entorno existente ni se publicaron imágenes. No se ejecutaron nuevas pruebas manuales de navegador/Postman, benchmarks, CI/CD o restauraciones desde backups externos; los resultados anteriores corresponden a los comandos y probes efectivamente ejecutados.

El diff de entrega previo a los commits incluyó los archivos nuevos y se verificó con git apply --check contra una exportación limpia de a2cbcc5. Tras la aprobación técnica se corrigió la guía de requisitos, se documentó la regeneración OpenAPI con un ID de contenedor asignado por Compose y se agregó una nota sobre variables efímeras de prueba para la futura CI. Estos ajustes finales afectan solo a documentación; no cambian la arquitectura Docker ni los resultados de ejecución registrados arriba.

# Validación de fase 1: OpenAPI y Swagger UI

Verificaciones automatizadas y cierre documental registrados el 22 de octubre de 2026 en `feat/openapi-documentation`, basada en `9d89ca4`. La fase 1 queda validada para revisión mediante Pull Request a `main`; su integración requiere una revisión posterior. Las cuatro operaciones, sus reglas y sus respuestas conservan el código existente; los controladores solo agregan imports y atributos de documentación.

## Implementación y dependencias

Swagger UI está en `/docs`; el documento OpenAPI 3.0.0 se sirve en `/docs/openapi.json` desde el archivo versionado `docs/openapi/openapi.json`. Los assets se sirven mediante las rutas del paquete bajo `/docs/openapi.json/asset/`. El servidor OpenAPI es `/`, relativo al mismo origen. Login es público y las otras tres operaciones declaran HTTP Bearer sin `bearerFormat`. No se registra un callback OAuth. La vista usa los assets del paquete, desactiva el validador remoto y la persistencia de autorización. La regeneración es explícita, con `composer docs:generate`.

Fuentes editables: atributos en los cuatro controladores de `app/Infrastructure/Http/` y componentes compartidos en `app/Infrastructure/OpenApi/`. Se documentan parámetros, JSON, respuestas, errores y headers del código, incluidos `X-Request-ID`, `Cache-Control` del login, límites de intentos y `Retry-After`. Los ejemplos son sintéticos; password y access_token no tienen ejemplos ni valores por defecto.

Primero se ejecutó el dry-run y después la misma instalación limitada:

```bash
docker compose build app
docker compose run --rm --no-deps app composer require 'darkaonline/l5-swagger:^11.2' --dry-run --no-interaction --minimal-changes
docker compose run --rm --no-deps app composer require 'darkaonline/l5-swagger:^11.2' --no-interaction --minimal-changes
```

El lock agregó siete paquetes, sin actualizar ni eliminar ninguno de los 119 paquetes existentes. Laravel sigue en `v13.35.0` y Passport en `v13.9.0`. Composer no informó vulnerabilidades en esa instalación.

Archivos de la entrega:

| Archivo | Cambio |
|---|---|
| `composer.json` | Dependencia de runtime y comando `docs:generate`. |
| `composer.lock` | Siete dependencias nuevas fijadas. |
| `config/l5-swagger.php` | Rutas, directorios y generación explícita. |
| `resources/views/vendor/l5-swagger/index.blade.php` | Vista Swagger con assets locales y opciones de autorización. |
| `app/Infrastructure/Http/LoginController.php` | Atributos del login público. |
| `app/Infrastructure/Http/SearchGifsController.php` | Atributos de búsqueda y paginación. |
| `app/Infrastructure/Http/GetGifByIdController.php` | Atributos de consulta por ID. |
| `app/Infrastructure/Http/SaveFavoriteGifController.php` | Atributos de creación de favorito. |
| `app/Infrastructure/OpenApi/Specification.php` | Metadatos, servidor, tags, Bearer y headers compartidos. |
| `app/Infrastructure/OpenApi/Schemas.php` | Esquemas de entradas, salidas y errores. |
| `app/Infrastructure/OpenApi/Responses.php` | Respuestas de error compartidas. |
| `docs/openapi/openapi.json` | Documento generado y versionado. |
| `tests/Feature/OpenApiDocumentationTest.php` | Seis pruebas de publicación, validez y cobertura. |
| `scripts/verify-docs.mjs` | Verificación HTTP por Nginx. |
| `README.md` | Acceso, recorrido interactivo, generación y validación. |
| `docs/openapi/validation.md` | Resultados, comandos, fallos corregidos y límites. |

| Paquete agregado | Versión resuelta |
|---|---|
| `darkaonline/l5-swagger` | `11.2.1` |
| `zircote/swagger-php` | `6.18.0` |
| `swagger-api/swagger-ui` | `v5.34.0` |
| `phpstan/phpdoc-parser` | `2.3.6` |
| `radebatz/type-info-extras` | `1.0.10` |
| `symfony/type-info` | `v8.1.8` |
| `symfony/yaml` | `v8.1.9` |

## Resultados aprobados

| Verificación | Resultado |
|---|---|
| Resolución Composer | Dry-run e instalación: 7 altas, 0 actualizaciones, 0 eliminaciones. Comparación de versiones con el lock base aprobada. |
| Manifiesto y plataforma | `composer validate --strict` y `composer check-platform-reqs` aprobados en PHP 8.4.26. |
| Generación y validez | Generación L5-Swagger en un directorio temporal; swagger-php valida sin advertencias ni errores; resultado idéntico byte por byte al versionado. Todas las referencias internas resuelven. |
| Redocly CLI | Versión fijada `1.34.5`, configuración recomendada incorporada: documento válido, sin advertencias. |
| Correspondencia | Cuatro operaciones coinciden con métodos y rutas de `/api/v1`, quitando HEAD. Se comprueban operationId únicos, login público y Bearer en las tres restantes. Salud, documentación y rutas auxiliares quedan fuera de esta comparación. |
| Unit y Feature, SQLite | **66 pruebas, 2687 aserciones**, aprobadas. Incluyen las seis pruebas nuevas de documentación y las pruebas existentes de contratos, tokens inválidos, validación y fallos GIPHY. |
| Unit, Feature e integración MySQL | **69 pruebas, 2713 aserciones**, aprobadas. Proyecto Compose separado `prex-openapi-mysql`, con base `prex_audit_test`; incluye rollback y concurrencia. |
| Pint | **116 archivos**, aprobado. |
| Compose | Configuración válida; `up -d --build --wait` aprobado. `init` terminó con código 0; `app` disponible, MySQL y Nginx saludables. |
| Publicación por Nginx | `/up`, `/docs`, `/docs/openapi.json` y los cuatro assets referenciados respondieron **200** con tipos HTML, JSON, CSS, PNG y JavaScript correctos. JSON idéntico al versionado y assets idénticos al paquete. |
| Chrome, verificación inicial del agente | Cuatro operaciones renderizadas, login interactivo **200**, diálogo Authorize operativo y header Bearer enviado por las tres operaciones protegidas. En esa ejecución todavía no había clave GIPHY y las tres respondieron **503** con el error previsto. |
| Navegador y autorización | Tras recargar, la autorización queda vacía. `persistAuthorization=false`, `validatorUrl=null`, sin errores JavaScript ni solicitudes a otros orígenes durante el recorrido. |
| GIPHY real, prueba manual del responsable del proyecto | Después de configurar una clave válida, el responsable del proyecto informó que verificó exitosamente búsqueda, consulta y creación de favoritos desde Swagger UI. El agente no reejecutó ese recorrido; ver el registro de cierre. |

La publicación HTTP fue comprobada tanto por `OpenApiDocumentationTest` dentro de Laravel como por `scripts/verify-docs.mjs` a través de Nginx. Otra prueba solicita un documento inexistente en una ubicación temporal y verifica que responde 404 sin generarlo.

## Comandos de verificación

Desde la raíz del repositorio, con `.env` preparado:

```bash
docker compose config --quiet
docker compose up -d --build --wait
docker compose ps -a
docker compose run --rm --no-deps app composer docs:generate
docker compose run --rm --no-deps -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: app vendor/bin/phpunit --display-phpunit-notices
docker compose run --rm --no-deps app vendor/bin/pint --test
docker compose run --rm --no-deps app composer validate --strict
docker compose run --rm --no-deps app composer check-platform-reqs
npm exec --yes --cache /tmp/devops-openapi-npm --package=@redocly/cli@1.34.5 -- redocly lint docs/openapi/openapi.json
node scripts/verify-docs.mjs http://127.0.0.1:18080
```

Esta instalación ya tenía `HTTP_PORT=18080`, `APP_URL=http://127.0.0.1:18080` y `DB_FORWARD_PORT=3309`; se conservaron. En otra instalación usar el puerto de su `.env`. El verificador HTTP requiere Node.js; se utilizó Node.js 22.22.3.

La integración usa un proyecto distinto; no aplicar el override al proyecto principal:

```bash
docker compose -p prex-openapi-mysql -f compose.yaml -f compose.audit-test.yaml up -d --wait mysql
docker compose -p prex-openapi-mysql -f compose.yaml -f compose.audit-test.yaml run --rm app vendor/bin/phpunit -c phpunit.mysql.xml --display-phpunit-notices
docker compose -p prex-openapi-mysql -f compose.yaml -f compose.audit-test.yaml down
```

El recorrido del navegador se automatizó con Playwright `1.56.1`, instalado temporalmente en `/tmp`, y Google Chrome del host, sin agregar dependencias JavaScript a la aplicación. Se siguió login → copiar access_token → Authorize → búsqueda → consulta → favorito → recarga, enviando solicitudes reales al Nginx local. Las credenciales y tokens usados no se incorporan a esta evidencia. La guía para repetirlo está en el apartado Swagger del README.

## Fallos observados y correcciones

| Observación inicial | Causa y acción | Repetición |
|---|---|---|
| Host con PHP 8.2, sin Composer ni vendor | Se construyó la imagen PHP 8.4.26 existente y se instalaron las dependencias mediante Composer en Docker. | Plataforma y suites aprobadas. |
| Docker: socket sin permiso y escritura de buildx bloqueada | Restricciones del sandbox. Se repitió con ejecución autorizada fuera de él. | Build y arranque aprobados. |
| Primera generación: `Undefined array key "excludes"` | La configuración abreviada omitía claves obligatorias de L5-Swagger. Se completaron `paths.excludes`, `paths.base`, `paths.views` y `operations_sort`. | Generación aprobada, incluida la reproducción temporal automatizada. |
| npm: `ENOTFOUND registry.npmjs.org` | DNS de red restringido por el sandbox. Se repitió la descarga autorizada fuera de él. | Redocly y Playwright instalados. |
| HTTP local: `EPERM` | Conexión local bloqueada por el sandbox. Se repitió con autorización fuera de él. | Publicación por Nginx aprobada. |
| Verificador HTTP: `/up` 404 en puerto 8080 | Se había usado el puerto del ejemplo, distinto al 18080 del `.env` existente. No se cambió la configuración local; se pasó la URL correcta al verificador. | Siete recursos comprobados con 200. |
| Prueba de asset: `text/css; charset=utf-8` frente a `text/css` | Laravel agrega el charset a la respuesta CSS. Se corrigió la prueba para comparar el tipo de medio. | Unit/Feature e integración aprobadas. |
| Redocly: advertencia por licencia sin URL | Se agregó la URL de la licencia MIT al atributo Info y se regeneró el JSON. | Documento válido sin advertencias. |
| Redocly rechazó `--max-warnings=0` | Opción no admitida por la versión fijada. Se quitó esa opción y se revisó la salida completa. | Lint sin errores ni advertencias. |
| Compose aislado intentó descargar una imagen inexistente | El nombre de imagen depende del proyecto. Compose continuó construyéndola desde el Dockerfile local. | Pruebas MySQL aprobadas. |
| Playwright agotó el tiempo al confirmar Authorize | El nombre accesible del botón es `Apply credentials`, aunque su texto visible sea Authorize. Se corrigió el selector. | Recorrido completo aprobado. |

No quedan verificaciones locales fallidas. Las contraseñas MySQL existentes se conservaron; no fue necesario reemplazarlas ni imprimirlas. Vendor, `.env`, claves Passport, cachés y herramientas temporales no se versionan.

## Prueba manual con GIPHY real y cierre de fase 1

El 22 de octubre de 2026, el responsable del proyecto informó que configuró una API key válida de GIPHY y comprobó el funcionamiento correcto desde Swagger UI, con resultado exitoso en estas operaciones:

| Operación | Resultado manual informado |
|---|---|
| Búsqueda, `GET /api/v1/gifs` | Exitosa utilizando GIPHY real. |
| Consulta, `GET /api/v1/gifs/{id}` | Exitosa utilizando GIPHY real. |
| Creación de favorito, `POST /api/v1/favorites` | Exitosa utilizando GIPHY real. |

La fuente de esta evidencia es la confirmación del responsable del proyecto en la conversación de cierre. El agente registra esa confirmación y no afirma haber ejecutado nuevamente las solicitudes contra GIPHY. No se recibieron capturas ni salidas HTTP de ese recorrido; no se atribuyen códigos, IDs ni cuerpos de respuesta no observados. Este resultado levanta el bloqueo de la verificación inicial sin reemplazar su registro histórico de respuestas 503.

Las pruebas automatizadas listadas arriba se ejecutaron durante la implementación y no se reejecutaron en este cierre, que solo modifica documentación. Las suites mantienen las simulaciones de GIPHY y el bloqueo de HTTP externo. Con esas verificaciones y la prueba manual informada, no quedan verificaciones pendientes de la fase 1; la revisión e integración del Pull Request a `main` siguen pendientes.

## Comprobación de secretos antes de publicar

Al iniciar el cierre se verificó que la rama era `feat/openapi-documentation` y el árbol de trabajo estaba limpio. Se comprobó que `.env` está ignorado y no versionado, y se buscó el valor de la clave GIPHY configurada localmente, sin mostrarlo, en los 171 archivos versionados y en todos los objetos locales de Git: 173 blobs, 81 árboles y 11 commits, incluidos objetos sin referencia. No hubo coincidencias.

Esta comprobación se limita al valor de la clave configurada y al contenido disponible en el repositorio local; no es una auditoría general de otros secretos. La clave no se incorpora a la documentación ni al texto del PR.

Esta fase no modifica CI/CD, empaquetado de producción ni observabilidad. El cierre se entrega mediante publicación de la rama y apertura de un Pull Request; el merge no forma parte de esta entrega.

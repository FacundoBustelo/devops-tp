# Fase 4: CI y publicación versionada

Implementación y validaciones locales del 22 de octubre de 2026 en `feat/github-actions-cicd`, creada desde `origin/main` actualizado, commit `fd42ffd4d27b150e3027fec140551d9be5d7acb7`. Se comprobó que `7abac05` (cierre de fase 3) es ancestro de esa base; `fd42ffd` integra el PR #3. El checkout estaba limpio antes de crear la rama. No se realizaron commits, push, PR, tags, despliegues ni publicaciones reales en Docker Hub.

## Archivos y comportamiento

| Archivo | Función |
|---|---|
| `.github/workflows/ci.yml` | PR hacia main y workflow reutilizable; calidad, matriz SQLite/MySQL y check final CI Required. |
| `.github/workflows/release.yml` | Validación de tag e historial de main, CI previa, construcción/prueba y publicación serializada. |
| `scripts/ci/common.sh` | Entorno efímero, nombres exclusivos, puertos loopback aleatorios y limpieza mediante trap. |
| `scripts/ci/check.sh` | Jobs reproducibles de calidad, SQLite e integración MySQL con development y Compose existentes. |
| `scripts/ci/validate-tag.sh` | Validación estricta de SemVer estable con prefijo v. |
| `scripts/ci/check-hub-version.py` | Consulta anónima: ambos repositorios deben existir, ser públicos y carecer del tag solicitado. Errores de red, autenticación y rate limit fallan sin publicar. |
| `scripts/ci/release-images.sh` | Buildx con load, prueba Compose sin build/pull de aplicación y registro de identidad/etiquetas/plataforma. |
| `scripts/ci/push-images.py` | Comprueba identidad, ejecuta únicamente push y registra digests, incluidos los de una publicación parcial fallida. |
| `tests/ci/test_release.py` | Pruebas con HTTP y Docker simulados para las protecciones del publicador; no publican. |
| `README.md` | Configuración, operación, limitaciones y activación posterior de Stop-the-Line. |
| `docs/cicd/validation.md` | Evidencia de implementación, validaciones locales y verificaciones pendientes de fase 4. |

Se conservaron Dockerfile, Compose, configuraciones PHPUnit, dependencias, código y contratos de API. Las pruebas HTTP reutilizan `scripts/verify-docs.mjs`, copiando temporalmente los assets desde el runtime probado; no requieren vendor en el host. El único pull explícito del arranque final es MySQL, fijado por digest; las imágenes de aplicación se cargan antes y Compose usa `--no-build --pull never`.

Cada job genera sus propias credenciales de Compose con Python secrets y no carga `.env`. Las pruebas no consultan GIPHY real. Se usan proyectos exclusivos `devops-ci-*`; al terminar se eliminan contenedores, redes, volúmenes MySQL/Passport y entorno temporal. Las imágenes y la caché de build permanecen locales para inspección. La configuración habitual del proyecto y sus volúmenes no se usan.

El preflight usa las rutas actuales `/v2/namespaces/{namespace}/repositories/{repository}` y `/tags/{tag}` del [API oficial de Docker Hub](https://docs.docker.com/reference/api/hub/latest/operations/GetRepository/). Se comprobó anónimamente el repositorio oficial `library/nginx`: la respuesta indica `is_private=false`, `latest` existe y un tag de prueba inexistente devuelve 404. Esto verifica el acceso al API público, sin validar todavía los repositorios o permisos del responsable del proyecto. La protección frente a escritores externos requiere configurar [inmutabilidad de tags](https://docs.docker.com/docker-hub/repos/manage/hub-images/immutable-tags/) en el registro.

## Actions y análisis estático

Todos los usos externos apuntan a `actions/checkout@34e114876b0b11c390a56381ad16ebd13914f8d5`, verificado contra `refs/tags/v4.3.1` mediante `git ls-remote` en el repositorio oficial. Todos llevan `persist-credentials: false`. Ambos workflows declaran `contents: read`; no hay `pull_request_target` ni herencia de secretos. La llamada local `./.github/workflows/ci.yml` usa el mismo commit del workflow invocante, como documenta [GitHub](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows).

Se descargó actionlint **1.7.12** a `/tmp`, se verificó el SHA256 del archivo Linux amd64 contra el listado de la [release oficial](https://github.com/rhysd/actionlint/releases/tag/v1.7.12): `8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8`. Ambos workflows pasan actionlint. También se comprobaron carga YAML, SHA completo en referencias externas, sintaxis Bash y Python y `git diff --check`. ShellCheck no está instalado; no se atribuye su validación a actionlint.

## Resultados locales

| Comprobación | Resultado |
|---|---|
| Calidad | Pint aprobado: 117 archivos PHP. OpenAPI aprobado: 6 pruebas, 328 aserciones. |
| Protecciones de publicación | 7 pruebas Python aprobadas: SemVer; repositorios públicos y versiones libres; rechazo de versión existente/privacidad; errores HTTP; identidad alterada; push de imágenes probadas y digests; fallo parcial conservando el primer digest. |
| PHPUnit SQLite | 67 pruebas, 2692 aserciones aprobadas con `phpunit.xml`. |
| PHPUnit MySQL | 70 pruebas, 2718 aserciones aprobadas con `phpunit.mysql.xml`; incluye concurrencia, rollback e inicialización. |
| Construcción final | Targets runtime y nginx cargados localmente con Buildx para linux/amd64. |
| Inicialización y salud | MySQL, app y Nginx saludables; init terminó con código 0; migraciones y Passport preparados en volúmenes nuevos. |
| Acceso runtime | MySQL 8.4.11, cuenta `prex_ci@%`, base `prex_ci`; comprobación de escritura en directorios runtime aprobada. |
| HTTP real por Nginx | 200 en `/up`, `/docs`, `/docs/openapi.json` y los cuatro assets CSS/PNG/JS, con tipos correctos. JSON idéntico al versionado y assets idénticos al paquete del runtime probado. |
| Metadatos | Ambas imágenes: versión 0.0.0, revisión fd42ffd4d27b150e3027fec140551d9be5d7acb7 y linux/amd64 verificadas por inspect. |
| Publicación sin reconstruir | Revisión del flujo y prueba simulada: solo dos llamadas docker push sobre imágenes cuyo ID coincide; ningún build en el publicador. Push real pendiente. |
| Check final | Se probaron las 16 combinaciones de success/failure/cancelled/skipped para calidad y matriz sobre el comando real del gate; solo success/success devuelve 0. `always()` y ambas dependencias verificados en el YAML. |

La revisión final abarcó los 11 archivos modificados/nuevos. Se buscaron en ellos dos valores sensibles configurados localmente de al menos ocho caracteres, sin imprimirlos, y patrones de claves privadas/tokens; no hubo coincidencias. Es una comprobación acotada, no una auditoría general de secretos. No quedaron contenedores, volúmenes ni redes de los proyectos `devops-ci-*`; los servicios habituales continuaron saludables. El diff completo, incluyendo archivos nuevos, se guardó en `/tmp/devops-phase4-full.diff` y pasó `git apply --check --whitespace=error-all` contra una exportación limpia de HEAD, sin modificar el índice Git.

Imágenes locales de validación (namespace sintético, sin tag Git ni release publicada):

```text
devops-phase4-validation/devops-tp-php:0.0.0
sha256:ba270e8eb7cf24c18446d6909bc071a0e058851396422de5d084d8792bb3ca73

devops-phase4-validation/devops-tp-nginx:0.0.0
sha256:b70e1701d4a21660304bff5bc9b50179f4d5fe67f57b283936fd5dcfd6645c6f
```

Estos son identificadores de configuración **locales**, no digests de manifiestos publicados. La etiqueta OCI de revisión registra HEAD de la base; la validación se hizo con cambios de fase 4 aún sin commit. En una ejecución de release en GitHub el checkout corresponde al commit etiquetado. No se asignan digests Docker Hub ni se afirma que estas imágenes estén publicadas.

## Reproducción

Requisitos locales: Docker Engine, Buildx, Compose con `!reset`/`!override` y `--wait`, Bash, Python 3 y Node.js 22 o superior para HTTP. En esta ejecución se usó Docker Compose 5.6.0, PHP 8.4.26 y Node.js 22.22.3; los jobs usan runners `ubuntu-24.04` con Docker y sus herramientas incluidas. No se instaló tooling en las imágenes runtime.

```bash
bash scripts/ci/check.sh quality
bash scripts/ci/check.sh sqlite
bash scripts/ci/check.sh mysql
python3 -B -m unittest discover -s tests/ci -v
bash -n scripts/ci/*.sh
actionlint

# Solo construir y probar: este script no autentica ni publica.
RELEASE_TAG=v0.0.0 DOCKERHUB_NAMESPACE=devops-phase4-validation \
  RELEASE_METADATA=/tmp/devops-phase4-images.json \
  bash scripts/ci/release-images.sh
```

Los jobs locales se ejecutaron con estos mismos scripts; los logs temporales de la sesión quedaron en `/tmp/devops-phase4-quality.log`, `/tmp/devops-phase4-sqlite.log`, `/tmp/devops-phase4-mysql-final.log` y `/tmp/devops-phase4-images.log`. Son evidencia de esta máquina, no artefactos de Actions y no se versionan.

## Fallos y correcciones

- El sandbox impedía escribir `.git/FETCH_HEAD`, crear referencias y usar el socket Docker; la red tampoco resolvía GitHub. Se ejecutaron fetch, creación de rama, consultas de pins, descarga de actionlint y pruebas Docker con autorización fuera del sandbox.
- actionlint detectó que el contexto `runner.temp` no está permitido en `jobs.<job>.env`. Se movió la preparación de rutas a un paso que escribe en `GITHUB_ENV`; la validación final pasó.
- La primera suite MySQL pasó sus 70 pruebas, pero se modificó `check.sh` mientras Bash aún lo ejecutaba para agregar las pruebas del publicador. El desplazamiento de lectura provocó un comando truncado al volver de PHPUnit y el script terminó con 127. Se repitió el job completo con el script final sin modificaciones concurrentes. No fue un fallo de la aplicación.

## Configuración y verificaciones pendientes

Después de aprobar e integrar el código:

1. Crear los dos repositorios públicos en Docker Hub; configurar `DOCKERHUB_NAMESPACE`, `DOCKERHUB_USERNAME` y `DOCKERHUB_TOKEN` en GitHub. Reservar la escritura a este flujo y habilitar tags inmutables en Docker Hub para impedir sobrescrituras por escritores externos. Las comprobaciones previas no constituyen una operación atómica de creación.
2. Ejecutar CI real en un PR y comprobar sus jobs y el check `CI Required`. Configurarlo como requisito de merge en el ruleset de main, con Pull Request, actualización de base y sin bypass; verificar bloqueo con una validación fallida y habilitación al corregirla. **Stop-the-Line aún no se declara efectivo.**
3. Autorizar una publicación real; crear/publicar un tag estable de un commit del historial de main y comprobar que el workflow valida ascendencia y ejecuta CI antes de publicar. El disparador acepta `v*` para que los tags de formato incorrecto fallen explícitamente en la validación.
4. Comprobar en Actions y Docker Hub ambas referencias exactas, su visibilidad pública, SHA/versión OCI, arquitectura y digests. Verificar descarga por digest desde un cliente sin credenciales. No se ejecutó login, push ni consulta de repositorios personales durante la implementación.
5. Conservar el resumen de Actions y los logs: contienen versión, revisión completa, IDs probados y digests publicados. `release-images.json` es temporal, se imprime en logs y no se sube como artefacto independiente. Una publicación parcial es fallida; no reintentar ni reutilizar esa versión, aunque falte uno de los tags.

No se probaron runners GitHub, credenciales, reglas de merge, inmutabilidad, rate limits ni una publicación real. La serialización evita solapar estos workflows, pero la publicación de los dos repositorios sigue sin ser transaccional. GitHub puede reemplazar runs pendientes de un mismo grupo de concurrencia; cada release requiere verificar su resultado antes de crear la siguiente. No hay rollback automático ni despliegue.

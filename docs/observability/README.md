# Fase 5 · Monitoreo y observabilidad

Los eventos HTTP JSON están activos con el Compose principal. Prometheus, Grafana y el almacenamiento de métricas son opcionales. MySQL conserva negocio y auditoría; SQLite almacena únicamente agregados operativos locales. No se instala APCu ni se agrega Redis, Alertmanager u otro servicio.

```mermaid
flowchart LR
  cliente -->|8080 público| nginx
  nginx --> FPM[Laravel / PHP-FPM]
  FPM --> MySQL[Negocio + auditoría]
  FPM --> GIPHY
  FPM --> SQLite[/tmp/devops-observability/metrics.sqlite]
  FPM --> JSON[stderr JSON]
  Prometheus -->|nginx:9091/metrics| nginx
  Grafana --> Prometheus
  navegador -->|127.0.0.1:3000| Grafana
```

## Uso

El Compose principal conserva sus comandos de README y no requiere contraseña de Grafana. Para habilitar monitoreo en ese proyecto, generar una contraseña fuera del repositorio y entregarla como variable de entorno (no guardar credenciales en comandos versionados):

```bash
# En una terminal local; la contraseña no se imprime ni se guarda en .env.
export GRAFANA_ADMIN_PASSWORD="$(openssl rand -hex 32)"
docker compose -f docker-compose.yml -f compose.monitoring.yaml up -d --build --wait
```

Ingresar en `http://127.0.0.1:3000` como `admin` con esa contraseña. Para conservarla entre terminales, usar un gestor de secretos o archivo privado fuera del repositorio. Grafana la utiliza al inicializar su volumen: cambiar la variable después no cambia la contraseña de un usuario existente. No borrar el volumen para cambiar una contraseña.

Para una validación o demostración, usar siempre un proyecto exclusivo con credenciales, etiquetas y volúmenes propios. Los puertos son efímeros:

```bash
python3 scripts/observability/prepare-env.py /tmp/devops-observability.env --giphy-from .env
# El archivo nuevo tiene modo 0600; de .env solo se copia la clave GIPHY.
docker compose --env-file /tmp/devops-observability.env \
  -f docker-compose.yml -f compose.monitoring.yaml up -d --build --wait
# Consultar puertos asignados sin imprimir credenciales:
docker compose --env-file /tmp/devops-observability.env \
  -f docker-compose.yml -f compose.monitoring.yaml port grafana 3000
```

`prepare-env.py` nunca reemplaza archivos, exige un destino fuera del checkout y nombres `devops-obs-*`. La demostración requiere una clave GIPHY válida y el usuario local que `init` crea en esta base nueva. Nunca ejecutar los overrides de pruebas/demostración contra el proyecto habitual.

Para deshabilitar monitoreo, volver a iniciar con solo el Compose principal y `--remove-orphans`; `app` se recreará con métricas deshabilitadas. No usar `down -v` sobre instalaciones con datos que se quieran conservar. Los volúmenes Prometheus/Grafana permanecen hasta su eliminación explícita.

## Logs y correlación

`AuditHttp` conserva la generación del UUID, la persistencia y las transacciones existentes. Solo añade el reloj monotónico inicial. `RecordHttpRequest`, listener síncrono de `RequestHandled`, observa la respuesta final y usa el `AuditContext` de esa solicitud. No hay contexto estático, `shareContext()` ni auditoría adicional.

El evento `http_request_completed` contiene `event`, `request_id`, `method`, `route`, `status` y `duration_ms`, además del envelope JSON de Monolog con timestamp UTC y nivel. Nivel info para 2xx/3xx, warning para 4xx y error para 5xx. Duración: hasta obtener la respuesta, incluida auditoría; no mide el envío completo de streams al cliente.

Las rutas son nombres registrados o `api.unmatched`. Se normalizan métodos a GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS/OTHER. Nunca se registran cuerpos, parámetros, URLs, IDs de GIF, emails, IP, tokens, cookies o headers. Los UUID solo aparecen en logs y auditoría, nunca en etiquetas de métricas.

`application_exception` registra únicamente el tipo de excepción y, si existe, el UUID de auditoría. El callback detiene el reporte Laravel predeterminado: no serializa mensajes, SQL, bindings o trazas. Se mantienen las exclusiones de errores de dominio. `SafeEvents` construye su writer JSON directamente con la configuración del canal `http`: evita que el logger de emergencia de Laravel vuelva a serializar una excepción. Si falla, emite solo `observability_logging_failed`. Los servicios se registran con alcance de solicitud.

El fallback SQL de auditoría sigue emitiendo `api_audit_persistence_failed` con metadatos seguros. Si SQL está caído, puede faltar la fila durable; no se interpreta el fallback como persistencia exitosa.

El access log Nginx registra solo fuente, estado y duración; omite dirección IP, URI y query string, también para rechazos anteriores a Laravel. No se habilita access log FPM en producción; el override de pruebas registra exclusivamente PID y estado en `/tmp` para acreditar concurrencia.

### Errores nativos de Nginx: privacidad y diagnóstico

La imagen fijada es `nginx:1.30.5-alpine@sha256:0985e772fb9f729e6fa0980da05fca5d9c468e870eed43071545afa9d2e27d94`; se verificó `nginx -V` en el contenedor. En esa versión OSS, `error_log` admite destino y severidad, sin plantilla ni redacción de campos. El [código de logging](https://github.com/nginx/nginx/blob/release-1.30.5/src/core/ngx_log.c) y el [handler HTTP](https://github.com/nginx/nginx/blob/release-1.30.5/src/http/ngx_http_request.c) muestran que se añade IP y línea de solicitud completa al mensaje. El [rechazo por tamaño](https://github.com/nginx/nginx/blob/release-1.30.5/src/http/ngx_http_core_module.c) emite un error antes de ejecutar Laravel: `Content-Length: 2097152` supera el límite predeterminado de 1 MiB.

Alternativas evaluadas:

- [`log_format` y `escape=json`](https://nginx.org/en/docs/http/ngx_http_log_module.html#log_format) controlan el access log; escapar caracteres no elimina secretos ni afecta al error nativo.
- Elevar la severidad a `crit`/`emerg` oculta este 413 de nivel `error`, pero no garantiza que otros errores con contexto sensible no se registren.
- Archivo, syslog y buffer de memoria cambian el destino, no el contenido. Un filtro externo recibe primero el mensaje sensible y añade otro proceso, fallos posibles y reglas de redacción difíciles de garantizar. Modificar/compilar Nginx o añadir módulos excede esta corrección.
- La [documentación actual de `error_log`](https://nginx.org/en/docs/ngx_core_module.html#error_log) menciona JSON comercial: no está en el parser OSS 1.30.5 utilizado y su formato conserva campos de cliente y solicitud; no es una garantía de sanitización.

Se configura **`error_log /dev/null` exclusivamente en `http`**, heredado por los listeners 8080 y 9091 y sus locations, incluidas solicitudes malformadas o rechazadas antes de PHP. Se mantiene **`error_log /dev/stderr warn` en el contexto principal** para diagnóstico del proceso, inicio/configuración y supervisión de workers. No se almacenan copias de los errores HTTP nativos. Siguen activos el access log JSON seguro (incluido 413), eventos y excepciones seguros de Laravel, healthchecks, métricas y alertas.

Límite deliberado: se pierde el texto nativo de errores HTTP/FastCGI, timeouts y fallos de conexión asociados a solicitudes. El estado y duración del access log ayudan a detectar fallos, pero no sustituyen ese detalle ni aportan UUID para rechazos previos a Laravel. Las métricas Laravel no cuentan esos rechazos. Para diagnóstico adicional usar `nginx -t`, salud y eventos seguros; cualquier activación temporal de errores HTTP requiere un entorno aislado sin datos reales. Esta política no sanitiza logs ajenos (Docker/host/FPM) ni protege frente a un administrador del daemon. No añadir overrides `error_log` en servers/locations sin revisar esta frontera.

## Métricas SQLite

| Métrica | Tipo | Etiquetas |
|---|---|---|
| `devops_http_requests_total` | Counter | method, route, status |
| `devops_http_request_duration_seconds` | Histogram | method, route |

Buckets (segundos): `0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, +Inf`. Solo se instrumenta el alcance `/api` existente; salud, documentación, assets y scrapes quedan fuera. Los errores se derivan del estado del contador.

PromPHP 2.15.1 utiliza su adaptador PDO SQLite oficial y una conexión independiente de Laravel DB. El entrypoint inicializa `/tmp/devops-observability/metrics.sqlite` antes de FPM cuando `METRICS_ENABLED=true`. Directorio 0700 y archivo 0600, propiedad del usuario PHP, sin volumen ni migraciones de negocio. Los contadores esperados se inicializan en cero; no se insertan observaciones falsas en el histograma.

WAL, `synchronous=NORMAL`, `busy_timeout=50 ms`, sin reintentos de aplicación. Cada observación adquiere `BEGIN IMMEDIATE` y actualiza contador, suma y bucket dentro de la misma transacción. Cada scrape utiliza una transacción de lectura coherente. NORMAL reduce la espera por fsync en las escrituras de estos agregados temporales; una caída del host puede perder sus últimas transacciones, aunque SQLite preserve la consistencia. No cambia la durabilidad de MySQL.

Ante un bloqueo/error se conserva la respuesta API, se revierte la transacción y se emite `metrics_recording_failed`. Una colección fallida devuelve 503 y `metrics_collection_failed`, nunca una colección vacía válida. Los 50 ms acotan la espera SQLite por lock, no garantizan un límite absoluto de E/S. Son métricas de mejor esfuerzo, no contabilidad exacta bajo fallos de instrumentación.

Reiniciar FPM o el mismo contenedor conserva el archivo. Recrear `app` lo elimina; los counters se reinician sin perder auditoría ni Passport. Prometheus conserva muestras históricas. Usar `rate()`/`increase()` que admiten resets. Una réplica adicional requeriría otro archivo y otro target: queda fuera de este diseño.

## Endpoint y redes

El listener público bloquea `/metrics` y su prefijo normalizado, y los caminos `/index.php/…`. El listener 9091 no publica puerto, acepta únicamente `GET /metrics` y marca FastCGI `DEVOPS_METRICS_INTERNAL=1`. El listener público fija `0`. Laravel exige simultáneamente la función habilitada, el método GET y la marca del servidor. Los headers de clientes llegan al espacio `HTTP_*` y no habilitan el acceso.

Prometheus solo está conectado a la red interna de monitoreo, sin credenciales SQL ni acceso a las redes de MySQL. Grafana comparte esa red y tiene una red adicional exclusiva para que Docker publique su UI en loopback; no comparte las redes de negocio. La frontera supone un host y contenedores confiables, sin protección frente al control del daemon Docker.

Prometheus recolecta `nginx:9091/metrics` cada 15 s, timeout 5 s; evalúa reglas cada 15 s. TSDB en volumen propio, retención 7 días o 512 MB. Sin puerto de host ni API administrativa habilitada. Prometheus y Grafana usan usuarios no privilegiados de sus imágenes; PHP e init conservan www-data, Nginx nginx. MySQL conserva su inicialización y permisos.

## Grafana y alertas

Datasource `devops-prometheus`, carpeta `DevOps`, dashboard `devops-api`, todo provisionado desde archivos de solo lectura. Sin plugins externos ni acceso anónimo/registro libre. Los nueve paneles filtran por job `laravel` y cubren RPS, rutas/estados, 4xx, 5xx, proporción 5xx, media, p95, disponibilidad y alertas.

Los paneles estadísticos consultan el valor instantáneo: no reutilizan una última latencia histórica cuando ya no hay observaciones en la ventana. El porcentaje sin solicitudes y las latencias sin muestras muestran “Sin tráfico / sin muestras suficientes”. No se convierte indiscriminadamente ausencia en cero.

Umbrales visuales absolutos: proporción 5xx verde por debajo de 20% y roja desde 20%; disponibilidad roja para `up=0` y verde desde `up=1`. El color es una indicación inmediata; la alerta 5xx conserva su condición estricta >20%, volumen mínimo y duración. Las consultas PromQL no cambian.

| Situación | Lectura |
|---|---|
| API devuelve errores | `up=1`, counter 5xx creciente |
| No se puede recolectar | `up=0` |
| Sin tráfico, con muestras suficientes | target disponible, RPS cero |
| Sin datos o muestras insuficientes | panel sin valor; verificar target y datasource |

`ApiHigh5xxRate`: proporción 5xx >20% en 2 minutos, `increase` total ≥10 y target disponible, durante 30 s. `increase` es una estimación, no un conteo exacto de la ventana. `ApiMetricsUnavailable`: target caído o serie esperada ausente durante 30 s. Sin Alertmanager: se detectan y visualizan estados, no se envían notificaciones externas.

## Verificación y demostración

```bash
# CI local completo, proyectos descartables, sin dependencia externa:
bash scripts/ci/check-observability.sh
# Jobs de regresión existentes:
bash scripts/ci/check.sh quality
bash scripts/ci/check.sh sqlite
bash scripts/ci/check.sh mysql

# Solo en el proyecto aislado preparado arriba, con GIPHY válido:
bash scripts/observability/demo.sh /tmp/devops-observability.env \
  /tmp/demo-evidence.json /tmp
```

La captura opcional requiere Node 22 y Chromium/Brave instalado; `OBS_BROWSER` permite indicar su ejecutable. Omitir el tercer argumento si se captura manualmente. Las credenciales no se imprimen ni se incluyen en la imagen. El script usa una sesión headless local temporal y la elimina al finalizar.

La demo mantiene el token solo en memoria y exige búsqueda autenticada 200 antes de inducir el fallo. Espera dos nuevos scrapes exitosos, aplica la clave vacía exclusivamente a `app`, recrea app/Nginx sin tocar MySQL/Passport y genera una búsqueda 503 por segundo. Verifica correlación y crecimiento del counter con `up=1`; consulta cada cinco segundos hasta observar pending y firing, con límite de cinco minutos desde la recolección restablecida. Restaura el Compose original, exige otra búsqueda 200 y espera la desaparición de la alerta, también con límite de cinco minutos. No hay espera fija de 90 segundos.

El wrapper tiene traps EXIT/INT/TERM y vuelve a intentar la restauración si no consta como completada; ante fallo termina con error e imprime el comando de recuperación. SIGKILL, caída del host o Docker inaccesible impiden garantizar restauración inmediata. Si el proveedor sigue fallando: “configuración restaurada, recuperación funcional no acreditada”. No se modifica `.env`.

El job `observability`, con timeout 15 minutos, valida Compose principal y monitoreo, promtool, cuatro workers reales, dos tandas de 200 solicitudes con concurrencia ocho, scrapes coherentes, UUID, fallos de almacenamiento/locks, recreación, seguridad pública y consultas reales de Grafana. Usa respuestas 401 deterministas sin llamar a GIPHY. Las pruebas temporales de reglas simulan volumen, pending, firing, recuperación, resets, target ausente y tráfico insuficiente. `CI Required` exige también este job; la release sigue invocando CI antes de publicar.

Resultados ejecutados y límites: [validation.md](validation.md). Referencias primarias: [adaptador PromPHP](https://github.com/PromPHP/prometheus_client_php/blob/v2.15.1/src/Prometheus/Storage/PDO.php), [SQLite WAL](https://www.sqlite.org/wal.html), [pruebas de reglas](https://prometheus.io/docs/prometheus/latest/configuration/unit_testing_rules/), [provisioning Grafana](https://grafana.com/docs/grafana/latest/administration/provisioning/).

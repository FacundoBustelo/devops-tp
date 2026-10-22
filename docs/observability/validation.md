# Fase 5 · Resultados ejecutados

Validación local del **22 de octubre de 2026**, rama `feat/observability`, creada desde `origin/main` actualizado en `8271f9b14c3f217dff0b25e2ef460b7c22486842` (`v1.0.0`). El checkout inicial estaba limpio. La implementación permanece **sin commit**; el SHA identifica la base y no un commit que ya contenga estos cambios. No se ejecutaron push, tags, publicación de imágenes ni cambios en secretos de GitHub.

El [manifiesto sanitizado](evidence/runtime.json) registra la ejecución **anterior a las correcciones de auditoría**, con plataforma, imágenes, usuarios, suites y un SHA-256 del contenido de los 160 archivos de código/configuración considerados. El digest concatena, en orden por nombre, `nombre + NUL + contenido + NUL`; incluye app, bootstrap, config, routes, scripts, tests, docker, monitoring, workflows, Dockerfile, Composer y Compose. Excluye documentación/evidencia y archivos ignorados. Permite distinguir el contenido probado de la base Git sin atribuirlo a un commit inexistente. La evidencia posterior se identifica por separado al final de este documento.

## Checks aprobados

| Comprobación ejecutada | Resultado |
|---|---|
| Composer | Solo se incorporó `promphp/prometheus_client_php v2.15.1`; ninguna dependencia existente actualizada. `check-platform-reqs` pasó en PHP 8.4.26 y durante los builds. |
| Calidad | Pint pasó sobre 128 archivos PHP. OpenAPI: 6 pruebas, 328 aserciones; documento y cuatro operaciones conservados. |
| Publicador | 7 pruebas Python aprobadas; protecciones SemVer, identidad, versiones existentes y publicación parcial conservadas. No publican imágenes. |
| PHPUnit SQLite | 79 pruebas, 2780 aserciones. Incluye 12 pruebas nuevas de observabilidad/almacenamiento y regresiones existentes. |
| PHPUnit MySQL | 82 pruebas, 2806 aserciones; proyecto y base descartables, incluyendo integración de auditoría/favoritos. |
| Compose principal | Build runtime y arranque saludable sin Prometheus/Grafana, API 401 determinista, HTTP JSON, Swagger y ausencia del directorio SQLite de métricas. |
| Promtool | Configuración y dos reglas válidas; seis escenarios temporales aprobados. Exposición real pasó `promtool check metrics`. |
| Concurrencia FPM | Dos tandas de 200 solicitudes con concurrencia ocho; cuatro workers acreditados por PID/estado en access log exclusivo de prueba. Counter y `_count` aumentaron exactamente 200 por tanda. Seis scrapes concurrentes coherentes en cada tanda de la ejecución final. |
| Reinicios | Restart de FPM conserva muestras; recreación de app reinicia counters y conserva cantidad de filas de auditoría y hash de la clave pública Passport. Segunda tanda desde almacenamiento recién inicializado. |
| Seguridad pública | 78 solicitudes sin normalización del cliente: `/metrics`, sufijos, barras, codificaciones, `/index.php`, seis métodos y headers de forwarding/autorización interna. Ninguna exposición Prometheus. |
| Correlación | Igualdad real header → UUID de fila SQL → único evento JSON, además de pruebas de aislamiento e ignorar el ID enviado por el cliente. |
| Fallo SQLite | Lock real: respuesta 401 conservada y scrape coherente sin actualización parcial. Directorio inaccesible: API 401, scrape 503, `up=0`; restauración exitosa. Rollback forzado entre contador e histograma también pasó en PHPUnit. |
| Logs | Esquema permitido, UTC, niveles, respuesta final, excepciones sin mensajes/SQL/trazas y fallback seguro. La revisión de logs app/Nginx no encontró los secretos centinela enviados en queries y headers. |
| Grafana | Autenticación obligatoria, datasource y dashboard provisionados, consultas ejecutadas por su proxy real. Sin tráfico: `up=1`, RPS=0 y proporción/latencias ausentes. Con tráfico sostenido: valores finitos en los ocho paneles de métricas; alertas ausentes. |
| Job obligatorio | `bash scripts/ci/check-observability.sh` completo terminó con código 0. `CI Required` exige `quality`, `phpunit` y `observability`. |

La [salida resumida del job](evidence/ci-observability.txt) incluye consultas y resultados efectivos, no resultados previstos. Ese proyecto descartable se eliminó con sus volúmenes al concluir. La comprobación de concurrencia utiliza rutas reales y PHP-FPM; no se sustituye por ejecución CLI.

## Demostración real con GIPHY

Se utilizó `devops-obs-phase5`, con archivo privado `/tmp/devops-phase5.env`, credenciales nuevas, imágenes locales `0.0.0-phase5`, puertos efímeros y volúmenes propios. De `.env` solo se leyó la clave GIPHY; no se modificó el archivo. El token permaneció en memoria y no se registraron cuerpos ni credenciales.

La [ejecución final](evidence/demo.json), de **21:22:44 a 21:26:21 UTC**, comprobó:

1. Login y búsqueda autenticada inicial con **200**.
2. Dos nuevos scrapes exitosos, obtenidos del timestamp original de `up`, antes de inducir el fallo y después de restablecer la recolección.
3. Clave vacía aplicada únicamente a `app`, con recreación de app/Nginx y conservación de MySQL/Passport.
4. **51 búsquedas autenticadas con 503** a una por segundo; incremento SQLite igual al tráfico generado, correlación SQL/header/log y crecimiento real del counter 503 recolectado.
5. `up=1`; alerta **pending a los 20.3 s** y **firing a los 50.3 s** desde el inicio del tráfico fallido, dentro del límite de cinco minutos. Los tiempos no constituyen una espera fija ni una garantía para futuras ejecuciones.
6. Restauración original, búsqueda autenticada **200**, dos nuevos scrapes estables y tráfico exitoso mientras los errores salían de la ventana.
7. Recuperación final: **5xx=0**, proporción **0%**, **up=1** y **cero alertas activas**, acreditados también mediante las consultas del datasource de Grafana.

El valor Prometheus del counter 503 guardado en el reporte es **45**, mientras se habían generado 51 respuestas: corresponde al último scrape disponible en ese instante, anterior a las últimas solicitudes. No se presenta como conteo exacto de todo el tráfico ni se confunde con el delta SQLite verificado.

Se revisaron visualmente las capturas autenticadas de [firing](evidence/grafana-firing.png) y [recuperación](evidence/grafana-recovered.png). Los gráficos de series temporales conservan el historial de errores/alertas; su presencia histórica después de recuperar no implica una alerta activa. Los paneles estadísticos muestran valores instantáneos. Las capturas pueden diferir ligeramente de los números de las consultas JSON por los segundos transcurridos entre ambas observaciones.

## Imágenes y usuarios

Los IDs completos se conservan en `runtime.json`. Son IDs de configuración **locales**, no digests de releases publicadas. Todos los runtimes probados fueron `linux/amd64`.

| Ejecución | PHP | Nginx |
|---|---|---|
| Job final observability | `sha256:59146a99d289f8610757e9c13ad3184cba4f2559d90631428943f5e3caef1691` | `sha256:a06c808073eadfb1b121c5cbbe4c1f7e48ce92e03dffd5b00d8a330c1e71a86a` |
| Demo | `sha256:863edc2d88b4a0fd72f758256b13e135ded5905b456e30cd5d2d33a4d493ed68` | `sha256:be77d97f15c286b45d5b90498d209643f5d019823c8b898b67188bd2f4b7dd8f` |

El contenedor `init` de la demo fue creado con un build previo de la misma fase (`b0302155…`), antes de optimizar la conexión de métricas; solo ejecutó la inicialización MySQL/Passport existente. El job final construyó e inició app/init con el mismo build final.

Pins externos efectivos: Prometheus `v3.15.1@sha256:fa3302279710be2f21a8301ec17a8b4f2301fd829bd113839b3b6b02b6f191a2`; Grafana `13.2.4@sha256:dbbc38ea21103cefb50396340cd88afec779c7e070b0a7bf5a75b4ce46651d3f`. Se conservaron los pins de PHP, Composer, Nginx y MySQL. PDO SQLite se verificó en PHP 8.4.26 con SQLite 3.53.4.

Usuarios observados: app/init usan la imagen con `www-data` (UID 82); Nginx UID 101; Prometheus UID 65534; Grafana UID 472, grupo de imagen 0, sin ejecutar como UID root. El servidor `mysqld` existente usa UID/GID 999; su inicialización y propietarios no se modificaron. Prometheus y Grafana pudieron escribir en sus propios volúmenes sin cambios manuales de propietarios.

## Correcciones detectadas y límites

- La primera suite detectó que Symfony añade `charset=utf-8` al `Content-Type`: se corrigió la aserción y se repitieron las suites.
- Pint detectó orden de imports y espacios en dos pruebas de regresión modificadas; se aplicó formato y se repitió el job completo.
- El archivo de pruebas promtool resuelve rutas relativas a su directorio: se corrigió a `../alerts.yml` y los seis escenarios pasaron.
- `OPTIONS /index.php/metrics` devolvía 200 con cuerpo vacío, sin métricas. Se añadió bloqueo explícito del prefijo; los 78 casos finales pasaron.
- La primera prueba concurrente produjo 199/200 observaciones por un lock SQLite y no se aceptó. WAL con `synchronous=NORMAL` redujo la espera de fsync; las tandas finales exactas pasaron. La posibilidad de pérdidas ante bloqueos/fallos sigue documentada; no se cambió la espera de 50 ms ni se agregaron reintentos.
- Un contenedor conectado solamente a la red Docker interna no publicaba el puerto de Grafana. Se añadió `grafana_access`, exclusiva de Grafana, conservando Prometheus aislado de redes/credenciales SQL.
- `timestamp(up == 1)` producía timestamps de evaluación y no acreditaba dos scrapes. Se corrigió a `timestamp(up) and (up == 1)`, se repitió el job y la demo; los resultados finales usan esa corrección.
- El primer criterio de recuperación podía coincidir con una desactivación transitoria al recrear app. La ejecución final exige recolección estable, proporción 5xx ≤20% y ninguna alerta activa; finalizó con 0%.
- La primera captura headless estaba vacía pese a existir el DOM. Se activó la página antes de capturar y se revisaron las imágenes efectivas. Los paneles de estadísticas se cambiaron a consultas instantáneas para no mostrar una latencia histórica como actual.

La instrumentación sigue siendo de mejor esfuerzo ante fallos de almacenamiento. WAL/NORMAL puede perder las últimas métricas ante caída del host; no cambia la auditoría MySQL. El diseño es para una instancia y no mide fallos anteriores a Laravel. Las restricciones sobre SIGKILL/host/Docker y restauración automática se documentan en la guía operativa.

No se ejecutó el workflow nuevo en runners GitHub ni se modificaron reglas de protección del repositorio: se verificó localmente el mismo script y se revisó el gate versionado. No se creó PR ni release. La publicación histórica `v1.0.0` se confirmó de solo lectura con `gh run view`: conclusión `success`, SHA `8271f9b…`. No se sobrescribieron sus imágenes.

## Correcciones de auditoría · 22 de octubre de 2026

Esta revisión se limita a privacidad de los errores nativos Nginx y colores de dos paneles Grafana, con pruebas y documentación correspondientes. Las suites y capturas anteriores son históricas: **no cubrían el rechazo 413 y mostraban umbrales incorrectos**. La ausencia de centinelas en aquellas solicitudes no acreditaba la privacidad de errores anteriores a Laravel.

Se reprodujo el problema en `devops-obs-audit-fixes`, con credenciales nuevas en un archivo 0600 fuera del repositorio, sin copiar GIPHY. La prueba envía un POST con query `token=forbidden-413-secret-<UUID>`, `Content-Length: 2097152`, `Expect: 100-continue` y conexión cerrada, sin transmitir el cuerpo: Nginx puede rechazarlo al leer los headers. La configuración original devolvió 413 y la nueva regresión falló por el marcador en el log nativo; se verificaron también el texto de tamaño 2097152 y el contexto de IP. No se guarda el mensaje crudo ni la IP como evidencia.

La configuración corregida usa `error_log /dev/null` en `http`, conservando `error_log /dev/stderr warn` en el contexto principal. La [guía de logging](README.md#errores-nativos-de-nginx-privacidad-y-diagnóstico) documenta el análisis de la versión exacta 1.30.5, las alternativas descartadas y la pérdida deliberada del detalle de errores HTTP/FastCGI. `nginx -V` confirmó 1.30.5; `nginx -t` pasó. Una invocación independiente de `nginx -t` con una directiva inválida devolvió código 1 y su diagnóstico por stderr, sin recargar ni alterar el servidor.

La regresión automatizada en `scripts/observability/verify.py`, integrada al job existente, pasó con la corrección: 413 sin UUID Laravel, métricas Laravel idénticas antes/después, evento Nginx JSON 413 con solo fuente/estado/duración, y una solicitud posterior 401 con correlación header/auditoría/evento JSON. `/up` y `/docs` siguieron en 200, `/metrics` público en 404 y el scrape interno disponible. El marcador no apareció en los logs Docker de **todos los servicios** ni en los archivos regulares de `/var/log` y `/tmp` de Nginx/PHP y `storage/logs` de Laravel. No se siguen enlaces a stdout/stderr ni se leen dispositivos/pipes; los streams se inspeccionan mediante Compose. Esto acredita los destinos inspeccionados en esa ejecución, no logs externos del host.

Grafana conserva todas las consultas y opciones de targets. Solo se añaden umbrales absolutos: 5xx rojo desde 20%; disponibilidad roja desde la base y verde desde 1. El JSON local pasó el parser y se compararon los targets completos con una copia anterior. El verificador también exige esos umbrales y `colorMode=value` en el dashboard obtenido de la API real.

Se revisaron visualmente las nuevas capturas autenticadas: [5xx alto](evidence/grafana-audit-5xx.png) muestra **48,5% rojo y up=1 verde**; [target caído](evidence/grafana-audit-down.png) muestra **up=0 rojo** y **65,4% rojo**. Se usaron respuestas 503 reales por clave GIPHY vacía en el proyecto descartable; no se inyectaron muestras Prometheus ni se cambiaron consultas. Para `up=0` se hizo inaccesible temporalmente el directorio de métricas y se restauró en `finally`; después se acreditaron dos nuevos scrapes correctos y `up=1`. El porcentaje de la consulta guardada puede diferir del de la captura por los segundos transcurridos y el siguiente scrape. No se presenta esta comprobación de colores como una repetición de la demo GIPHY completa ni como recuperación del porcentaje a cero.

Checks posteriores ejecutados, todos con código 0:

| Comando/comprobación | Resultado |
|---|---|
| `bash scripts/ci/check.sh quality` | Pint: 128 archivos; OpenAPI: 6 pruebas, 328 aserciones; publicador Python: 7 pruebas. |
| `bash scripts/ci/check.sh sqlite` | 79 pruebas, 2780 aserciones, incluidas observabilidad y almacenamiento. |
| `bash scripts/ci/check-observability.sh` | Compose principal y monitoreo; promtool config/reglas/seis escenarios/exposición; regresión 413; 78 solicitudes de seguridad; dos tandas exactas de 200 solicitudes con ocho clientes, cuatro workers y seis scrapes coherentes por tanda; reinicio/recreación; locks/fallo SQLite; correlación; autenticación, umbrales y consultas reales Grafana. |
| Comprobación directa corregida | Regresión 413 y `nginx -t` aprobados; diagnóstico de configuración inválida conservado. |
| JSON/Python/diff | JSON válido, targets sin cambios, Python parseado con AST y `git diff --check origin/main` sin errores. |

La [salida seleccionada](evidence/audit-corrections.txt) y el [manifiesto posterior](evidence/audit-corrections.json) registran solo resultados ejecutados. Este último incluye la lista explícita y digest de archivos de código/configuración considerados, con ambos archivos PHPUnit; su selección queda definida independientemente del manifiesto histórico. No se repitió la suite MySQL completa ni la demo externa GIPHY: el job observability sí usó MySQL/FPM reales. Los proyectos de estos nuevos checks y sus volúmenes se eliminaron; los proyectos preexistentes no se recrearon.

Se revisó el diff contra `origin/main` (`8271f9b…`), incluidos los archivos nuevos de Fase 5. Se inspeccionaron los candidatos a versionar y se compararon con valores privados de los entornos usados, sin encontrar credenciales reales ni temporales incorporados. Los únicos cambios nuevos de código/configuración en esta corrección son Nginx, los dos umbrales y su verificador; el resto es documentación/evidencia. No se ejecutaron commits, push, PR, tags ni publicaciones Docker Hub. La rama queda preparada para solicitar autorización de commits y PR; el workflow remoto todavía debe ejecutarse en GitHub.

Riesgos restantes: se pierde el detalle nativo HTTP/FastCGI indicado en la guía; la instrumentación Laravel no cuenta rechazos previos a PHP y conserva sus límites ante fallos SQLite. Aplicar el cambio a instalaciones existentes requiere reconstruir/recrear Nginx; la política no elimina registros históricos ya almacenados ni sanitiza logs externos al contenedor.

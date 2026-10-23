# Validación local del paquete Oracle

Ejecutada el 22 de octubre de 2026, UTC−03, en la rama `feat/oracle-deployment`. Base de aplicación: `v1.1.0`, revisión `8c85178a6a1560e0d66334770ed2499855c2da59`. No se hicieron commits, push, PR, builds de aplicación ni acciones OCI.

## Resultados

| Verificación | Resultado |
|---|---|
| Docker Hub, referencias PHP/Nginx publicadas | Digests y etiquetas OCI verificados; amd64, versión 1.1.0 y revisión esperada |
| `python3 -B -m unittest discover -s tests/oracle -v` | 14 pruebas OK; incluye Compose, secretos, redes, recursos, tar reproducible, permisos, propiedad de volúmenes, recuperación ante SIGTERM y gate PR/release |
| `python3 -B -m unittest discover -s tests/ci -v` | 7 pruebas de protección de release OK |
| `python3 -B scripts/ci/check-oracle.py` | OK, código de salida 0; proyectos, redes y volúmenes temporales retirados |
| Ubuntu 24.04, root y permisos de instalación | Init, alta de usuario, health, backup cifrado y restore OK en contenedor Ubuntu 24.04 temporal; paquete con dueño root, modos 0755/0644 y secretos 0700/0600 |
| Paquete | 15 archivos operativos explícitos, contenido reproducible, sin archivos adicionales ni secretos; copia instalada probada |
| Volúmenes externos | Los cuatro sobreviven a `compose down --volumes`; rechazo de volúmenes pertenecientes a otro proyecto |
| Compatibilidad release | `release.yml` idéntico a v1.1.0; Oracle se ejecuta solo en PR; simulación del gate con éxito, fallo y skipped OK |
| PHP-FPM contra imagen publicada | Sintaxis OK; `pm=ondemand`, `pm.max_children=1`, reciclado 200, timeout 20 segundos; ajuste final `access.log=/dev/null` comprobado por separado |
| PHP INI efectivo | `memory_limit=96M`, OPcache 48 MiB, `validate_timestamps=0` |
| Nginx contra imagen publicada | `nginx -t` OK con usuario de imagen y capacidades reducidas |
| MySQL, configuración efectiva | Buffer pool 128 MiB, 20 conexiones, Performance Schema OFF, TempTable 16 MiB |
| Caddy | Configuración válida; TLS comprobado con CA local, redirección HTTP 308, métricas 404 |
| Proxy | Laravel reconoce HTTPS y puerto 443; IP coincide con peer observado; headers de forwarding falsificados no prevalecen |
| Instalación producción | Migraciones exitosas con límites previstos; cero usuarios sembrados; alta explícita y rechazo de duplicados |
| Persistencia | APP_KEY, ambas claves, cliente OAuth y token conservados tras recrear contenedores y restaurar backup |
| SQL independiente de salud HTTP/FPM | `/up` devuelve 200 con MySQL detenido, pero health operacional falla; contraseña SQL inválida también detectada desde PHP |
| Backup | Cifrado GnuPG AES256; no reejecuta init/migraciones al reanudar contenedores; conserva sus montajes y puertos |
| Restauración | Volúmenes nuevos; token anterior y CA conservados; rechazo de backup alterado y de volúmenes existentes |
| `actionlint .github/workflows/ci.yml` | OK, usando actionlint local 1.7.12 |
| PHP lint de scripts operativos | OK |
| `git diff --check` | OK |

Consumo observado al final del ensayo, sin carga sostenida:

| Servicio | Memoria observada | Límite |
|---|---:|---:|
| MySQL | 135,5 MiB | 384 MiB |
| PHP-FPM | 36,5 MiB | 192 MiB |
| Caddy | 14,2 MiB | 96 MiB |
| Nginx | 2,4 MiB | 32 MiB |

No se observaron contenedores marcados `OOMKilled` al finalizar. Estos números no miden el pico de inicialización, capacidad máxima, presión global de una VPS de 954 MiB ni CPU compartida.

## Correcciones derivadas del ensayo

- Se reservó un rango dinámico separado de la IP fija de Caddy: Nginx podía recibir esa IP cuando Caddy todavía no existía.
- El probe compara claves públicas normalizadas por OpenSSL: phpseclib puede producir PEM con CRLF sin que exista una incompatibilidad criptográfica.
- La reanudación tras backup usa IDs existentes de Docker. `compose start` podía iniciar dependencias y volver a ejecutar init.
- Los hashes de backups se calculan por streaming para no cargar dumps completos en memoria.
- La auditoría final limitó Oracle a PR para evitar agregar a release una dependencia de imágenes publicadas.
- El comando `package` reemplaza el tar recursivo: lista explícita de 15 archivos, permisos normalizados, sin secretos ni cachés adicionales.
- Se verifica la etiqueta de propiedad de volúmenes antes de reutilizarlos y se probó su conservación incluso con `down --volumes`.
- El mantenimiento atiende SIGTERM e intenta reanudar todos los consumidores aunque falle alguno. Se agregó regresión para ambos casos.
- La detección de checkout exige un `.git` real: un directorio marcador vacío no puede bloquear el uso de un paquete instalado fuera del repositorio.

## Límites de esta evidencia

El host local tiene más RAM que la VPS; se aplicaron los límites de cgroup de cada servicio. Las pruebas TLS usaron una CA local y exclusivamente puertos efímeros en `127.0.0.1`, con una ruta diagnóstica montada solo en el stack temporal. No se modificaron las imágenes ni el código Laravel del checkout.

La prueba adicional Ubuntu utilizó `ubuntu:24.04@sha256:68ee22004a9109b44973313b02ff7b1de250f1de0f5ecb628d73f579aaff33ac`, Python 3.12 y GnuPG instalados por apt, con cliente Docker conectado al daemon local. No inició systemd como PID 1: las unidades se verificaron con `systemd-analyze verify` y sus comandos se ejecutaron como root. No sustituye probar timers y reboot en la VPS. Un primer intento del harness anidado de TLS apuntaba al loopback del contenedor en vez del host; se separó el ensayo privado Ubuntu del ensayo TLS en el host, que pasó completo.

No se validaron DNS público, reglas OCI, certificados/renovación ACME públicos, reboot real de la VPS, carga sostenida, GIPHY con una clave real, ni ejecución en GitHub Actions. El workflow queda preparado para ejecutar las suites cuando se publique un cambio posteriormente autorizado. No se repitió la suite Laravel completa porque no se cambió su código, dependencias ni migraciones.

Los secretos de prueba se generaron aleatoriamente fuera del repositorio. Los contenedores de desarrollo y observabilidad preexistentes no formaron parte del stack de prueba.

Procedimiento operativo: [oracle.md](oracle.md).

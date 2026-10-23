# Oracle Cloud: devops-tp v1.1.0

Preparación reproducible para Ubuntu 24.04 x86_64, 954 MiB RAM, 2 GiB swap y aproximadamente 42 GB libres. Este procedimiento no reconstruye imágenes ni necesita un checkout de Laravel en la VPS. No modifica infraestructura OCI. La publicación HTTPS es una acción separada y queda deshabilitada por defecto.

## Artefactos y alcance

La revisión de aplicación es `8c85178a6a1560e0d66334770ed2499855c2da59`. Las referencias del Compose se comprobaron contra Docker Hub el 22 de octubre de 2026:

| Servicio | Imagen inmutable |
|---|---|
| app / init | `facubus/devops-tp-php:1.1.0@sha256:be814bb939c98c3912feb5df847f857e5ba750d7a1eed4df98dcd1a4a716318c` |
| nginx | `facubus/devops-tp-nginx:1.1.0@sha256:c18a7934b67b4abafcedac45b3a38552fce8f23b92eae2e7c143a7f987f05f24` |
| mysql | `mysql:8.4.11@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242` |
| caddy | `caddy:2.11.7-alpine@sha256:eb4d35b7452d1ab6171fbdbe8d9275b2301dad6dad92115d91e19fb9b24d510f` |

El digest de Caddy identifica el índice multiarquitectura; `platform: linux/amd64` selecciona su manifiesto amd64. Los digests PHP/Nginx identifican las imágenes publicadas de esa arquitectura. Las etiquetas OCI de ambas deben indicar versión `1.1.0` y la revisión anterior. `bin/oracle init` lo exige.

Solo se usa `deploy/oracle/compose.prod.yaml`, nunca el Compose de desarrollo ni sus overrides. No contiene `build`, montajes de fuentes ni puertos publicados. Caddy tiene el perfil `https`: la inicialización no lo arranca ni solicita certificados. No hay Prometheus, Grafana ni listener de métricas 9091.

## Servicios, recursos y persistencia

| Servicio | RAM máxima | RAM + swap máxima | Redes |
|---|---:|---:|---|
| MySQL | 384 MiB | 768 MiB | database, interna |
| init, temporal | 192 MiB | 384 MiB | database |
| PHP-FPM | 192 MiB | 384 MiB | database, fastcgi, outbound |
| Nginx | 32 MiB | 64 MiB | fastcgi, proxy, internas |
| Caddy, optativo | 96 MiB | 192 MiB | proxy, outbound |

Total permanente con Caddy: 704 MiB de límites. Quedan aproximadamente 250 MiB para Ubuntu y Docker; no es una reserva garantizada. El host también necesita memoria para operaciones CLI y backups. La swap amortigua picos; paginación sostenida, OOM o latencias elevadas son motivo para reducir carga o ampliar la instancia. `memswap_limit` incluye RAM y swap.

MySQL tiene buffer pool de 128 MiB, 20 conexiones, tablas temporales limitadas y Performance Schema deshabilitado. Se conserva `innodb_flush_log_at_trx_commit=1` y `sync_binlog=1`. Los binlogs expiran a los tres días; no constituyen una solución PITR automática. Se deshabilita MySQL X Protocol. La carga inicial de tablas de zonas horarias se omite: las operaciones actuales usan UTC y no necesitan conversiones SQL por nombre de zona.

PHP usa un worker `ondemand`, `memory_limit=96M` y OPcache de 48 MiB, además del buffer de strings. No aumentar workers sin medir. Se suprime el access log de FPM que incluye la URI cruda; se conservan los eventos de aplicación y los logs sanitizados de Nginx. Nginx usa un worker y conserva los bloqueos de la release. Los archivos de configuración cargan después de los valores de la imagen; las pruebas comprueban las configuraciones efectivas.

Volúmenes externos, con prefijo predeterminado `devops_tp_prod`:

- `_mysql`: `/var/lib/mysql`, incluye usuarios, favoritos, auditoría, sesiones, caché y OAuth.
- `_passport`: `/var/www/html/storage/passport`, escritura solo en init; lectura en app.
- `_caddy_data`: `/data`, certificados, CA y estado ACME.
- `_caddy_config`: `/config`.

Init verifica la etiqueta de propiedad antes de reutilizar un volumen: rechaza nombres preexistentes sin etiqueta o asignados a otro proyecto. Los externos sobreviven incluso a `compose down --volumes`, condición probada automáticamente. Esto no impide que un administrador los borre con `docker volume rm` o una poda global de volúmenes sin uso; no ejecutar esas operaciones sobre esta instalación.

PHP ejecuta como UID/GID 82 y las claves tienen modo 0600, directorio 0700. Docker inicializa un volumen Passport vacío con el contenido y propietario de la imagen. La restauración aplica los mismos permisos. No se persiste todo `storage`, código, `vendor` ni `bootstrap/cache`: actualmente no hay uploads de usuario. Los logs van a Docker con tres archivos de hasta 10 MiB por contenedor.

## Instalación sin exposición pública

Los siguientes pasos son para una ejecución futura autorizada en la VPS. No abren puertos ni cambian reglas cloud. Requisitos adicionales: Python 3.12, GnuPG y curl.

1. Desde el equipo con esta rama revisada, copiar solamente el paquete de despliegue. Reemplazar `USUARIO@VPS` por el acceso real:

```bash
deploy/oracle/bin/oracle package --output /tmp/devops-tp-oracle.tar.gz
scp /tmp/devops-tp-oracle.tar.gz USUARIO@VPS:/tmp/devops-tp-oracle.tar.gz
```

El empaquetador incluye solo los 15 archivos operativos declarados en `PACKAGE_FILES`, con propietario root y modos normalizados. Excluye archivos adicionales, secretos y cachés; produce el mismo tar.gz para los mismos contenidos y se niega a sobrescribir el destino. Si ya existe un paquete anterior, elegir otro nombre para conservarlo hasta revisar el nuevo.

2. En la VPS, revisar recursos, instalar herramientas operativas y copiar el paquete:

```bash
uname -m
free -h
swapon --show
df -h /
docker version
docker compose version
sudo ss -lntup
sudo apt-get update
sudo apt-get install -y python3 gnupg curl
sudo install -d -m 0755 /opt/devops-tp
sudo tar -xzf /tmp/devops-tp-oracle.tar.gz -C /opt/devops-tp
sudo chown -R root:root /opt/devops-tp
sudo find /opt/devops-tp -type d -exec chmod 0755 {} +
sudo find /opt/devops-tp -type f -exec chmod 0644 {} +
sudo chmod 0755 /opt/devops-tp/bin/oracle
```

3. Elegir un dominio real y un correo ACME. Revisar las subredes Docker y rutas de la VCN para elegir un `/24` privado sin solapamientos:

```bash
sudo docker network ls
ip route
sudo /opt/devops-tp/bin/oracle prepare \
  --domain api.example.com \
  --email operator@example.com \
  --proxy-subnet 172.30.77.0/24
```

Sustituir dominio, correo y subred antes de ejecutar. Init detecta conflictos con redes Docker; la revisión de rutas del host/OCI es manual. No usar direcciones `.example`/`.invalid` para certificados públicos.

El comando crea `/etc/devops-tp` con 0700 y archivos 0600. Reserva la mitad inferior de la subred para direcciones fijas y usa la superior para IP dinámicas: Nginx no puede ocupar la IP de Caddy antes de activarlo.

- `app.env`: APP_KEY aleatoria, contraseña SQL de aplicación y configuración Laravel.
- `mysql.env`: credenciales iniciales de MySQL, con contraseña root independiente.
- `deploy.env`: dominio, correo y dirección fija del proxy, sin secretos.
- `backup.passphrase`: clave aleatoria de cifrado de respaldos; **copiarla fuera de la VPS, separada de los backups**.

Se niega a reemplazar archivos existentes o escribir dentro del repositorio. No copiar `.env` local ni ejecutar `scripts/init-env.php` de desarrollo. Para migrar una instalación existente usar restauración, conservando APP_KEY, base y ambas claves.

Para GIPHY, proporcionar una clave mediante archivo privado con `prepare --giphy-key-file /ruta/privada/giphy.key`, o editar únicamente `GIPHY_API_KEY` en `/etc/devops-tp/app.env` con `sudoedit` antes de init. El archivo de entrada debe pertenecer al usuario que ejecuta el comando y tener modo 0600. No introducir secretos en argv, Git, tickets o logs.

4. Inicializar y verificar:

```bash
sudo /opt/devops-tp/bin/oracle init
sudo /opt/devops-tp/bin/oracle provision-user
sudo /opt/devops-tp/bin/oracle health
```

Init descarga referencias fijadas, comprueba metadatos de aplicación, crea volúmenes externos y ejecuta MySQL → init → PHP-FPM → Nginx. No arranca Caddy. La inicialización de Laravel aplica migraciones pendientes, crea claves solo si faltan ambas y reutiliza el cliente personal. En producción no hay seeder automático ni contraseña predeterminada.

El alta solicita nombre, email y una contraseña de al menos 16 caracteres y hasta 72 bytes UTF-8, con confirmación sin eco. El máximo evita truncamiento de bcrypt. Usa stdin para transmitirlos a PHP, no crea archivos de credenciales y se niega a reemplazar usuarios existentes, incluso borrados lógicamente. `--stdin` permite automatización con JSON privado por pipe; no incluirlo literalmente en historial de shell. No hay endpoint de registro público.

Definir una función para operaciones posteriores:

```bash
dc() {
  sudo env ORACLE_SECRETS_DIR=/etc/devops-tp \
    docker compose --project-directory /opt/devops-tp \
    --env-file /etc/devops-tp/deploy.env -p devops-tp-prod \
    -f /opt/devops-tp/compose.prod.yaml "$@"
}
dc config --quiet
dc ps -a
dc logs --tail=100 init
dc stats --no-stream
sudo ss -lntup
```

Evitar `dc config` sin `--quiet`: renderiza secretos. Evitar `docker inspect` completo en registros compartidos. Que `docker ps` muestre `9000/tcp` o `3306/tcp` sin `HOST->PUERTO` es metadato interno, no publicación.

## Activación futura de HTTPS: acción separada

**No ejecutar esta sección durante la preparación sin exposición pública.** Requiere una decisión posterior de habilitar acceso, DNS A correcto, resolución pública y reglas OCI/host que permitan 80/443 TCP. AAAA solo si IPv6 está operativo. Mantener SSH restringido a administradores. No abrir 3306, 9000, 8080, 9091, 9090 ni 3000.

La configuración pública está aislada en `compose.https.yaml` y exige `PUBLIC_BIND_IP` explícito. Cuando se autorice:

```bash
sudo env ORACLE_SECRETS_DIR=/etc/devops-tp PUBLIC_BIND_IP=0.0.0.0 \
  docker compose --project-directory /opt/devops-tp \
  --env-file /etc/devops-tp/deploy.env -p devops-tp-prod \
  -f /opt/devops-tp/compose.prod.yaml -f /opt/devops-tp/compose.https.yaml \
  --profile https pull caddy

sudo env ORACLE_SECRETS_DIR=/etc/devops-tp PUBLIC_BIND_IP=0.0.0.0 \
  docker compose --project-directory /opt/devops-tp \
  --env-file /etc/devops-tp/deploy.env -p devops-tp-prod \
  -f /opt/devops-tp/compose.prod.yaml -f /opt/devops-tp/compose.https.yaml \
  --profile https up -d --no-build --pull never --wait --wait-timeout 600

curl --fail --silent --show-error https://api.example.com/up
curl -I http://api.example.com/up
curl --silent --output /dev/null --write-out '%{http_code}\n' https://api.example.com/metrics
sudo /opt/devops-tp/bin/oracle --https-url https://api.example.com/up health
```

Resultados: HTTPS válido y `/up` 200, HTTP 308 hacia HTTPS y métricas 404. No usar `curl -k` en aceptación. Caddy renueva certificados y los conserva en sus volúmenes. Su API administrativa queda en loopback del contenedor, sin publicación. No se habilita HTTP/3/UDP. TLS mínimo 1.2. HSTS queda pendiente de validar dominio/renovación; no activar preload ni `includeSubDomains` anticipadamente.

Caddy conserva el Host autorizado y **reemplaza** X-Forwarded-For, Proto y Port con valores del transporte. Laravel confía únicamente en la IP fija de Caddy, pasada por Compose; no en todas las redes privadas. Nginx conserva REMOTE_ADDR como el peer Caddy y pasa los headers FastCGI; no se agrega `real_ip`. Así se preservan HTTPS e IP del cliente sin tocar el middleware Laravel. La prueba local manda headers falsificados y comprueba que no se acepten.

Mantener siempre ambos archivos y `--profile https` para recrear el stack una vez publicado. `init` se niega a actuar si hay consumidores ejecutándose: usar el procedimiento de mantenimiento y conservar los dos archivos al recuperar HTTPS. No utilizar init para actualizar un stack público.

## Salud, reinicio y diagnóstico

FPM solo comprueba su listener. Nginx `/up` comprueba HTTP → FPM → Laravel, sin SQL. `bin/oracle health` agrega SQL usando las credenciales de app, tablas esenciales, cliente Passport y correspondencia/permisos del par de claves. Un contenedor healthy no garantiza SQL operativo. La prueba integral demuestra `/up=200` con MySQL detenido y exige que la verificación operacional falle; también prueba una contraseña SQL incorrecta desde PHP.

`restart: unless-stopped` recupera procesos que terminan, no un estado `unhealthy`. `depends_on` ordena Compose, no supervisa continuamente el arranque de Docker tras reboot. Después de reiniciar la VPS ejecutar `health`; comprobar también la persistencia de un token no expirado y un favorito. No se instala un agente con permisos de Docker para reiniciar servicios ilimitadamente.

```bash
dc ps -a
dc logs --tail=100 mysql init app nginx
dc stats --no-stream
free -h
vmstat 1 5
df -h /
sudo journalctl -k --since '1 hour ago'
```

Si FPM se recrea cambia potencialmente su IP: recrear Nginx después, porque el upstream de la release resuelve `app` al iniciar. Tras corregir un problema de proceso y con MySQL saludable:

```bash
dc up -d --no-build --pull never --force-recreate --no-deps app
dc up -d --no-build --pull never --force-recreate --no-deps nginx
sudo /opt/devops-tp/bin/oracle health
```

Si init falla, detener consumidores antes de reintentarlo, investigar SQL/permisos y no regenerar secretos:

```bash
dc --profile https stop caddy nginx app
dc up -d --no-build --pull never --wait --wait-timeout 600 mysql
dc run --rm -T --no-deps init
dc up -d --no-build --pull never --wait --wait-timeout 600 app nginx
```

En una instalación pública, recuperar Caddy usando los dos archivos de la sección HTTPS. Nunca usar `migrate:fresh`, `passport:keys --force` ni `key:generate` como reparación. Un par Passport incompleto exige restaurar ambas claves del mismo backup.

## Backups y restauración

```bash
sudo /opt/devops-tp/bin/oracle backup --destination /var/backups/devops-tp
```

El directorio debe tener modo 0700. El comando realiza una comprobación de salud, detiene brevemente Caddy/Nginx/PHP que estuvieran ejecutándose, vuelca SQL con `--single-transaction --quick --no-tablespaces --set-gtid-purged=OFF`, copia claves, estado Caddy, configuración y metadatos de imágenes, y reanuda solo los contenedores previamente activos. Luego comprime y cifra con GnuPG AES256 y protección de integridad. Las contraseñas no se pasan en argv. No se archiva `backup.passphrase`.

Durante esa ventana no debe haber otros escritores SQL ni cambios de esquema. El directorio temporal de plaintext tiene modo 0700, se elimina al salir normalmente o por excepción; un corte abrupto puede dejarlo en el disco y requiere revisión. No se promete borrado seguro de bloques. Se requiere espacio para dump, archivo comprimido y cifrado; la verificación mínima de disco no estima el crecimiento de la base. Los hashes se calculan por streaming para no cargar el dump en RAM.

Copiar archivos `.gpg` a almacenamiento externo mediante un canal administrado y probar recuperación. El script no envía información a ningún servicio. Política inicial sugerida: siete diarios y cuatro semanales fuera de la VPS; establecer retención local según espacio. No hay eliminación automática de backups ni auditoría. RPO diario: hasta 24 horas. RTO: medirlo con una restauración real.

Para recuperar en una VPS limpia, instalar el mismo paquete y herramientas. No ejecutar `prepare` ni `init`: primero restaurar secretos y datos desde el backup. Copiar la clave de recuperación almacenada separadamente:

```bash
sudo install -d -m 0700 /etc/devops-tp
sudo install -m 0600 /ruta/recuperacion/backup.passphrase /etc/devops-tp/backup.passphrase
sudo /opt/devops-tp/bin/oracle restore /ruta/recuperacion/devops-FECHA.tar.gz.gpg --confirm-empty
sudo /opt/devops-tp/bin/oracle health
```

Restore se niega a usar **cualquiera de los cuatro volúmenes de destino existentes**, a reemplazar secretos distintos o a restaurar otra revisión/imágenes. Valida cifrado, checksums y rutas del archivo, restaura SQL y claves antes de iniciar Laravel, conserva APP_KEY y cliente OAuth, y aplica UID 82 y modos Passport. No habilita HTTPS público. Si una restauración falla a mitad, conserva los volúmenes parciales para diagnóstico; el siguiente intento requiere otro namespace vacío o eliminación manual y revisada exclusivamente de esos volúmenes.

Para un ensayo en otro namespace usar `--project NOMBRE` y `--secrets-dir DIRECTORIO` en todas las operaciones. El prefijo de volúmenes se deriva del proyecto. La subred del backup debe estar disponible; no ejecutar en paralelo con la red original si usa el mismo `/24`. El test automático retira la red de origen antes de restaurar, conservando sus volúmenes hasta verificar el destino.

Un rollback de imagen no revierte migraciones. Antes de cualquier actualización detener escrituras, respaldar, revisar compatibilidad de esquema y conservar las referencias anteriores. No implementar rollback SQL automático ni reemplazar imágenes de la release.

## Timers opcionales

Solo después de inicializar y comprobar manualmente un backup:

```bash
sudo install -m 0644 /opt/devops-tp/systemd/devops-tp-*.service /etc/systemd/system/
sudo install -m 0644 /opt/devops-tp/systemd/devops-tp-*.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now devops-tp-health.timer devops-tp-backup.timer
sudo systemctl list-timers 'devops-tp-*'
sudo journalctl -u devops-tp-health.service -u devops-tp-backup.service
```

Salud cada cinco minutos; backup diario alrededor de 03:30 UTC, con retraso aleatorio. Las operaciones se serializan por directorio de secretos; una comprobación concurrente falla sin interferir en un backup. SIGTERM, enviado por systemd al detener o agotar el timeout, deshace el mantenimiento e intenta reanudar todos los consumidores. SIGKILL o pérdida de energía requieren revisión manual. Los fallos quedan en systemd/journal; no se configura un canal externo de alertas ni reinicios automáticos. Después de publicar se puede agregar al servicio de salud `--https-url https://DOMINIO/up` antes de `health`, mediante un override revisado.

## Validación reproducible

Desde la raíz del repositorio, sin secretos reales:

```bash
python3 -B -m unittest discover -s tests/oracle -v
python3 -B scripts/ci/check-oracle.py
```

La primera suite valida Compose, aislamiento, referencias fijadas, secretos, límites, empaquetado, permisos, publicación optativa y el gate PR/release sin necesitar el daemon. La segunda necesita Docker, Python 3.12 y GnuPG: instala y prueba el tar con los permisos documentados, descarga imágenes, usa proyectos/volúmenes aleatorios y una subred libre; publica únicamente puertos efímeros en 127.0.0.1. No hace builds ni accede a OCI/ACME público.

Para probar IP/HTTPS sin cambiar Laravel, monta exclusivamente en el stack temporal una ruta diagnóstica de prueba y utiliza una CA local de Caddy. Verifica TLS con esa CA, rechazo de forwarding falsificado, cierre de métricas, alta sin seeder, persistencia de tokens, fallo SQL, cifrado/restauración, rechazo de sobrescritura y límites efectivos. El `finally` elimina solo recursos del namespace de prueba. CI incorpora ambas suites al gate `CI Required` de Pull Requests; se omite Oracle cuando release reutiliza el workflow con evento push, conservando sus validaciones originales. No se ejecutan despliegues desde Actions.

La validación local con cgroups no reproduce la presión global de un host de 954 MiB, su CPU compartida, DNS público, reglas OCI, renovación ACME ni reinicio completo de la VPS. La aceptación final debe medir esos puntos, probar GIPHY con una clave válida y persistir/recuperar favoritos. Tampoco sustituye una revisión de vulnerabilidades de las imágenes fijadas. Auditoría, tokens expirados y sesiones requieren una política operacional de retención; no se agrega un proceso de scheduler/worker permanente.

## Referencias

- [Docker: memoria y swap](https://docs.docker.com/engine/containers/resource_constraints/).
- [Docker: firewall y UFW](https://docs.docker.com/engine/network/packet-filtering-firewalls/): no confiar en UFW como única barrera para puertos publicados.
- [Caddy: HTTPS automático](https://caddyserver.com/docs/automatic-https) y [forwarding del proxy](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy).
- [MySQL 8.4: variables del servidor](https://dev.mysql.com/doc/refman/8.4/en/server-system-variables.html).
- [Oracle Always Free](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm): las instancias inactivas pueden ser recuperadas; los volúmenes locales no sustituyen backups externos.

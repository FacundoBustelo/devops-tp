#!/usr/bin/env bash
set -euo pipefail
bash "$(dirname "$0")/validate-tag.sh" "${RELEASE_TAG:?}"
: "${DOCKERHUB_NAMESPACE:?}" "${RELEASE_METADATA:?}"
export CI_IMAGE_REPOSITORY="$DOCKERHUB_NAMESPACE/devops-tp" CI_APP_VERSION="${RELEASE_TAG#v}"
source "$(dirname "$0")/common.sh"
for target in runtime nginx; do
    suffix=$target
    [[ $target != runtime ]] || suffix=php
    docker buildx build --platform linux/amd64 --load --provenance=false \
        --target "$target" --build-arg "APP_VERSION=$APP_VERSION" --build-arg "VCS_REF=$VCS_REF" \
        --tag "$IMAGE_REPOSITORY-$suffix:$APP_VERSION" .
done
"${compose[@]}" config --quiet
# Only the third-party database is pulled. Application images must exist locally.
"${compose[@]}" pull mysql
"${compose[@]}" up -d --no-build --pull never --wait --wait-timeout 240
init_id=$("${compose[@]}" ps -a -q init)
test "$(docker inspect --format '{{.State.ExitCode}}' "$init_id")" = 0
"${compose[@]}" exec -T app php scripts/verify-runtime.php
address=$("${compose[@]}" port nginx 8080)
# Run the existing HTTP verifier with the vendor assets from this exact runtime.
mkdir -p "$task_dir/scripts" "$task_dir/docs/openapi" "$task_dir/vendor/swagger-api/swagger-ui"
cp scripts/verify-docs.mjs "$task_dir/scripts/"
cp docs/openapi/openapi.json "$task_dir/docs/openapi/"
"${compose[@]}" cp app:/var/www/html/vendor/swagger-api/swagger-ui/dist "$task_dir/vendor/swagger-api/swagger-ui/dist"
node "$task_dir/scripts/verify-docs.mjs" "http://$address"
python3 - <<'PY'
import json, os, subprocess
from pathlib import Path
images = []
for suffix in ('php', 'nginx'):
    name = f'{os.environ["IMAGE_REPOSITORY"]}-{suffix}:{os.environ["APP_VERSION"]}'
    info = json.loads(subprocess.check_output(['docker', 'image', 'inspect', name]))[0]
    assert (info['Os'], info['Architecture']) == ('linux', 'amd64'), name
    labels = info['Config']['Labels']
    assert labels['org.opencontainers.image.version'] == os.environ['APP_VERSION'], name
    assert labels['org.opencontainers.image.revision'] == os.environ['VCS_REF'], name
    images.append({'image': name, 'id': info['Id'], 'platform': 'linux/amd64'})
metadata = {'version': os.environ['APP_VERSION'], 'revision': os.environ['VCS_REF'], 'images': images}
Path(os.environ['RELEASE_METADATA']).write_text(json.dumps(metadata, indent=2) + '\n')
print(json.dumps(metadata, indent=2))
PY

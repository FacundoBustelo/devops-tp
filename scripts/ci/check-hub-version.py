"""Anonymous, fail-closed preflight: public repositories and absent version tags."""
import json
import os
import re
from urllib.error import HTTPError
from urllib.request import urlopen

namespace = os.environ['DOCKERHUB_NAMESPACE']
tag = os.environ['RELEASE_TAG']
if not re.fullmatch(r'[a-z0-9]+(?:[a-z0-9_-]*[a-z0-9])?', namespace):
    raise SystemExit('Invalid or missing DOCKERHUB_NAMESPACE')
if not re.fullmatch(r'v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', tag):
    raise SystemExit('Invalid stable release tag')

for target in ('php', 'nginx'):
    repository = f'{namespace}/devops-tp-{target}'
    url = f'https://hub.docker.com/v2/namespaces/{namespace}/repositories/devops-tp-{target}'
    with urlopen(url, timeout=30) as response:
        if json.load(response).get('is_private') is not False:
            raise SystemExit(f'{repository} must already exist and be public')
    try:
        with urlopen(f'{url}/tags/{tag[1:]}', timeout=30):
            raise SystemExit(f'{repository}:{tag[1:]} already exists; refusing overwrite')
    except HTTPError as error:
        if error.code != 404:
            raise
    print(f'{repository}:{tag[1:]} is available')

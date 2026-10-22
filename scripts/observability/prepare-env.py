#!/usr/bin/env python3
"""Create private independent Compose credentials; never read or replace .env implicitly."""
import argparse
import base64
import os
import re
from pathlib import Path
import secrets
import uuid


def prepare(path, source=None, project=None):
    if Path(path).resolve().is_relative_to(Path(__file__).resolve().parents[2]):
        raise ValueError('Write the private environment outside the repository.')
    values = {}
    if source:
        # Only the provider key is copied; never reuse database credentials or app keys.
        for line in Path(source).read_text().splitlines():
            if line.startswith('GIPHY_API_KEY='):
                values['GIPHY_API_KEY'] = line.split('=', 1)[1].strip().strip('\"\'')
    project = project or 'devops-obs-' + uuid.uuid4().hex[:10]
    if not re.fullmatch(r'devops-obs-[a-z0-9-]+', project):
        raise ValueError('Use an independent devops-obs-* project name.')
    values.update({
        'COMPOSE_PROJECT_NAME': project,
        'IMAGE_REPOSITORY': project,
        'APP_VERSION': '0.0.0-phase5',
        'APP_ENV': 'local',
        'APP_DEBUG': 'false',
        'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
        'DB_DATABASE': 'prex_observability',
        'DB_USERNAME': 'prex_obs',
        'DB_PASSWORD': secrets.token_hex(24),
        'MYSQL_ROOT_PASSWORD': secrets.token_hex(24),
        'GRAFANA_ADMIN_PASSWORD': secrets.token_hex(32),
        'HTTP_PORT': '0',
        'DB_FORWARD_PORT': '0',
        'GRAFANA_PORT': '0',
        'BCRYPT_ROUNDS': '4',
    })
    with open(path, 'x', opener=lambda name, flags: os.open(name, flags, 0o600)) as output:
        output.write('\n'.join(f'{key}={value}' for key, value in values.items()) + '\n')
    print('Private environment created: ' + str(path))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('path', type=Path)
    parser.add_argument('--giphy-from', type=Path)
    parser.add_argument('--project')
    args = parser.parse_args()
    prepare(args.path, args.giphy_from, args.project)

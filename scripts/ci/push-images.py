# No build command here. Validate identity immediately before pushing each tested image.
import json, os, re, subprocess
from pathlib import Path
path = Path(os.environ['RELEASE_METADATA'])
metadata = json.loads(path.read_text())
summary = os.environ['GITHUB_STEP_SUMMARY']
with open(summary, 'a') as output:
    output.write(f'Version: {metadata["version"]}\n\nRevision: `{metadata["revision"]}`\n\n')
try:
    for image in metadata['images']:
        name = image['image']
        info = json.loads(subprocess.check_output(['docker', 'image', 'inspect', name]))[0]
        if info['Id'] != image['id']:
            raise RuntimeError(f'Tested image changed: {name}')
        subprocess.run(['docker', 'push', name], check=True)
        info = json.loads(subprocess.check_output(['docker', 'image', 'inspect', name]))[0]
        repository = name.rsplit(':', 1)[0]
        digests = [d for d in info['RepoDigests'] if d.startswith(repository + '@sha256:')]
        if len(digests) != 1 or not re.fullmatch(r'.+@sha256:[0-9a-f]{64}', digests[0]):
            raise RuntimeError(f'Missing unique registry digest: {name}')
        image['digest'] = digests[0].split('@', 1)[1]
        with open(summary, 'a') as output:
            output.write(f'- Published `{name}` → `{image["digest"]}` ({image["platform"]})\n')
except BaseException:
    with open(summary, 'a') as output:
        output.write('\n**Release failed. A partial publication must not be retried or overwritten.**\n')
    raise
finally:
    path.write_text(json.dumps(metadata, indent=2) + '\n')
    print(path.read_text())

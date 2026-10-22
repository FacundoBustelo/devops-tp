"""Exercise publication guards with simulated HTTP and Docker, never a real push."""
import contextlib
import io
import json
import os
from pathlib import Path
import runpy
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / 'scripts/ci'


class ReleaseGuardsTest(unittest.TestCase):
    def test_stable_semver(self):
        for tag in ('v0.0.0', 'v1.2.3', 'v100.20.3'):
            self.assertEqual(subprocess.run(['bash', str(SCRIPTS / 'validate-tag.sh'), tag]).returncode, 0)
        for tag in ('', '1.2.3', 'v01.2.3', 'v1.02.3', 'v1.2.03', 'v1.2', 'v1.2.3-rc.1', 'v1.2.3+build', 'v1.2.3\n'):
            with self.subTest(tag=tag):
                self.assertNotEqual(subprocess.run(
                    ['bash', str(SCRIPTS / 'validate-tag.sh'), tag], capture_output=True).returncode, 0)

    def hub(self, responses):
        def request(url, timeout):
            response = next(responses)
            if isinstance(response, Exception):
                raise response
            return io.BytesIO(json.dumps(response).encode())
        with patch.dict(os.environ, {'DOCKERHUB_NAMESPACE': 'example', 'RELEASE_TAG': 'v1.2.3'}), \
                patch('urllib.request.urlopen', side_effect=request), contextlib.redirect_stdout(io.StringIO()):
            runpy.run_path(str(SCRIPTS / 'check-hub-version.py'))

    def test_both_public_repositories_with_absent_tags(self):
        missing = HTTPError('https://example.test', 404, 'absent', {}, None)
        self.hub(iter([{'is_private': False}, missing, {'is_private': False}, missing]))

    def test_existing_or_private_repository_is_rejected(self):
        for responses in ([{'is_private': True}], [{'is_private': False}, {'name': '1.2.3'}]):
            with self.subTest(responses=responses), self.assertRaises(SystemExit):
                self.hub(iter(responses))

    def test_registry_errors_fail_closed(self):
        for code in (401, 403, 429, 500):
            with self.subTest(code=code), self.assertRaises(HTTPError):
                self.hub(iter([{'is_private': False}, HTTPError('https://example.test', code, 'failed', {}, None)]))
        with self.assertRaises(HTTPError):
            self.hub(iter([HTTPError('https://example.test', 404, 'repository absent', {}, None)]))

    def push(self, directory, *, changed=False, partial=False):
        metadata = {'version': '1.2.3', 'revision': 'a' * 40, 'images': [
            {'image': f'example/devops-tp-{suffix}:1.2.3', 'id': f'sha256:{suffix}', 'platform': 'linux/amd64'}
            for suffix in ('php', 'nginx')]}
        path = directory / 'metadata.json'
        path.write_text(json.dumps(metadata))

        def inspect(command):
            name = command[-1]
            suffix = name.split('-')[-1].split(':')[0]
            return json.dumps([{'Id': 'changed' if changed else f'sha256:{suffix}',
                                'RepoDigests': [name.rsplit(':', 1)[0] + '@sha256:' + 'b' * 64]}]).encode()

        def command(args, check):
            self.assertEqual(args[:2], ['docker', 'push'])
            if partial and '-nginx:' in args[-1]:
                raise subprocess.CalledProcessError(1, args)

        environment = {'RELEASE_METADATA': str(path), 'GITHUB_STEP_SUMMARY': str(directory / 'summary')}
        with patch.dict(os.environ, environment), patch('subprocess.check_output', side_effect=inspect), \
                patch('subprocess.run', side_effect=command) as pushed, contextlib.redirect_stdout(io.StringIO()):
            if changed:
                with self.assertRaisesRegex(RuntimeError, 'Tested image changed'):
                    runpy.run_path(str(SCRIPTS / 'push-images.py'))
                self.assertEqual(pushed.call_count, 0)
            elif partial:
                with self.assertRaises(subprocess.CalledProcessError):
                    runpy.run_path(str(SCRIPTS / 'push-images.py'))
            else:
                runpy.run_path(str(SCRIPTS / 'push-images.py'))
                self.assertEqual(pushed.call_count, 2)
        return json.loads(path.read_text()), (directory / 'summary').read_text()

    def test_only_tested_images_are_pushed_and_digests_recorded(self):
        with tempfile.TemporaryDirectory() as directory:
            metadata, summary = self.push(Path(directory))
        self.assertTrue(all(image['digest'] == 'sha256:' + 'b' * 64 for image in metadata['images']))
        self.assertIn(metadata['revision'], summary)

    def test_changed_image_blocks_publication(self):
        with tempfile.TemporaryDirectory() as directory:
            _, summary = self.push(Path(directory), changed=True)
        self.assertIn('Release failed', summary)

    def test_partial_publication_preserves_first_digest_and_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            metadata, summary = self.push(Path(directory), partial=True)
        self.assertIn('digest', metadata['images'][0])
        self.assertNotIn('digest', metadata['images'][1])
        self.assertIn('partial publication', summary)

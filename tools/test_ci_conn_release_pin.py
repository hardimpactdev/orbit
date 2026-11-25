import contextlib
import hashlib
import importlib.machinery
import importlib.util
import io
import json
import plistlib
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]


class ConnReleasePinTest(unittest.TestCase):
    def setUp(self):
        work = self.enterContext(tempfile.TemporaryDirectory())
        self.work = Path(work)
        loader = importlib.machinery.SourceFileLoader('conn_release_pin', str(ROOT / 'bin/conn-release-pin'))
        self.pin = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
        loader.exec_module(self.pin)
        for name in ('CORE', 'HOST_RELEASES', 'CONTRACT', 'GOLDEN'):
            original = getattr(self.pin, name)
            destination = self.work / original.name
            shutil.copyfile(original, destination)
            setattr(self.pin, name, destination)
        self.files = [self.pin.CORE, self.pin.HOST_RELEASES, self.pin.CONTRACT, self.pin.GOLDEN]
        self.before = {path: path.read_bytes() for path in self.files}
        current = self.pin.version_tuple(json.loads(self.before[self.pin.HOST_RELEASES])['default'])
        self.version = '.'.join(map(str, (*current[:2], current[2] + 1)))
        self.commit = 'a' * 40
        self.tag = 'conn-server-v' + self.version
        self.prefix = 'conn-server-' + self.version
        self.base = f'https://github.com/nckrtl/conn/releases/download/{self.tag}/'
        self.binary = self.prefix + '-linux-x64/conn-server'
        self.transcript = f'conn-server {self.version} (T3 server 0.1.10 contract, contract digest 2b83411d59ae)'
        self.pr_body = self.work / 'proposal.md'
        self.assets()
        self.enterContext(patch.object(self.pin.urllib.request, 'urlopen', side_effect=self.open_release))

    def assets(self, appended=b''):
        linux = io.BytesIO()
        binary = f"#!/bin/sh\nprintf '%s\\n' '{self.transcript}'\n".encode() + appended
        with tarfile.open(fileobj=linux, mode='w:gz') as archive:
            member = tarfile.TarInfo(self.binary)
            member.mode = 0o755
            member.size = len(binary)
            archive.addfile(member, io.BytesIO(binary))
        mac = io.BytesIO()
        with zipfile.ZipFile(mac, mode='w') as archive:
            archive.writestr('Conn Server.app/Contents/Info.plist', plistlib.dumps({
                'CFBundleIdentifier': self.pin.BUNDLE, 'CFBundleShortVersionString': self.version,
                'ConnServerCommit': self.commit,
            }))
        self.files_at_url = {}
        self.published = []
        for suffix, content in [('linux-x64.tar.gz', linux.getvalue()), ('macos-arm64.zip', mac.getvalue())]:
            name = self.prefix + '-' + suffix
            digest = hashlib.sha256(content).hexdigest()
            self.publish(name, content)
            self.publish(name + '.sha256', f'{digest}  {name}\n'.encode())
        self.linux_digest = self.published[0]['digest'][7:]

    def publish(self, name, content):
        url = self.base + name
        self.files_at_url[url] = content
        self.published.append({'name': name, 'state': 'uploaded', 'browser_download_url': url,
                               'digest': 'sha256:' + hashlib.sha256(content).hexdigest()})

    def open_release(self, request, timeout):
        url = request.full_url
        if url == 'https://api.github.com/repos/nckrtl/conn/commits/' + self.tag:
            return io.BytesIO(json.dumps({'sha': self.commit}).encode())
        if url == 'https://api.github.com/repos/nckrtl/conn/releases/tags/' + self.tag:
            return io.BytesIO(json.dumps({'tag_name': self.tag, 'draft': False, 'prerelease': False,
                                         'published_at': '2026-01-01', 'assets': self.published}).encode())
        return io.BytesIO(self.files_at_url[url])

    def run_pin(self, *mode):
        args = ['conn-release-pin', self.version, '--source-sha', self.commit, '--pr-body', str(self.pr_body), *mode]
        output = io.StringIO()
        with patch.object(sys, 'argv', args), contextlib.redirect_stdout(output):
            self.pin.main()
        return output.getvalue()

    def propose(self):
        return self.run_pin('--contract', json.dumps({'linux_sha256': self.linux_digest, 'version_output': self.transcript}))

    def test_writes_separate_pins_and_reverifies_the_same_release(self):
        contract = json.loads(self.run_pin('--print-contract'))
        self.assertEqual(contract, {'linux_sha256': self.linux_digest, 'version_output': self.transcript})
        self.assertEqual({path: path.read_bytes() for path in self.files}, self.before)
        with patch.object(self.pin, 'capture_fixture') as capture, patch.object(self.pin.subprocess, 'run') as launch:
            self.assertIn('generated the pin proposal', self.propose())
            self.assertIn('existing pin and launch contract match', self.propose())
            capture.assert_called_once_with()
            launch.assert_called_once()

        catalog = json.loads(self.pin.HOST_RELEASES.read_text())
        self.assertEqual(catalog['default'], self.version)
        self.assertEqual({version: release for version, release in catalog['releases'].items() if version != self.version},
                         json.loads(self.before[self.pin.HOST_RELEASES])['releases'])
        host = catalog['releases'][self.version]
        self.assertIs(host['release_canary'], True)
        self.assertEqual(host['linux_x64']['sha256'], self.linux_digest)
        self.assertEqual(host['macos_arm64']['team_id'], self.pin.TEAM)
        expected_core = json.loads(self.before[self.pin.CORE])
        expected_core['instances']['agent']['conn-server'] = {
            'version': self.version, 'artifacts': {'amd64': host['linux_x64']}, 'bin': {'conn-server': self.binary},
        }
        self.assertEqual(json.loads(self.pin.CORE.read_text()), expected_core)
        self.assertTrue(self.pr_body.exists())

        after = {path: path.read_bytes() for path in self.files}
        self.assets(appended=b'# republished bytes\n')
        with self.assertRaisesRegex(ValueError, 'differs from|differ from'):
            self.propose()
        self.assertEqual({path: path.read_bytes() for path in self.files}, after)

    def test_restores_both_catalogs_and_fixtures_after_a_late_failure(self):
        def capture():
            self.pin.GOLDEN.write_text('recomputed fixture')

        with patch.object(self.pin, 'capture_fixture', side_effect=capture), patch.object(
            self.pin.subprocess, 'run', side_effect=subprocess.CalledProcessError(1, 'launch check'),
        ), self.assertRaises(subprocess.CalledProcessError):
            self.propose()
        self.assertEqual({path: path.read_bytes() for path in self.files}, self.before)
        self.assertFalse(self.pr_body.exists())


if __name__ == '__main__':
    unittest.main()

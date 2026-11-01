"""Real Git payload checks; never create accounts, keys or network listeners on this host."""
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import swamp_proof_repository as repository


def git(path, *args):
    return subprocess.run(['git', '-C', str(path), *args], check=True, capture_output=True, text=True, timeout=10).stdout.strip()


class FixtureRepositoryTest(unittest.TestCase):
    def test_payload_bytes_are_unchanged_by_its_git_attributes(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            payload = root / 'payload'
            payload.mkdir()
            (payload / '.gitattributes').write_text('*.txt text eol=lf\n')
            original = b'one\r\ntwo\r\n'
            (payload / 'input.txt').write_bytes(original)
            bare = root / 'payload.git'
            repository.materialize(payload, bare)
            actual = subprocess.run(['git', '-C', str(bare), 'show', 'main:input.txt'], capture_output=True, check=True, timeout=10).stdout
            self.assertEqual(actual, original)

    def test_bare_repository_has_one_reproducible_main_commit_and_exact_public_files(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            payload = root / 'payload'
            payload.mkdir()
            (payload / 'README.md').write_text('Disposable payload\n')
            (payload / '.gitignore').write_text('owned.env\n')
            (payload / 'owned.env').write_text('PUBLIC_FIXTURE=true\n')
            (payload / 'setup').write_text('#!/bin/sh\nexit 0\n')
            (payload / 'setup').chmod(0o755)
            (payload / 'readme-link').symlink_to('README.md')
            first, second = root / 'one.git', root / 'two.git'
            # Ambient Git state cannot rewrite the payload or start user hooks.
            with patch.dict(os.environ, {'GIT_CONFIG_COUNT': '1', 'GIT_CONFIG_KEY_0': 'core.hooksPath', 'GIT_CONFIG_VALUE_0': '/missing'}):
                commit = repository.materialize(payload, first)
            self.assertEqual(repository.materialize(payload, second), commit)
            self.assertEqual(git(first, 'config', 'http.receivepack'), 'false')
            self.assertEqual(git(first, 'rev-list', '--count', 'main'), '1')
            self.assertEqual(git(first, 'symbolic-ref', 'HEAD'), 'refs/heads/main')
            self.assertEqual(git(first, 'ls-remote', str(first), 'refs/heads/main').split()[0], commit)
            clone = root / 'clone'
            git(root, 'clone', '--quiet', str(first), str(clone))
            self.assertEqual(git(clone, 'ls-files').splitlines(), ['.gitignore', 'README.md', 'owned.env', 'readme-link', 'setup'])
            self.assertEqual((clone / 'owned.env').read_text(), 'PUBLIC_FIXTURE=true\n')
            self.assertEqual((clone / 'setup').stat().st_mode & 0o111, 0o111)
            self.assertEqual(os.readlink(clone / 'readme-link'), 'README.md')

    def test_missing_empty_nested_git_and_existing_destination_refuse_before_materialization(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            payload, destination = root / 'payload', root / 'result.git'
            for state in ('missing', 'empty', 'nested-git'):
                if state == 'empty':
                    payload.mkdir()
                if state == 'nested-git':
                    (payload / 'nested/.git').mkdir(parents=True)
                with self.subTest(state=state), self.assertRaisesRegex(ValueError, 'payload_invalid'):
                    repository.materialize(payload, destination)
                self.assertFalse(destination.exists())
            with self.assertRaisesRegex(ValueError, 'payload_invalid'), patch.object(repository, 'CHECKOUT', root), patch.object(repository, 'REPOSITORIES', destination):
                repository.create('seed', 'run', 'repositories.orbit')
            self.assertFalse(destination.exists())
            valid = root / 'valid'
            valid.mkdir()
            (valid / 'README').write_text('fixture')
            destination.mkdir()
            (destination / 'preserve').write_text('owned')
            with self.assertRaisesRegex(ValueError, 'already_exists'):
                repository.materialize(valid, destination)
            self.assertEqual((destination / 'preserve').read_text(), 'owned')

    def test_per_run_payload_is_readable_via_real_smart_http_and_receive_pack_is_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            payload = root / 'swamp/proof/instance.create/repository'
            payload.mkdir(parents=True)
            (payload / 'README').write_text('public test code\n')
            repos = root / 'public/repositories'
            with patch.object(repository, 'CHECKOUT', root), patch.object(repository, 'REPOSITORIES', repos):
                first = repository.create('instance.create', 'run-one', 'repositories.orbit', 'source')
                second = repository.create('instance.create', 'run-two', 'repositories.orbit', 'source')
                self.assertNotEqual(first['url'], second['url'])
                self.assertEqual(first['commit'], second['commit'])
                self.assertEqual(first['url'], 'https://repositories.orbit/run-one/instance.create-source.git')
                self.assertEqual(first['branch'], 'main')
                for service in ('upload', 'receive'):
                    env = dict(os.environ, GIT_PROJECT_ROOT=str(repos), GIT_HTTP_EXPORT_ALL='1', PATH_INFO='/run-one/instance.create-source.git/info/refs',
                               REQUEST_METHOD='GET', QUERY_STRING='service=git-' + service + '-pack', REMOTE_USER='')
                    response = subprocess.run(['git', 'http-backend'], capture_output=True, env=env, check=False, timeout=10)
                    if service == 'upload':
                        self.assertIn(b'application/x-git-upload-pack-advertisement', response.stdout)
                        self.assertIn(first['commit'].encode(), response.stdout)
                        self.assertIn(b'shallow', response.stdout)
                    else:
                        self.assertIn(b'Status: 403 Forbidden', response.stdout)
                self.assertEqual((repos / 'run-one/instance.create-source.git/config').stat().st_mode & 0o777, 0o644)
                self.assertEqual(repos.stat().st_mode & 0o777, 0o755)

    def test_variant_payload_overrides_shared_payload_and_seed_has_its_own_identity(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            for name in ('seed', 'instance.create', 'instance.create/source'):
                payload = root / 'swamp/proof' / name / 'repository'
                payload.mkdir(parents=True)
                (payload / 'README').write_text(name)
            repos = root / 'public/repositories'
            with patch.object(repository, 'CHECKOUT', root), patch.object(repository, 'REPOSITORIES', repos):
                seed = repository.create('seed', 'run', 'repositories.orbit')
                repository.create('instance.create', 'run', 'repositories.orbit', 'source')
            self.assertEqual(seed['url'], 'https://repositories.orbit/seed.git')
            self.assertEqual(git(repos / 'run/instance.create-source.git', 'show', 'main:README'), 'instance.create/source')


if __name__ == '__main__':
    unittest.main()

"""Disposable source ownership, failed-create and interrupted-finalization regressions."""
import importlib.util
import json
import os
import pwd
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).parent


def module(name):
    spec = importlib.util.spec_from_file_location(name, HERE / 'scripts' / (name + '.py'))
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


class SourceActions(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR'))
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.path = self.root / 'project' / 'proof'
        self.path.mkdir(parents=True)
        self.member = {'instance_id': 17, 'checkout_path': str(self.path), 'source_root': str(self.root), 'source_layout': 'checkout', 'user': pwd.getpwuid(os.geteuid()).pw_name, 'failed_creation': False, 'starting_commit': 'admitted', 'branch': 'main', 'source_prepare_id': 'admitted-prepare'}
        self.observe = module('inspect_source').observe
        self.verify = module('verify_source').verify
        self.finalize = module('finalize_source').finalize
        self.git('init', '--initial-branch=main')
        self.git('config', 'user.email', 'fixture@example.test')
        self.git('config', 'user.name', 'Fixture')
        self.git('remote', 'add', 'origin', 'https://github.com/example/project.git')
        (self.path / 'tracked').write_text('fixture')
        self.git('add', 'tracked')
        self.git('commit', '-qm', 'fixture')
        self.commit = self.git('rev-parse', 'HEAD').strip()
        self.git('update-ref', 'refs/remotes/origin/main', self.commit)

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.path), *args], check=True, capture_output=True, text=True).stdout

    def capture(self):
        info = self.path.stat()
        return {'source_identity': str(info.st_dev)+':'+str(info.st_ino), 'source_digest': 'a'*64, 'source_commit': self.commit, 'common_repository_path': str(self.path)}

    def value(self):
        return {'member': self.member, 'capture': self.capture(), 'admitted_paths': [str(self.path)], 'force': False, 'removal_id': '0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6'}

    def test_clean_published_checkout_verifies_and_finalizes(self):
        value = self.value()
        self.assertTrue(self.verify(value)['ready'])
        self.assertTrue(self.finalize(value)['ready'])
        self.assertFalse(self.path.exists())
        self.assertTrue(self.finalize(value)['ready'])

    def test_dirty_content_is_refused_before_finalization(self):
        (self.path / 'tracked').write_text('changed')
        with self.assertRaises(ValueError): self.verify(self.value())
        self.assertTrue(self.path.exists())

    def test_unpublished_commit_is_refused(self):
        (self.path / 'tracked').write_text('changed')
        self.git('commit', '-qam', 'unpublished')
        self.commit = self.git('rev-parse', 'HEAD').strip()
        with self.assertRaises(ValueError): self.verify(self.value())

    def test_git_fetch_refuses_configured_transport_command_with_private_token(self):
        marker = self.root/'credential-observed'
        shim = self.root/'ssh-probe.py'
        shim.write_text('from pathlib import Path\nPath(' + repr(str(marker)) + ').write_text("ran")\n')
        self.git('config','url.ssh://fixture.invalid/.insteadOf','https://github.com/')
        self.git('config','core.sshCommand',sys.executable+' '+str(shim))
        script = Path(__file__).parent.parent/'instance-source-command/scripts/fetch.py'
        answer = subprocess.run([sys.executable,str(script),str(self.path),'https://github.com/example/project.git'],input=json.dumps({'github_token':'FAKE_NOT_A_CREDENTIAL'}),text=True,capture_output=True,check=False)
        self.assertNotEqual(answer.returncode,0)
        self.assertFalse(marker.exists())
        self.assertNotIn('FAKE_NOT_A_CREDENTIAL',answer.stdout+answer.stderr)

    def test_new_unadmitted_worktree_refuses_before_common_repository_removal(self):
        value = self.value()
        survivor = self.path.with_name('unadmitted')
        self.git('worktree', 'add', '--detach', str(survivor), self.commit)
        with self.assertRaises(ValueError):
            self.verify(value)
        self.assertTrue((survivor/'tracked').is_file())
        self.assertTrue((self.path/'.git').is_dir())

    def test_replaced_inode_is_refused_even_when_forced(self):
        value = self.value()
        self.path.rename(self.path.with_name('original'))
        self.path.mkdir()
        value['force'] = True
        with self.assertRaises(ValueError): self.verify(value)
        with self.assertRaises(ValueError): self.finalize(value)

    def test_symlink_checkout_is_refused(self):
        value = self.value()
        self.path.rename(self.path.with_name('original'))
        self.path.symlink_to(self.path.with_name('original'), target_is_directory=True)
        with self.assertRaises(ValueError): self.finalize(value)
        with self.assertRaises(ValueError): self.observe({'member': self.member, 'force': True, 'repository': 'https://github.com/example/project.git'})

    def test_failed_create_requires_its_own_prepare_marker(self):
        self.member.update(failed_creation=True, starting_commit=None)
        with self.assertRaises(ValueError): self.observe({'member': self.member, 'force': True, 'repository': 'https://github.com/example/project.git'})
        identity = self.capture()['source_identity']
        (self.path / '.git/orbit-source-prepare').write_text('admitted-prepare:'+identity)
        self.assertEqual(self.observe({'member': self.member, 'force': True, 'repository': 'https://github.com/example/project.git'})['instance_id'], 17)

    def test_owned_preinit_directory_can_be_inspected_and_removed(self):
        import shutil
        shutil.rmtree(self.path / '.git')
        (self.path / '.git').mkdir()
        self.member.update(failed_creation=True, starting_commit=None, branch=None)
        (self.path / '.git/orbit-source-prepare').write_text('admitted-prepare:'+self.capture()['source_identity'])
        observed = self.observe({'member': self.member, 'force': False, 'repository': 'https://github.com/example/project.git'})
        import base64
        self.assertEqual(base64.b64decode(observed['source']['commit']), b'')
        value = self.value()
        value['capture']['source_commit'] = ''
        self.assertTrue(self.verify(value)['ready'])
        self.assertTrue(self.finalize(value)['ready'])

    def test_interrupted_quarantine_reuses_only_its_original_receipt(self):
        value = self.value()
        expected = {'instance_id': 17, 'removal_id': value['removal_id'], 'source_digest': 'a'*64, 'identity': value['capture']['source_identity']}
        receipt = self.root / ('.orbit-removal-'+value['removal_id']+'-17.json')
        receipt.write_text(json.dumps(expected))
        quarantine = self.path.with_name(self.path.name+'.orbit-removing-'+value['removal_id'])
        self.path.rename(quarantine)
        self.assertTrue(self.finalize(value)['ready'])
        self.assertFalse(quarantine.exists())

    def test_retained_capture_verifies_after_owned_quarantine(self):
        value = self.value()
        receipt = self.root / ('.orbit-removal-'+value['removal_id']+'-17.json')
        receipt.write_text(json.dumps({'instance_id':17,'removal_id':value['removal_id'],'source_digest':'a'*64,'identity':value['capture']['source_identity']}))
        self.path.rename(self.path.with_name(self.path.name+'.orbit-removing-'+value['removal_id']))
        self.assertTrue(self.verify(value)['ready'])
        self.assertTrue(self.finalize(value)['ready'])

    def test_foreign_quarantine_is_not_deleted(self):
        value = self.value()
        quarantine = self.path.with_name(self.path.name+'.orbit-removing-'+value['removal_id'])
        quarantine.mkdir()
        with self.assertRaises(ValueError): self.finalize(value)
        self.assertTrue(quarantine.exists())


if __name__ == '__main__': unittest.main()

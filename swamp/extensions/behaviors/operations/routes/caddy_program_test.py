import hashlib
import os
from pathlib import Path
import shutil
import tempfile
import types
import unittest
from unittest.mock import patch

source = (Path(__file__).parent.parent / 'certificates/retained_role_policy.py').read_text().split('# BEGIN HOST\n')[1].split('# END HOST')[0].replace('from role_paths import KEY_ROOT, role_paths\n','') + Path(__file__).with_name('caddy-program.ts').read_text().split('String.raw`', 1)[1].rsplit('`;', 1)[0]
module = types.ModuleType('native_caddy')
exec(compile(source, 'caddy-program.ts', 'exec'), module.__dict__)

class Publication(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-native-caddy-')
        self.addCleanup(self.temp.cleanup)
        self.root = module.ROOT = Path(self.temp.name)
        self.active = True
        self.calls = []
        self.refuse = None
        self.release = b'v2.10.2 test'
        self.addresses = b'1: wg0 inet 10.44.0.9/24 scope global\n'
        self.data = (module.MARKER + '\n{ }\n').encode()
        self.input = {'content': self.data.decode(), 'version': module.digest(self.data), 'listen_addresses': ['10.44.0.9'], 'unserved': False}
        module.command = self.command
        module.service = self.service
        for name in ['chown', 'fchown']:
            p = patch.object(module.os, name, lambda *_args: None)
            p.start(); self.addCleanup(p.stop)
        p = patch.object(module.grp, 'getgrnam', lambda _: types.SimpleNamespace(gr_gid=os.getgid()))
        p.start(); self.addCleanup(p.stop)

    def command(self, program, *args):
        self.calls.append((program, args))
        output, code = b'', 0
        if program == '/usr/bin/caddy': output = self.release
        elif program == '/usr/sbin/ip': output = self.addresses
        elif program == '/usr/sbin/runuser': code = 1 if self.refuse == 'validate' else 0
        elif program == '/usr/bin/dpkg-query': code = 1
        elif program == '/usr/bin/cp':
            source, target = map(Path, args[-2:])
            if source.is_dir(): shutil.copytree(source, target, symlinks=True)
            else: shutil.copy2(source, target, follow_symlinks=False)
        else: self.fail('Unexpected command ' + program)
        return types.SimpleNamespace(returncode=code, stdout=output)

    def service(self, *args):
        self.calls.append(('systemctl', args))
        if args[0] == 'is-active': return self.active
        if args[0] == 'reload-or-restart' and self.refuse == 'reload': return False
        return True

    def test_publish_and_unchanged_preserve_version_and_modes(self):
        self.assertEqual(module.publish(self.input), {'result': 'published'})
        live = self.root / 'Caddyfile'
        self.assertTrue(live.is_symlink())
        self.assertEqual(live.read_bytes(), self.data)
        self.assertEqual(live.stat().st_mode & 0o777, 0o640)
        self.assertEqual(live.resolve().parent.stat().st_mode & 0o777, 0o750)
        calls = len(self.calls)
        self.assertEqual(module.publish(self.input), {'result': 'unchanged'})
        self.assertFalse(any(c[0] == 'systemctl' for c in self.calls[calls:]))

    def test_validation_refusal_keeps_live_and_creates_no_backup(self):
        (self.root / 'Caddyfile').write_text('old foreign')
        self.refuse = 'validate'
        with self.assertRaises(module.Refusal) as failure: module.publish(self.input)
        self.assertEqual(failure.exception.stage, 'validate')
        self.assertEqual((self.root / 'Caddyfile').read_text(), 'old foreign')
        self.assertFalse((self.root / 'orbit-backups').exists())
        self.assertFalse(any(c[0] == 'systemctl' for c in self.calls))

    def test_reload_failure_restores_regular_file_symlink_and_absence(self):
        for state in ['regular', 'symlink', 'absent']:
            with self.subTest(state=state):
                live = self.root / 'Caddyfile'; module.remove(live)
                if state == 'regular': live.write_text('keep')
                if state == 'symlink':
                    (self.root / 'outside').write_text('keep')
                    live.symlink_to('outside')
                self.refuse = 'reload'
                with self.assertRaises(module.Refusal): module.publish(self.input)
                if state == 'absent': self.assertFalse(module.exists(live))
                else: self.assertEqual(live.read_text(), 'keep')
                if state == 'symlink': self.assertEqual(os.readlink(live), 'outside')
                self.assertFalse(any(c == ('systemctl', ('restart',)) for c in self.calls))

    def test_foreign_live_file_is_backed_up_before_replacement(self):
        (self.root / 'Caddyfile').write_text('hand edited')
        module.publish(self.input)
        backups = list((self.root / 'orbit-backups').glob('*/Caddyfile'))
        self.assertEqual(len(backups), 1)
        self.assertEqual(backups[0].read_text(), 'hand edited')

    def test_invalid_digest_release_or_address_never_changes_live(self):
        for variant in ['digest', 'release', 'address']:
            with self.subTest(variant=variant):
                self.refuse = None; self.release = b'v2.10.2'; self.addresses = b'1: wg0 inet 10.44.0.9/24\n'
                value = dict(self.input)
                if variant == 'digest': value['version'] = 'a' * 32
                elif variant == 'release': self.release = b'v2.8.9'
                else: self.addresses = b''
                with self.assertRaises(module.Refusal): module.publish(value)
                self.assertFalse((self.root / 'Caddyfile').exists())
                self.assertFalse((self.root / 'orbit-versions').exists())

    def test_prune_keeps_nine_previous_versions_and_backs_up_edited_old_version(self):
        versions = self.root / 'orbit-versions'; versions.mkdir()
        for index in range(12):
            path = versions / f'{index:032x}'; path.mkdir(); (path / 'Caddyfile').write_text('edited')
            os.utime(path, (index, index))
        module.publish(self.input)
        visible = [p for p in versions.iterdir() if not p.name.startswith('.')]
        self.assertEqual(len(visible), 10)
        self.assertEqual(len(list((self.root / 'orbit-backups').iterdir())), 3)

    def test_candidate_symlink_does_not_write_external_target(self):
        versions = self.root / 'orbit-versions'; versions.mkdir()
        external = self.root / 'external'; external.mkdir()
        candidate = versions / ('.' + self.input['version'] + '.candidate'); candidate.symlink_to(external)
        module.publish(self.input)
        self.assertEqual(list(external.iterdir()), [])

if __name__ == '__main__': unittest.main()

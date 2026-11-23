"""Drop the host's dir `default` pool once no runner is on it: its profiles move to orbit-instances, then it goes."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SETUP = Path(__file__).with_name('setup.sh')

# Incus over one JSON state of pools and profile roots; each call is logged.
SHIM = '''#!/usr/bin/env python3
import json, os, sys
path = os.environ['DROP_STATE']
state = json.load(open(path))
args = sys.argv[1:]
open(os.environ['DROP_LOG'], 'a').write(' '.join(args) + '\\n')
if args[0] == 'query':
    name = args[1].removeprefix('/1.0/storage-pools/')
    if name not in state['pools']:
        sys.exit(1)
    print(json.dumps(state['pools'][name]))
elif args[:3] == ['profile', 'device', 'set']:
    state['roots'][args[3] + '@' + args[-1]] = args[5].split('=', 1)[1]
    state['pools']['default']['used_by'].remove('/1.0/profiles/' + args[3] + ('' if args[-1] == 'default' else '?project=' + args[-1]))
elif args[:2] == ['storage', 'delete']:
    if state['pools'][args[2]]['used_by']:
        sys.exit(1)
    del state['pools'][args[2]]
else:
    sys.exit(2)
json.dump(state, open(path, 'w'))
'''

ZFS = {'driver': 'zfs', 'used_by': [], 'config': {}}


def dir_pool(*used_by):
    return {'driver': 'dir', 'used_by': list(used_by), 'config': {}}


class DropDirPoolTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        (self.root / 'incus').write_text(SHIM)
        (self.root / 'incus').chmod(0o755)
        self.state = self.root / 'state.json'
        self.log = self.root / 'log'

    def tearDown(self):
        self.directory.cleanup()

    def drop(self, pools):
        self.state.write_text(json.dumps({'pools': pools, 'roots': {}}))
        self.log.write_text('')
        environment = {**os.environ, 'PATH': f'{self.root}:{os.environ["PATH"]}', 'DROP_STATE': str(self.state),
                       'DROP_LOG': str(self.log)}
        result = subprocess.run(['bash', str(SETUP), 'drop_dir_pool'], env=environment, capture_output=True, text=True)
        return result, json.loads(self.state.read_text())

    def changes(self):
        return [line for line in self.log.read_text().splitlines() if not line.startswith('query ')]

    def test_moves_each_profile_root_to_orbit_instances_and_deletes_the_emptied_dir_pool(self):
        result, state = self.drop({'default': dir_pool('/1.0/profiles/default', '/1.0/profiles/default?project=orbit-ci'),
                                   'orbit-instances': ZFS, 'orbit-ci': ZFS})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('default', state['pools'])
        self.assertEqual(state['roots'], {'default@default': 'orbit-instances', 'default@orbit-ci': 'orbit-ci'})

    def test_refuses_before_any_change_while_a_runner_or_volume_is_still_on_it(self):
        for user in ('/1.0/instances/ci-shark-1?project=orbit-ci', '/1.0/storage-pools/default/volumes/custom/v'):
            with self.subTest(user=user):
                result, state = self.drop({'default': dir_pool('/1.0/profiles/default', user), 'orbit-instances': ZFS})
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(user, result.stderr)
                self.assertEqual(self.changes(), [])
                self.assertIn('default', state['pools'])

    def test_leaves_a_default_pool_that_is_not_dir_and_needs_orbit_instances(self):
        result, state = self.drop({'default': {**ZFS, 'used_by': ['/1.0/profiles/default']}, 'orbit-instances': ZFS})
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('not dir', result.stderr)
        result, state = self.drop({'default': dir_pool('/1.0/profiles/default')})
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.changes(), 'default' in state['pools']), ([], True))

    def test_no_default_pool_is_done(self):
        result, _ = self.drop({'orbit-instances': ZFS})
        self.assertEqual((result.returncode, result.stdout.strip(), self.changes()), (0, 'no default pool', []))


if __name__ == '__main__':
    unittest.main()

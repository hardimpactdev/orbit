"""Prove the empty inode-probe bind works with the shipped systemd sandbox."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import uuid


class LivenessNativeTest(unittest.TestCase):
    def test_metadata_only_bind_keeps_home_hidden_and_read_only(self):
        if not shutil.which('systemd-run') or not Path('/run/systemd/system').exists():
            self.skipTest('a native systemd host is required')
        prefix = [] if os.geteuid() == 0 else ['sudo', '-n']
        subprocess.run([*prefix, 'true'], check=True, capture_output=True)
        with tempfile.TemporaryDirectory(prefix='swamp-inode-probe-', dir=Path.home()) as temp:
            root = Path(temp)
            probe = root / 'empty'
            probe.mkdir(mode=0o700)
            secret = root / 'operator-sentinel'
            secret.write_text('synthetic native test sentinel')
            secret.chmod(0o600)
            expected = os.statvfs(probe)
            # The positive host control must open the real file, without reading it.
            subprocess.run([*prefix, '/usr/bin/python3', '-c',
                            'import os,sys;os.close(os.open(sys.argv[1],os.O_RDONLY))', str(secret)],
                           check=True, capture_output=True)
            script = '''import os,json,sys
p='/run/swamp-datastore-inode-probe';s=os.statvfs(p)
assert not os.listdir(p), 'probe_mount_not_empty'
try: os.open(sys.argv[1],os.O_RDONLY)
except (PermissionError,FileNotFoundError): pass
else: raise RuntimeError('home_sentinel_exposed')
try: os.open(p+'/forbidden',os.O_WRONLY|os.O_CREAT,0o600)
except OSError as e: assert e.errno==30, e
else: raise RuntimeError('probe_mount_writable')
print(json.dumps({'fsid':s.f_fsid,'inodes':s.f_files,'homeDenied':True,'readOnly':True}))
'''
            result = subprocess.run([
                *prefix, 'systemd-run', '--quiet', '--wait', '--pipe',
                '--unit=orbit-swamp-inode-probe-test-' + uuid.uuid4().hex,
                '--property=User=root', '--property=NoNewPrivileges=yes',
                '--property=ProtectSystem=strict', '--property=ProtectHome=yes',
                '--property=PrivateTmp=yes',
                '--property=BindReadOnlyPaths=' + str(probe) + ':/run/swamp-datastore-inode-probe',
                '/usr/bin/python3', '-c', script, str(secret),
            ], capture_output=True, text=True, timeout=20)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            value = json.loads(result.stdout)
            self.assertEqual(value['fsid'], expected.f_fsid)
            self.assertEqual(value['inodes'], expected.f_files)
            self.assertTrue(value['homeDenied'])
            self.assertTrue(value['readOnly'])


if __name__ == '__main__':
    unittest.main()

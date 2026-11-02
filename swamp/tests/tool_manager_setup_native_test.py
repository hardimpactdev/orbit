"""The real manager child executes official cfgmgmt against a disposable host filesystem."""
import json
import os
import pwd
import re
import shutil
import sys
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class ToolManagerSetupNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.user = pwd.getpwuid(os.getuid()).pw_name
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        for package in ('@swamp/ssh', '@swamp/cfgmgmt'):
            self.cli('extension', 'pull', package + '@' + inventory[package]['version'])
        seen = set()

        def copy(source):
            if source in seen or not source.is_file():
                return
            seen.add(source)
            target = self.repo / source.relative_to(ROOT)
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(source, target)
            for name in re.findall(r'(?:from|import)\s*["\'](\.[^"\']+)["\']', source.read_text()):
                copy((source.parent / name).resolve())

        copy(ROOT / 'extensions/behaviors/operations/tools/write.ts')
        copy(ROOT / 'extensions/behaviors/operations/node-bootstrap/tool_setup_assets.ts')
        self.host = self.repo / 'host'
        self.host.mkdir()
        self.scope = self.host / 'composer'
        self.state = self.repo / 'installed'
        self.calls = self.repo / 'calls'
        tools = self.repo / 'tools'
        tools.mkdir()
        self.native_env.update(PATH=str(tools) + ':' + os.environ['PATH'], STATE=str(self.state), CALLS=str(self.calls))
        scripts = {
            'ssh': '''import subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)
command=sys.argv[-1].replace('/opt/orbit/composer',SCOPE).replace('/var/cache/orbit/tool-setup',CACHE).replace('/usr/bin/composer',COMPOSER)
sys.exit(subprocess.call(['/bin/sh','-c',command]))
'''.replace('SCOPE', repr(str(self.scope))).replace('CACHE', repr(str(self.host / 'cache'))).replace('COMPOSER', repr(str(tools / 'composer'))),
            'scp': '''import shutil,sys
shutil.copyfile(sys.argv[-2],sys.argv[-1].split(':',1)[1])
''',
            'sudo': '''import subprocess,sys
a=sys.argv[1:];a=a[a.index('--')+1:]
sys.exit(subprocess.call(a))
''',
            'chown': 'pass\n', 'chgrp': 'pass\n',
            'composer': '''import os,sys
from pathlib import Path
if not Path(os.environ['STATE']).exists():sys.exit(127)
print('Composer version 2.9.5')
''',
            'apt-get': '''import json,os,sys
from pathlib import Path
with open(os.environ['CALLS'],'a') as f:f.write(json.dumps(sys.argv[1:])+'\\n')
if os.environ.get('APT_FAIL')=='1':sys.exit(1)
if 'install' in sys.argv:Path(os.environ['STATE']).write_text('2.9.5-1')
''',
            'dpkg-query': '''import os,sys
from pathlib import Path
p=Path(os.environ['STATE'])
if not p.exists():sys.exit(1)
print('install ok installed '+p.read_text())
''',
        }
        for name, source in scripts.items():
            file = tools / name
            file.write_text('#!' + sys.executable + '\n' + source)
            file.chmod(0o700)
        path = ROOT / 'workflows/workflow-operation-tool-manager-setup.yaml'
        w = yaml.safe_load(path.read_text())
        for job in w['jobs']:
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh':
                    task['globalArgs']['sshBinary'] = str(tools / 'ssh')
        (self.repo / path.relative_to(ROOT)).write_text(yaml.safe_dump(w, sort_keys=False))
        self.input = {'operation_id': 9901, 'node_id': 701,
                      'ssh': {'target': {'host': 'fixture', 'user': self.user, 'port': 22, 'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB']}, 'identity_file': '/fixture/key'},
                      'intent': {'manager': 'composer', 'user': self.user, 'platform': 'linux', 'architecture': 'x86_64',
                                 'setup': {'manager': 'composer', 'input': {'user': self.user, 'version': '2.9.5-1'},
                                           'apt': {'snapshot': '20260101T000000Z', 'packages': [{'name': 'composer', 'version': '2.9.5-1', 'artifact': None}]}, 'packages': []}}}

    def run_setup(self, success=True):
        output = self.cli('workflow', 'run', 'operation-tool-manager-setup', '--input', json.dumps(self.input), success=success)
        return json.loads(output.stdout)

    def test_first_use_installs_exact_packages_and_creates_managed_manifest(self):
        self.run_setup()
        self.assertEqual(json.loads((self.scope / 'composer.json').read_text()), {'require': {}})
        self.assertTrue((self.scope / 'vendor/bin').is_dir())
        calls = [json.loads(x) for x in self.calls.read_text().splitlines()]
        install = next(x for x in calls if 'install' in x)
        self.assertEqual(install[-1], 'composer=2.9.5-1')
        self.assertIn('--no-remove', install)
        self.assertIn('APT::Get::List-Cleanup=false', install)

    def test_existing_composer_requirements_survive_first_use_recovery(self):
        self.scope.mkdir()
        original = '{"require":{"vendor/existing-tool":"^2.0"}}\n'
        (self.scope / 'composer.json').write_text(original)
        self.run_setup()
        self.assertEqual((self.scope / 'composer.json').read_text(), original)

    def test_failed_package_install_never_publishes_manager_readiness(self):
        self.native_env['APT_FAIL'] = '1'
        result = self.run_setup(success=False)
        self.assertEqual(result['status'], 'failed')
        self.assertFalse((self.scope / 'composer.json').exists())

    def test_ready_manager_needs_no_install_or_scope_rewrite(self):
        self.state.write_text('2.9.5-1')
        self.run_setup()
        self.assertFalse(self.calls.exists())
        self.assertFalse(self.scope.exists())


if __name__ == '__main__':
    import unittest
    unittest.main()

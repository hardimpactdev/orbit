"""Pinned native vault output: private templates never enter public run history."""
import json
import unittest

import yaml
from native_harness import NativeTestCase


class EnvironmentNative(NativeTestCase):
    def test_private_template_is_stored_as_native_vault_output(self):
        self.copy_behaviors('operations/instance-dev-command')
        dependency=self.repo/'extensions/behaviors/operations/instance-deploy';dependency.mkdir(parents=True)
        import shutil

        from native_harness import ROOT
        shutil.copy(ROOT/'extensions/behaviors/operations/instance-deploy/dotenv.ts',dependency/'dotenv.ts')
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        template = self.repo / 'template'
        template.write_text('TEMPLATE_SECRET="template-private-sentinel"\n')
        base = json.dumps({'values': {'STORED': 'stored-private-sentinel'}, 'name': 'Example', 'framework_key': None})
        self.cli('vault', 'put', 'orbit-control-inputs', 'base', base)
        flow = {'id':'f10229c1-3637-463b-b6ed-4f01bd8124e0','name':'instance-env-proof','vaults':['orbit-control-inputs'],'jobs':[{'name':'environment','steps':[{'name':'capture','task':{'type':'behavior','behaviorType':'@nckrtl/instance-create-environment','behaviorName':'environment-proof','methodName':'capture','inputs':{'path':str(template),'env':{'ORBIT_ENVIRONMENT':"${{ vault.get('orbit-control-inputs','base') }}"},'profile':{'laravel':True},'reference':{'vault':'orbit-control-inputs','key':'instance-create-environment-result-71'},'directory':'/tmp/fixture'}}}]}]}
        (self.repo / 'workflows/workflow-proof.yaml').write_text(yaml.safe_dump(flow,sort_keys=False))
        reply = self.cli('workflow','run','instance-env-proof')
        run = json.loads(reply.stdout)
        history = self.cli('workflow','history','get',run['id']).stdout
        self.assertNotIn('template-private-sentinel', history)
        self.assertNotIn('stored-private-sentinel', history)
        self.assertFalse(template.exists())
        self.assertIn('instance-create-environment-result-71', history)


if __name__ == '__main__':
    unittest.main()

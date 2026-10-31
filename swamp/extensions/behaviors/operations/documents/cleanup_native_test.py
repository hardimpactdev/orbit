"""Pinned native steps execute the exact cleanup helper against a disposable filesystem."""
import json
from pathlib import Path
import shutil
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[4]))
from tests.native_harness import NativeTestCase
ROOT=Path(__file__).resolve().parents[4]
class CleanupNative(NativeTestCase):
    def test_native_parent_composes_cleanup_without_gateway_or_agent(self):
        self.copy_behaviors('operations/documents')
        node=self.repo/'extensions/behaviors/operations/node';node.mkdir()
        (node/'system_command.ts').write_text('''export type SystemTransport=(input:any,signal?:AbortSignal)=>Promise<{success:boolean;stdout:string}>;
export const localSystemCommand:SystemTransport=async(command)=>{
 let program=command.args[5].replace("ROOT=pathlib.Path('/run/orbit')","ROOT=pathlib.Path("+JSON.stringify(Deno.cwd()+"/scratch")+")").replace("ROOT_UID=0","ROOT_UID=os.getuid()").replace("ROOT_GID=0","ROOT_GID=os.getgid()").replace("pwd.getpwnam('orbit')","pwd.getpwuid(os.geteuid())");
 const result=await new Deno.Command('/usr/bin/python3',{args:['-I','-c',program],stdout:'piped',stderr:'null'}).output();
 return {success:result.success,stdout:new TextDecoder().decode(result.stdout)};
};''')
        self.cli('workflow','schema','get');self.cli('workflow','create','cleanup-parent')
        path=self.repo/'workflows/workflow-cleanup-parent.yaml'
        identity=path.read_text().split('id: ',1)[1].splitlines()[0]
        path.write_text(f'''id: {identity}
name: cleanup-parent
jobs:
  - name: startup
    steps:
      - name: pause-before-fpm
        writes: true
        task:
          type: behavior
          behaviorType: '@nckrtl/documents-cleanup'
          behaviorName: documents-cleanup-gateway
          methodName: prepare
          inputs: {{}}
      - name: pause-before-scheduler
        writes: true
        task:
          type: behavior
          behaviorType: '@nckrtl/documents-cleanup'
          behaviorName: documents-cleanup-gateway
          methodName: prepare
          inputs: {{}}
''')
        self.cli('workflow','validate','cleanup-parent')
        self.cli('workflow','run','cleanup-parent')
        directory=self.repo/'scratch/project-documents'
        state=json.loads((directory/'generation.json').read_text())
        self.assertEqual(state['lock_inode'],(directory/'execution.lock').stat().st_ino)
        self.assertIsNone(state['report_id']);self.assertFalse((directory/'permit.json').exists())
if __name__=='__main__':unittest.main()

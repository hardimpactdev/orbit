"""Pinned native removal steps; all filesystem effects use isolated scratch paths."""
from pathlib import Path
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[4]))
from tests.native_harness import NativeTestCase
class SourceNative(NativeTestCase):
    def test_parent_removes_only_fixed_sources(self):
        self.copy_behaviors('operations/ai-router')
        node=self.repo/'extensions/behaviors/operations/node';node.mkdir()
        (node/'system_command.ts').write_text('''export type SystemTransport=(input:any,signal?:AbortSignal)=>Promise<{success:boolean;stdout:string}>;
export const localSystemCommand:SystemTransport=async(command)=>{
 const program=command.args[5].replace("ROOT=pathlib.Path('/var/lib/orbit')","ROOT=pathlib.Path("+JSON.stringify(Deno.cwd()+"/scratch")+")");
 const child=new Deno.Command('/usr/bin/python3',{args:['-I','-c',program],stdin:'piped',stdout:'piped',stderr:'null'}).spawn();
 const writer=child.stdin.getWriter();await writer.write(new TextEncoder().encode(command.stdin));await writer.close();
 const result=await child.output();return {success:result.success,stdout:new TextDecoder().decode(result.stdout)};
};''')
        for name in ['ai-router','proxycli','unrelated']:
            path=self.repo/'scratch'/name;path.mkdir(parents=True);(path/'collector.py').write_text('source')
        self.cli('workflow','schema','get');self.cli('workflow','create','source-parent')
        path=self.repo/'workflows/workflow-source-parent.yaml'
        identity=path.read_text().split('id: ',1)[1].splitlines()[0]
        path.write_text(f'''id: {identity}
name: source-parent
jobs:
  - name: retirement
    steps:
      - name: remove-current
        writes: true
        task:
          type: behavior
          behaviorType: '@nckrtl/ai-router-source'
          behaviorName: ai-router-source-node-17
          methodName: remove
          inputs: {{retired: false}}
      - name: remove-retired
        writes: true
        task:
          type: behavior
          behaviorType: '@nckrtl/ai-router-source'
          behaviorName: ai-router-source-node-17
          methodName: remove
          inputs: {{retired: true}}
''')
        self.cli('workflow','validate','source-parent');self.cli('workflow','run','source-parent')
        self.assertEqual([p.name for p in (self.repo/'scratch').iterdir()],['unrelated'])
if __name__=='__main__':unittest.main()

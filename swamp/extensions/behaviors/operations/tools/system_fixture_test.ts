/** Load the shipped embedded Python modules; observe refusals without expectations. */
export async function python(source: string) {
    const root = new URL(".", import.meta.url).pathname;
    const result = await new Deno.Command("python3", {
        args: ["-I", "-c", `ROOT=${JSON.stringify(root)}\n` + setup + source],
        stdout: "piped",
        stderr: "piped",
        signal: AbortSignal.timeout(5000),
    }).output();
    if (!result.success) {
        throw new Error(new TextDecoder().decode(result.stderr));
    }
    return JSON.parse(new TextDecoder().decode(result.stdout));
}
const setup = String.raw`import io,json,os,tarfile,tempfile,types
from pathlib import Path
from unittest.mock import patch
M=types.ModuleType('native_tools_test')
program=''.join((Path(ROOT)/name).read_text().split('String.raw'+chr(96),1)[1].rsplit(chr(96)+';',1)[0] for name in ('system_program.ts','materialize_program.ts','local.ts'))
exec(compile(program,'native-tools','exec'),M.__dict__)
candidate=types.SimpleNamespace(pw_uid=2101,pw_gid=2102,pw_name='candidate',pw_dir='/home/candidate')
class Host:
 def __init__(self,manager='apt'):
  self.calls=[];self.node=dict(manager=manager,platform='linux',architecture='x86_64',user='candidate');self.account=types.SimpleNamespace(pw_uid=os.getuid(),pw_gid=os.getgid(),pw_name='candidate',pw_dir='/home/candidate');self.home=self.account.pw_dir
 def run(self,argv,**options):self.calls.append((argv,options));return (0,'','')
def error(fn):
 try:return fn()
 except Exception as e:return [type(e).__name__,getattr(e,'code',None)]
`;

/** Real Metrics subprocesses with fake sudo and persistent fake ownership in a private tree. */
import { toFileUrl } from "jsr:@std/path@1.1.4";
import type { Runner } from "./metrics_system.ts";

export async function metricsRunnerFixture(root?: string) {
  const directory = await Deno.makeTempDir({ prefix: "metrics-runner-fixture-" });
  const sudo = directory + "/sudo";
  await Deno.writeTextFile(sudo, '#!/bin/sh\n[ "$1" = -n ] && [ "$2" = -- ] || exit 64\nshift 2\nexec "$@"\n', { mode: 0o700 });
  let source = await Deno.readTextFile(new URL("./metrics_system.ts", import.meta.url));
  source = source.replace(/from "(\.[^"]+)"/g, (_, path) => `from ${JSON.stringify(new URL(path, new URL("./metrics_system.ts", import.meta.url)).href)}`)
    .replace('command: "/usr/bin/sudo"', `command: ${JSON.stringify(sudo)}`);
  const module = directory + "/metrics.ts";
  await Deno.writeTextFile(module, source);
  const native: Runner = (await import(toFileUrl(module).href)).runMetricsProcess;
  const run: Runner = (program, args, options) => {
    if (root && program === "/usr/bin/python3" && args[0] === "-c") {
      // Ownership alone is simulated; file bytes, modes, renames and fsync remain real.
      const ownership = root + "/fixture-owners.json";
      const prelude = `import os,json
_owner_file=${JSON.stringify(ownership)}
try:
 with open(_owner_file) as _f:_owners=json.load(_f)
except FileNotFoundError:_owners={}
_stat,_fstat,_replace=os.stat,os.fstat,os.replace
def _save():
 with open(_owner_file,'w') as _f:json.dump(_owners,_f)
def _chown(path,uid,gid):
 _owners[str(path)]=[uid,gid];_save()
def _fchown(fd,uid,gid):_chown(os.readlink('/proc/self/fd/'+str(fd)),uid,gid)
def _meta(value,path):
 fields=list(value)
 if str(path) in _owners:fields[4:6]=_owners[str(path)]
 return os.stat_result(fields)
def _rename(source,target):
 _replace(source,target)
 if str(source) in _owners:_owners[str(target)]=_owners.pop(str(source));_save()
os.chown=_chown;os.fchown=_fchown;os.replace=_rename
os.stat=lambda path,*a,**kw:_meta(_stat(path,*a,**kw),path)
os.fstat=lambda fd:_meta(_fstat(fd),os.readlink('/proc/self/fd/'+str(fd)))
`;
      args = ["-c", prelude + args[1], ...args.slice(2)];
    }
    return native(program, args, options);
  };
  return { run, async [Symbol.asyncDispose]() { await Deno.remove(directory, { recursive: true }); } };
}

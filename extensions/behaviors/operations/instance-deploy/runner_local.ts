import { localCommand } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";

const Runner = z.string().regex(
  /^orbit-deploy-step:[1-9][0-9]{0,15}:[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}:(?:setup|teardown|before_activation|after_activation)\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/,
);

/** Read-only exact argv/inherited-marker scan. Unreadable processes never prove absence. */
export const runnerProbeProgram = String.raw`
import os,re
def inspect_runner(proc,runner):
 if not re.fullmatch(r'orbit-deploy-step:[1-9][0-9]{0,15}:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:(?:setup|teardown|before_activation|after_activation)\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?',runner): raise ValueError()
 def bounded(path):
  with open(path,'rb') as f: value=f.read(2097153)
  if len(value)>2097152: raise ValueError()
  return value
 mounts=bounded(os.path.join(proc,'mounts')).decode('utf-8')
 options=next((line.split()[3] for line in mounts.splitlines() if len(line.split())>=4 and line.split()[1:3]==['/proc','proc']),None)
 if options is None or any(v.startswith('hidepid=') and v!='hidepid=0' for v in options.split(',')): raise ValueError()
 marker=runner.encode(); inherited=b'ORBIT_DEPLOY_STEP_RUNNER='+marker; count=0
 for pid in os.listdir(proc):
  if not pid.isascii() or not pid.isdigit(): continue
  count+=1
  if count>32768: raise ValueError()
  directory=os.path.join(proc,pid)
  for name,expected in [('cmdline',marker),('environ',inherited)]:
   try: value=bounded(os.path.join(directory,name))
   except OSError:
    # A process racing to exit is absent only once its entire directory is gone.
    if not os.path.lexists(directory): break
    raise
   if value and not value.endswith(b'\0'): raise ValueError()
   if expected in value.split(b'\0'): return dict(runner=runner,gone=False)
 return dict(runner=runner,gone=True)
`;

export async function inspectDeployRunner(
  runner: string,
  signal?: AbortSignal,
) {
  Runner.parse(runner);
  signal?.throwIfAborted();
  const child = localCommand("/usr/bin/sudo", {
    args: [
      "-n",
      "--",
      "/usr/bin/python3",
      "-I",
      "-c",
      runnerProbeProgram +
      "\nimport sys,json\ntry: print(json.dumps(inspect_runner('/proc',json.load(sys.stdin)['runner'])))\nexcept BaseException: sys.exit(1)\n",
    ],
    clearEnv: true,
    env: { PATH: "/usr/bin:/bin", LANG: "C.UTF-8" },
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: signal
      ? AbortSignal.any([signal, AbortSignal.timeout(30000)])
      : AbortSignal.timeout(30000),
  }).spawn();
  try {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify({ runner })));
    await writer.close();
    const result = await child.output();
    if (!result.success || result.stdout.length > 2048) {
      throw new Error("deployment.runner_probe_unconfirmed");
    }
    return z.object({ runner: z.literal(runner), gone: z.boolean() }).strict()
      .parse(JSON.parse(new TextDecoder().decode(result.stdout)));
  } finally {
    try {
      child.kill("SIGTERM");
    } catch { /* Finished. */ }
    await child.status;
  }
}

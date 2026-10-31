import { z } from "npm:zod@4.4.3";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const Input = z.object({
  node_id: z.number().int().positive(),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  configuration: z.string().max(65536),
}).strict();
export const EnrollmentWorkerReady = Input.omit({ configuration: true }).extend(
  {
    ready: z.literal(true),
  },
).strict();

/** Read-only fixed checks. Native target+label selects this worker; the canonical
 * command runner selects its effect host, including a configured proxy worker.
 */
export async function verifyEnrollmentWorker(
  raw: z.infer<typeof Input>,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
) {
  const input = Input.parse(raw);
  signal?.throwIfAborted();
  try {
    const user = await invoke({
      command: "/usr/bin/id",
      args: ["-un"],
      timeoutMs: 10000,
      maxOutputBytes: 4096,
    }, signal);
    if (!user.success || user.stdout.trim() !== input.user) throw new Error();
    const result = await invoke({
      command: "/usr/bin/sudo",
      args: [
        "-n",
        "--",
        "/usr/bin/python3",
        "-c",
        enrollmentReadinessProgram(),
      ],
      stdin: JSON.stringify({
        agent_sha256: input.agent_sha256,
        configuration: input.configuration,
      }),
      timeoutMs: 30000,
      maxOutputBytes: 4096,
    }, signal);
    if (!result.success) throw new Error();
    z.object({ ready: z.literal(true) }).strict().parse(
      JSON.parse(result.stdout),
    );
    return EnrollmentWorkerReady.parse({
      node_id: input.node_id,
      user: input.user,
      agent_sha256: input.agent_sha256,
      ready: true,
    });
  } catch {
    throw new Error("node.enrollment_worker_unready");
  }
}

export function enrollmentReadinessProgram(root = "/", uid = 0): string {
  return `ROOT=${JSON.stringify(root)}\nUID=${uid}\n` + String.raw`
import hashlib,json,os,stat,sys
try:
 i=json.load(sys.stdin)
 if os.geteuid()!=UID: raise ValueError()
 for path,mode in [('/usr/local/bin/orbit-agent',0o755),('/etc/orbit/agent/config.toml',0o644)]:
  metadata=os.lstat(os.path.join(ROOT,path.lstrip('/')))
  if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid!=UID or stat.S_IMODE(metadata.st_mode)!=mode: raise ValueError()
 with open(os.path.join(ROOT,'usr/local/bin/orbit-agent'),'rb') as file:
  if hashlib.file_digest(file,'sha256').hexdigest()!=i['agent_sha256']: raise ValueError()
 with open(os.path.join(ROOT,'etc/orbit/agent/config.toml'),'rb') as file:
  if file.read(65537)!=i['configuration'].encode(): raise ValueError()
 print('{"ready":true}')
except Exception:
 print('{"ready":false}')
`;
}

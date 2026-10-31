import { z } from "npm:zod@4.4.3";
import { commandOutput } from "./node-command-process.ts";

const Root = "/etc/orbit/node-proxy";
import {
  proxyCgroupPattern,
  ProxyIdentity as Config,
} from "./node-proxy-config.ts";
export type NodeProxy = z.infer<typeof Config>;

/** Only the systemd unit's cgroup selects a proxy; workflow input and env cannot. */
export function proxyNode(cgroup: string): 1 | 18 | null {
  const match = new RegExp(proxyCgroupPattern, "m").exec(cgroup);
  if (!match) {
    if (cgroup.includes("orbit-node-proxy-worker-")) {
      throw new Error("Unrecognized proxy cgroup");
    }
    return null;
  }
  if (match[1] !== "1" && match[1] !== "18") {
    throw new Error("Unsupported Node proxy identity");
  }
  return Number(match[1]) as 1 | 18;
}

// The compiled native runtime permits fixed subprocesses, not direct /proc reads.
// This program reads public metadata only. Its child inherits the worker cgroup.
export const proxyMetadataProgram = `import os,sys,json,stat,re
with open('/proc/self/cgroup') as f:
 cgroup=f.read(65537)
if len(cgroup)>65536:raise RuntimeError('cgroup too large')
match=re.search(${JSON.stringify(proxyCgroupPattern)},cgroup,re.M)
if match is None:
 if 'orbit-node-proxy-worker-' in cgroup:raise RuntimeError('unrecognized proxy cgroup')
 print('null');sys.exit(0)
node=int(match.group(1))
if node not in (1,18):raise RuntimeError('unsupported proxy')
root='/etc/orbit/node-proxy'
for path,directory in [('/etc',True),('/etc/orbit',True),(root,True),(root+'/'+str(node)+'.json',False),(root+'/known_hosts',False)]:
 s=os.lstat(path)
 if s.st_uid!=0 or s.st_mode&0o022 or not (stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode)):raise RuntimeError('unsafe custody')
with open(root+'/'+str(node)+'.json') as f:
 data=f.read(4097)
if len(data)>4096:raise RuntimeError('configuration too large')
config=json.loads(data)
if config.get('node_id')!=node:raise RuntimeError('proxy identity changed')
print(json.dumps(config))
`;

export async function runtimeNodeProxy(): Promise<NodeProxy | null> {
  if (Deno.build.os !== "linux") return null;
  const result = await commandOutput(
    { command: "/usr/bin/python3", args: ["-c", proxyMetadataProgram] },
    undefined,
    4096,
    AbortSignal.timeout(8000),
  );
  if (!result.success) throw new Error("Node proxy metadata unavailable");
  const value = JSON.parse(new TextDecoder().decode(result.stdout));
  return value === null ? null : Config.parse(value);
}

const quote = (value: string) => "'" + value.replaceAll("'", "'\\''") + "'";

/** Public fixed argv only. SSH reads the root-only key; private bodies use stdin. */
export function proxyLaunch(
  proxy: NodeProxy,
  command: string[],
  deadlineMs = 60000,
) {
  if (!Number.isSafeInteger(deadlineMs) || deadlineMs <= 0) {
    throw new Error("Invalid proxy command deadline");
  }
  const config = Config.parse(proxy);
  return {
    command: "/usr/bin/sudo",
    args: [
      "-n",
      "--",
      "/usr/bin/ssh",
      "-F",
      "/dev/null",
      "-T",
      "-o",
      "BatchMode=yes",
      "-o",
      "IdentitiesOnly=yes",
      "-o",
      "IdentityAgent=none",
      "-o",
      "ForwardAgent=no",
      "-o",
      "UpdateHostKeys=no",
      "-o",
      "VerifyHostKeyDNS=no",
      "-o",
      "HostKeyAlgorithms=ssh-ed25519",
      "-o",
      "ClearAllForwardings=yes",
      "-o",
      "StrictHostKeyChecking=yes",
      "-o",
      `UserKnownHostsFile=${Root}/known_hosts`,
      "-o",
      "GlobalKnownHostsFile=/dev/null",
      "-o",
      "ConnectTimeout=8",
      "-o",
      "ConnectionAttempts=1",
      "-o",
      "ServerAliveInterval=10",
      "-o",
      "ServerAliveCountMax=2",
      "-o",
      "LogLevel=ERROR",
      "-i",
      `${Root}/id_ed25519`,
      `${config.user}@${config.host}`,
      [
        "/usr/bin/sudo",
        "-n",
        "--",
        "/usr/bin/timeout",
        "--signal=TERM",
        "--kill-after=5s",
        `${deadlineMs / 1000}s`,
        "/usr/bin/sudo",
        "-n",
        "-u",
        config.user,
        "--",
        ...command,
      ].map(quote).join(" "),
    ],
  };
}

export { commandOutput } from "./node-command-process.ts";

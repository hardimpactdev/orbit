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

const curlQuote = (value: string) => JSON.stringify(value);
/** Keep Gateway peer authentication on the effect Node, without an identity header. */
export function proxyHttpConfig(
  url: string,
  method: string,
  path: string,
  body: unknown,
  key: string | undefined,
  timeoutMs: number,
  caFile: string,
): string {
  if (!Number.isSafeInteger(timeoutMs) || timeoutMs <= 0) {
    throw new Error("Invalid proxy Gateway timeout");
  }
  const target = new URL(path, url);
  if (
    target.origin !== "https://gateway.orbit" ||
    !target.pathname.startsWith("/api/v1/") ||
    !["GET", "POST", "PUT", "PATCH"].includes(method) ||
    key !== undefined && /[\r\n]/.test(key)
  ) throw new Error("Invalid proxy Gateway request");
  return [
    `url = ${curlQuote(target.href)}`,
    `request = ${curlQuote(method)}`,
    `cacert = ${curlQuote(caFile)}`,
    'proto = "=https"',
    "silent",
    "show-error",
    `max-time = ${Math.ceil(timeoutMs / 1000)}`,
    "connect-timeout = 8",
    'header = "Accept: application/json"',
    ...(key ? [`header = ${curlQuote(`Idempotency-Key: ${key}`)}`] : []),
    ...(body === undefined ? [] : [
      'header = "Content-Type: application/json"',
      `data-binary = ${curlQuote(JSON.stringify(body))}`,
    ]),
    'write-out = "\\n%{http_code}\\t%{content_type}"',
    "",
  ].join("\n");
}

export async function proxyGatewayRequest(
  proxy: NodeProxy,
  url: string,
  caFile: string,
  method: string,
  path: string,
  body?: unknown,
  key?: string,
  signal?: AbortSignal,
  timeoutMs = 60000,
): Promise<{ status: number; body: unknown }> {
  const timeout = AbortSignal.timeout(timeoutMs + 1000);
  const result = await commandOutput(
    proxyLaunch(proxy, ["/usr/bin/curl", "--config", "-"], timeoutMs),
    new TextEncoder().encode(
      proxyHttpConfig(url, method, path, body, key, timeoutMs, caFile),
    ),
    268435456 + 1024,
    signal ? AbortSignal.any([signal, timeout]) : timeout,
  );
  if (!result.success) {
    throw new Error("The proxy Gateway request did not complete");
  }
  const bytes = result.stdout;
  const end = bytes.lastIndexOf(10);
  const metadata = /^(\d{3})\t([^\r\n]*)$/.exec(
    new TextDecoder().decode(bytes.subarray(end + 1)),
  );
  if (end < 0 || !metadata) throw new Error("Invalid proxy Gateway response");
  const data = bytes.slice(0, end);
  return {
    status: Number(metadata[1]),
    body: metadata[2].split(";")[0] === "application/octet-stream"
      ? data
      : JSON.parse(new TextDecoder().decode(data)),
  };
}

import { z } from "npm:zod@4.4.3";
import { type NodeCommand, run } from "../operations/_lib/node-commands.ts";
import { availableMemory, Swamp, type WorkerCredentials } from "./install.ts";
import {
  metadata,
  owned,
  privateDirectory,
  publishRootFile,
  required,
  text,
} from "./files.ts";

export { ProxyTarget } from "../operations/_lib/node-proxy-config.ts";
import { ProxyTarget } from "../operations/_lib/node-proxy-config.ts";
const root = "/etc/orbit/node-proxy";
const key = root + "/id_ed25519";

export async function knownHost(target: ProxyTarget): Promise<string> {
  const v = ProxyTarget.parse(target);
  const wire = Uint8Array.from(
    atob(v.host_key.split(" ")[1]),
    (c) => c.charCodeAt(0),
  );
  if (
    wire.length !== 51 || new DataView(wire.buffer).getUint32(0) !== 11 ||
    new TextDecoder().decode(wire.slice(4, 15)) !== "ssh-ed25519" ||
    new DataView(wire.buffer).getUint32(15) !== 32
  ) throw new Error("Invalid recorded host key");
  const hash = new Uint8Array(await crypto.subtle.digest("SHA-256", wire));
  const fingerprint = "SHA256:" +
    btoa(String.fromCharCode(...hash)).replace(/=+$/, "");
  if (fingerprint !== v.host_fingerprint) {
    throw new Error("Recorded SSH host fingerprint mismatch");
  }
  return `${v.host} ${v.host_key}\n`;
}

const identityProgram = `import os,stat,tomllib
p='/etc/orbit/agent/config.toml';s=os.lstat(p)
if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode&0o022:raise RuntimeError('unsafe identity')
with open(p,'rb') as f:config=tomllib.load(f)
if config.get('swamp_worker')!={'node_id':209,'user':'orbit'}:raise RuntimeError('proxy workers belong on Node209')
`;

export async function assertProxyHost(invoke: NodeCommand) {
  await required(invoke, ["/usr/bin/python3", "-c", identityProgram]);
}

/** Only public bytes leave this call. The dedicated private key is created on209. */
export async function prepareProxyKey(
  invoke: NodeCommand = run,
): Promise<string> {
  await assertProxyHost(invoke);
  const memory = availableMemory(
    text(await required(invoke, ["/usr/bin/cat", "/proc/meminfo"])),
  );
  if (memory < 2 * (Swamp.bytes + Swamp.headroom)) {
    throw new Error("Insufficient RAM for two proxy workers");
  }
  for (const path of ["/etc", "/etc/orbit"]) {
    const m = await metadata(invoke, path);
    if (
      !m || m.kind !== "directory" || m.uid !== 0 || m.gid !== 0 ||
      (m.mode & 0o022) !== 0
    ) throw new Error("Unsafe proxy parent custody");
  }
  const dir = await metadata(invoke, root);
  if (dir !== null && !owned(dir, "directory", 0o755)) {
    throw new Error("Unsafe proxy directory");
  }
  if (dir === null) {
    await required(invoke, [
      "/usr/bin/install",
      "-d",
      "-m",
      "0755",
      "-o",
      "0",
      "-g",
      "0",
      root,
    ]);
  }
  const old = await metadata(invoke, key);
  if (old === null) {
    await required(invoke, [
      "/usr/bin/ssh-keygen",
      "-q",
      "-t",
      "ed25519",
      "-N",
      "",
      "-C",
      "orbit-light-node-proxy",
      "-f",
      key,
    ]);
  }
  if (!owned(await metadata(invoke, key), "file", 0o600)) {
    throw new Error("Unsafe proxy key custody");
  }
  const publicKey = text(
    await required(invoke, ["/usr/bin/ssh-keygen", "-y", "-f", key]),
  ).trim();
  if (!/^ssh-ed25519 [A-Za-z0-9+/]+={0,2}$/.test(publicKey)) {
    throw new Error("Invalid proxy public key");
  }
  return publicKey;
}

export function proxyUnit(node: 1 | 18): string {
  if (node !== 1 && node !== 18) throw new Error("Unsupported proxy Node");
  const state = `/var/lib/orbit-node-proxy-${node}`;
  const credentials = `${root}/credentials/${node}`;
  return `[Unit]\nDescription=Orbit SSH proxy worker for Node ${node}\nAfter=network-online.target\nWants=network-online.target\nStartLimitIntervalSec=0\n\n[Service]\nType=exec\nUser=orbit\nWorkingDirectory=${state}\nEnvironment=HOME=${state} SWAMP_HOME=${state}/home SWAMP_CONFIG_DIR=${state}/config\nLoadCredential=enrollment:${credentials}/enrollment.token\nLoadCredential=server:${credentials}/server.token\nExecStart=/usr/local/bin/swamp worker connect wss://swamp.orbit --token-file=%d/enrollment --server-token-file=%d/server --ca-cert=/etc/orbit/agent/ca.pem --cache-dir=${state}/cache --label node=${node} --label pool=light-node-proxy\nRestart=on-failure\nRestartSec=15\nKillMode=mixed\nSendSIGKILL=no\nTimeoutStopSec=infinity\nUMask=0077\nStandardOutput=journal\nStandardError=journal\n\n[Install]\nWantedBy=multi-user.target\n`;
}

/** Called on209 after public-key authorization on both exact approved targets. */
export async function installProxyWorkers(
  targets: readonly ProxyTarget[],
  credentials: Readonly<Record<1 | 18, WorkerCredentials>>,
  invoke: NodeCommand = run,
) {
  const parsed = targets.map((v) => ProxyTarget.parse(v));
  if (parsed.length !== 2 || new Set(parsed.map((v) => v.node_id)).size !== 2) {
    throw new Error("Both exact proxy Nodes required");
  }
  const known = (await Promise.all(parsed.map(knownHost))).join("");
  for (const v of parsed) {
    for (
      const [name, value] of [[
        `orbit-node-${v.node_id}`,
        credentials[v.node_id].enrollment_token,
      ], [
        `orbit-node-${v.node_id}-access`,
        credentials[v.node_id].server_token,
      ]]
    ) {
      if (
        value.length > 4096 || !value.startsWith(name + ".") ||
        !/^[a-z0-9-]+\.[A-Za-z0-9_-]{16,4096}$/.test(value)
      ) throw new Error("Proxy credential identity mismatch");
    }
  }
  await prepareProxyKey(invoke);
  if (
    !owned(await metadata(invoke, "/usr/local/bin/swamp"), "file", 0o755) ||
    text(
        await required(invoke, ["/usr/bin/sha256sum", "/usr/local/bin/swamp"]),
      ) !== `${Swamp.sha256}  /usr/local/bin/swamp\n`
  ) throw new Error("Pinned existing Swamp binary required on209");
  const account = text(
    await required(invoke, ["/usr/bin/getent", "passwd", "orbit"]),
  ).trim().split(":");
  const uid = Number(account[2]), gid = Number(account[3]);
  if (
    account.length !== 7 || account[0] !== "orbit" ||
    !Number.isSafeInteger(uid) || uid <= 0 || !Number.isSafeInteger(gid) ||
    gid <= 0
  ) throw new Error("Invalid209 worker account");
  await publishRootFile(invoke, root + "/known_hosts", known, 0o644);
  await privateDirectory(invoke, root + "/credentials", 0, 0);
  for (const v of parsed) {
    const dir = `${root}/credentials/${v.node_id}`;
    await privateDirectory(invoke, dir, 0, 0);
    await publishRootFile(
      invoke,
      dir + "/enrollment.token",
      credentials[v.node_id].enrollment_token,
      0o600,
    );
    await publishRootFile(
      invoke,
      dir + "/server.token",
      credentials[v.node_id].server_token,
      0o600,
    );
    await privateDirectory(
      invoke,
      `/var/lib/orbit-node-proxy-${v.node_id}`,
      uid,
      gid,
    );
    await publishRootFile(
      invoke,
      `/etc/systemd/system/orbit-node-proxy-worker-${v.node_id}.service`,
      proxyUnit(v.node_id),
      0o644,
    );
  }
  await required(invoke, ["/usr/bin/systemctl", "daemon-reload"]);
  for (const v of parsed) {
    const unit = `orbit-node-proxy-worker-${v.node_id}.service`;
    await required(invoke, ["/usr/bin/systemctl", "enable", unit]);
    await required(invoke, ["/usr/bin/systemctl", "reset-failed", unit]);
    // Publish managed selection only after every prerequisite and unit are installed.
    await publishRootFile(
      invoke,
      `${root}/${v.node_id}.json`,
      JSON.stringify({ node_id: v.node_id, host: v.host, user: v.user }) + "\n",
      0o644,
    );
    await required(invoke, ["/usr/bin/systemctl", "start", "--no-block", unit]);
  }
  return { configured: true, node_ids: [1, 18] };
}

// Bootstrap uses the existing managed SSH authority. Only the new public key crosses hosts.
export const authorizeProgram = `import os,sys,json,pwd,stat,tempfile,subprocess
p=json.load(sys.stdin)
if os.geteuid()!=0 or p['node_id'] not in (1,18) or p['user']!='orbit':raise RuntimeError('invalid target')
expected='10.44.0.1' if p['node_id']==1 else '10.44.0.13'
addresses=json.loads(subprocess.check_output(['/usr/sbin/ip','-j','address','show'],timeout=8))
if not any(a.get('local')==expected for d in addresses for a in d.get('addr_info',[])):raise RuntimeError('wrong effect Node')
state=subprocess.run(['/usr/bin/systemctl','is-enabled','orbit-node-worker.service'],capture_output=True,text=True,timeout=8)
if state.stdout.strip()!='masked':raise RuntimeError('local worker must remain masked')
state=subprocess.check_output(['/usr/bin/systemctl','show','orbit-node-worker.service','-p','MainPID','--value'],timeout=8)
if state.strip()!=b'0':raise RuntimeError('local worker still running')
account=pwd.getpwnam('orbit');home=account.pw_dir
s=os.lstat(home)
if not stat.S_ISDIR(s.st_mode) or s.st_uid!=account.pw_uid or s.st_mode&0o022:raise RuntimeError('unsafe home')
directory=home+'/.ssh'
if not os.path.lexists(directory):os.mkdir(directory,0o700);os.chown(directory,account.pw_uid,account.pw_gid)
s=os.lstat(directory)
if not stat.S_ISDIR(s.st_mode) or s.st_uid!=account.pw_uid or stat.S_IMODE(s.st_mode)!=0o700:raise RuntimeError('unsafe ssh directory')
path=directory+'/authorized_keys';old=b''
if os.path.lexists(path):
 s=os.lstat(path)
 if not stat.S_ISREG(s.st_mode) or s.st_uid!=account.pw_uid or s.st_nlink!=1 or s.st_mode&0o077:raise RuntimeError('unsafe authorized keys')
 with open(path,'rb') as f:old=f.read(1048577)
 if len(old)>1048576:raise RuntimeError('authorized keys too large')
line=('restrict,from="10.44.0.15" '+p['public_key']+' orbit-light-node-proxy').encode()
if line not in old.splitlines():
 fd,temp=tempfile.mkstemp(prefix='.orbit-proxy-',dir=directory)
 try:
  os.fchmod(fd,0o600);os.fchown(fd,account.pw_uid,account.pw_gid)
  with os.fdopen(fd,'wb') as f:f.write(old+(b'\\n' if old and not old.endswith(b'\\n') else b'')+line+b'\\n');f.flush();os.fsync(f.fileno())
  os.replace(temp,path)
 finally:
  if os.path.exists(temp):os.unlink(temp)
print(json.dumps({'authorized':True,'node_id':p['node_id']}))
`;

export async function authorizeProxyKey(
  target: ProxyTarget,
  publicKey: string,
  invoke: NodeCommand,
) {
  const input = ProxyTarget.parse(target);
  await knownHost(input);
  if (!/^ssh-ed25519 [A-Za-z0-9+/]+={0,2}$/.test(publicKey)) {
    throw new Error("Invalid public key");
  }
  const result = await required(
    invoke,
    ["/usr/bin/python3", "-c", authorizeProgram],
    new TextEncoder().encode(
      JSON.stringify({
        node_id: input.node_id,
        user: input.user,
        public_key: publicKey,
      }),
    ),
  );
  const proof = z.object({
    authorized: z.literal(true),
    node_id: z.literal(input.node_id),
  }).strict().parse(JSON.parse(text(result)));
  return proof;
}

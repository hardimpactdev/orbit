import type { NodeCommand } from "./command.ts";

export const utf8 = (value: string) => new TextEncoder().encode(value);
export const text = (value: Uint8Array) => new TextDecoder().decode(value);
export async function required(
  invoke: NodeCommand,
  command: string[],
  stdin?: Uint8Array,
) {
  const result = await invoke(command, stdin, "root", { deadlineMs: 600000 });
  if (!result.success) throw new Error("swamp.node_worker_failed");
  return result.stdout;
}

const inspect = `import os,sys,json,stat
try:
 s=os.lstat(sys.argv[1]);print(json.dumps({'uid':s.st_uid,'gid':s.st_gid,'mode':stat.S_IMODE(s.st_mode),'kind':'directory' if stat.S_ISDIR(s.st_mode) else 'file' if stat.S_ISREG(s.st_mode) else 'other'}))
except FileNotFoundError: print('null')
`;
export type Metadata = { uid: number; gid: number; mode: number; kind: string };
export async function metadata(
  invoke: NodeCommand,
  path: string,
): Promise<Metadata | null> {
  return JSON.parse(
    text(await required(invoke, ["/usr/bin/python3", "-c", inspect, path])),
  );
}
export function owned(
  value: Metadata | null,
  kind: string,
  mode: number,
  uid = 0,
  gid = 0,
) {
  return value !== null && value.kind === kind && value.uid === uid &&
    value.gid === gid && value.mode === mode;
}

/** Only the known empty directory left by the old installer may change owner. */
export async function privateDirectory(
  invoke: NodeCommand,
  path: string,
  uid: number,
  gid: number,
) {
  let old = await metadata(invoke, path);
  if (path === "/var/lib/orbit-node-worker" && owned(old, "directory", 0o755)) {
    await required(invoke, ["/usr/bin/rmdir", "--", path]);
    old = null;
  }
  if (old !== null && !owned(old, "directory", 0o700, uid, gid)) {
    throw new Error("swamp.node_worker_invalid");
  }
  await required(invoke, [
    "/usr/bin/install",
    "-d",
    "-m",
    "0700",
    "-o",
    String(uid),
    "-g",
    String(gid),
    "--",
    path,
  ]);
}

// Public program; private file bytes are base64 inside stdin, never argv or env.
export const publishProgram = `import os,sys,json,stat,tempfile,base64
p=json.load(sys.stdin); path=p['path']; mode=p['mode']; data=base64.b64decode(p['bytes'],validate=True)
if os.geteuid()!=0: raise RuntimeError('root required')
parent=os.path.dirname(path); current='/'
for part in parent.strip('/').split('/'):
 current=os.path.join(current,part);s=os.lstat(current)
 if not stat.S_ISDIR(s.st_mode) or s.st_uid!=0 or s.st_mode&0o022: raise RuntimeError('unsafe parent')
if os.path.lexists(path):
 s=os.lstat(path)
 if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode&0o022: raise RuntimeError('unsafe file')
changed=not os.path.exists(path) or open(path,'rb').read()!=data
fd,temp=tempfile.mkstemp(prefix='.orbit-',dir=parent)
try:
 os.fchmod(fd,mode);os.fchown(fd,0,0)
 with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
 os.replace(temp,path)
finally:
 if os.path.exists(temp):os.unlink(temp)
print(json.dumps({'changed':changed}))
`;
export async function publishRootFile(
  invoke: NodeCommand,
  path: string,
  content: string,
  mode: number,
) {
  const encoded = btoa(
    Array.from(utf8(content), (b) => String.fromCharCode(b)).join(""),
  );
  const result = JSON.parse(
    text(
      await required(
        invoke,
        ["/usr/bin/python3", "-c", publishProgram],
        utf8(JSON.stringify({ path, bytes: encoded, mode })),
      ),
    ),
  );
  if (typeof result.changed !== "boolean") {
    throw new Error("swamp.node_worker_failed");
  }
  return result.changed as boolean;
}

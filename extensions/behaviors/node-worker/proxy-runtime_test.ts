import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { attestProxyRuntime, runtimeProgram } from "./proxy-runtime.ts";
import { runtimeFixture } from "./proxy-runtime.fixture.ts";
import type { NodeCommand } from "../operations/_lib/node-commands.ts";
import { prepareProxyKey } from "./proxy-install.ts";
import { activate } from "./proxy.ts";
import { availableMemory, Swamp } from "./install.ts";

Deno.test("proxy budget uses the actual artifact while ordinary installers retain canonical pin size", async () => {
  const invoke: NodeCommand = (argv) =>
    Promise.resolve({
      success: true,
      code: 0,
      stdout: new TextEncoder().encode(
        argv[0] === "/usr/bin/python3"
          ? JSON.stringify(runtimeFixture)
          : "MemAvailable: 524289 kB\n",
      ),
    });
  assertEquals(await attestProxyRuntime(invoke), runtimeFixture);
  assertEquals(availableMemory("MemAvailable: 524289 kB\n", 128), 536871936);
  assertEquals(Swamp.sha256 === runtimeFixture.sha256, false);
  let refused = false;
  try {
    availableMemory("MemAvailable: 524289 kB\n");
  } catch {
    refused = true;
  }
  assertEquals(refused, true);
});

for (const stage of ["prepare", "activate"] as const) {
  Deno.test(`${stage} refuses runtime mismatch before all key, authorization and issuance effects`, async () => {
    const calls: string[][] = [];
    let issued = false;
    const invoke: NodeCommand = (argv) => {
      calls.push([...argv]);
      if (argv[0] !== "/usr/bin/python3" || argv[2] !== runtimeProgram) {
        throw new Error("effect before attestation");
      }
      return Promise.resolve({
        success: true,
        code: 0,
        stdout: new TextEncoder().encode(JSON.stringify(runtimeFixture)),
      });
    };
    const expected = { ...runtimeFixture, sha256: "d".repeat(64) };
    await assertRejects(() =>
      stage === "prepare"
        ? prepareProxyKey(invoke, expected)
        : activate(expected, invoke, () => {
          issued = true;
          throw new Error("unexpected issuance");
        })
    );
    assertEquals(calls.length, 1);
    assertEquals(issued, false);
  });
}

for (
  const field of [
    "pid",
    "invocation",
    "bytes",
    "inode",
    "device",
    "unit_sha256",
  ] as const
) {
  Deno.test(`runtime receipt refuses ${field} drift`, async () => {
    const changed = {
      ...runtimeFixture,
      [field]: typeof runtimeFixture[field] === "number"
        ? 999
        : "d".repeat(field === "invocation" ? 32 : 64),
    };
    const invoke: NodeCommand = () =>
      Promise.resolve({
        success: true,
        code: 0,
        stdout: new TextEncoder().encode(JSON.stringify(changed)),
      });
    await assertRejects(() => attestProxyRuntime(invoke, runtimeFixture));
  });
}

// Execute the actual fixed Python program against an in-memory host. These
// failures exercise its OS checks, not a mock success response from the runner.
const harness = String
  .raw`import io,os,sys,stat,json,types,hashlib,builtins,pwd,subprocess
program=sys.argv[1];case=sys.argv[2]
unit='/etc/systemd/system/orbit-node-worker.service';binary='/usr/local/bin/swamp'
before={'LoadState':'loaded','ActiveState':'active','SubState':'running','MainPID':'123','User':'orbit','FragmentPath':unit,'DropInPaths':'','InvocationID':'a'*32}
if case=='inactive':before['ActiveState']='inactive'
if case=='unitpath':before['FragmentPath']='/tmp/worker.service'
if case=='user':before['User']='root'
if case=='dropin':before['DropInPaths']='/tmp/override.conf'
def check_output(*args,**kwargs):return ''.join(k+'='+v+'\n' for k,v in before.items())
subprocess.check_output=check_output
pwd.getpwnam=lambda n:types.SimpleNamespace(pw_uid=1000,pw_gid=1000)
os.geteuid=lambda:0
def metadata(path):
 directory=path not in [unit,binary,'/etc/orbit/agent/config.toml']
 mode=(stat.S_IFDIR|(0o700 if path=='/etc/orbit/agent' else 0o755)) if directory else stat.S_IFREG|(0o755 if path==binary else 0o644)
 if case=='symlink' and path==binary:mode=stat.S_IFLNK|0o777
 return types.SimpleNamespace(st_uid=1000 if case=='owner' and path==binary else 0,st_gid=0,st_mode=mode,st_dev=1,st_ino=2,st_size=128,st_mtime_ns=1,st_ctime_ns=1)
os.lstat=metadata
os.readlink=lambda p:'/tmp/swamp' if case=='exepath' else binary
class File(io.BytesIO):
 def __init__(self,path,data):super().__init__(data);self.path=path
 def fileno(self):return 2 if self.path.endswith('/exe') else 1
def fstat(fd):
 s=metadata(binary)
 if case=='size' and fd==2:s.st_size=127
 if case=='inode' and fd==2:s.st_ino=3
 return s
os.fstat=fstat
def open_file(path,mode='r',*args,**kwargs):
 if path=='/etc/orbit/agent/config.toml':data=b'[swamp_worker]\nnode_id=209\nuser="orbit"\n' if case!='node' else b'[swamp_worker]\nnode_id=9\nuser="orbit"\n'
 elif path.endswith('/status'):data=('Uid:\t'+('0 0 0 0' if case=='processuser' else '1000 1000 1000 1000')+'\n').encode()
 elif path.endswith('/cgroup'):data=b'0::/system.slice/'+(b'wrong.service' if case=='cgroup' else b'orbit-node-worker.service')+b'\n'
 elif path.endswith('/cmdline'):data=bytes([0]).join([binary.encode(),b'worker',b'connect',b'--label',b'node=9' if case=='argv' else b'node=209'])
 else:data=(b'x' if case=='digest' and path.endswith('/exe') else b'a')*128
 return File(path,data) if 'b' in mode else io.StringIO(data.decode())
builtins.open=open_file
exec(program)
`;

for (
  const scenario of [
    "ok",
    "inactive",
    "unitpath",
    "user",
    "dropin",
    "symlink",
    "owner",
    "exepath",
    "size",
    "inode",
    "node",
    "processuser",
    "cgroup",
    "argv",
    "digest",
  ]
) {
  Deno.test(`fixed read-only runtime attestation: ${scenario}`, async () => {
    const result = await new Deno.Command("/usr/bin/python3", {
      args: ["-c", harness, runtimeProgram, scenario],
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(
      result.success,
      scenario === "ok",
      new TextDecoder().decode(result.stderr),
    );
    if (scenario === "ok") {
      const proof = JSON.parse(new TextDecoder().decode(result.stdout));
      assertEquals(proof.pid, 123);
      assertEquals(proof.bytes, 128);
      assertEquals(proof.node_id, 209);
    }
  });
}

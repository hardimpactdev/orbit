import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  installObserver,
  ObserverInstallProgram,
} from "./observer_install_system.ts";

const pin = "a".repeat(64);
async function python(test: string) {
  const child = new Deno.Command("/usr/bin/python3", {
    args: [
      "-c",
      "import json,sys\nns={'__name__':'observer_test'}\nexec(json.load(sys.stdin),ns)\n" +
      test,
    ],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(
    new TextEncoder().encode(JSON.stringify(ObserverInstallProgram)),
  );
  await writer.close();
  const output = await child.output();
  assertEquals(output.success, true, new TextDecoder().decode(output.stderr));
}

Deno.test("observer delivery uses one placed system call and data-only stdin", async () => {
  assertEquals(
    await installObserver("https://example.test/observer", pin, (input) => {
      assertEquals(input.command, "/usr/bin/sudo");
      assertEquals(input.args, [
        "-n",
        "--",
        "/usr/bin/python3",
        "-c",
        ObserverInstallProgram,
      ]);
      assertEquals(JSON.parse(input.stdin!), {
        url: "https://example.test/observer",
        sha256: pin,
      });
      assertEquals(input.timeoutMs, 300000);
      return Promise.resolve({ success: true, stdout: '{"changed":true}' });
    }),
    { changed: true },
  );
});

Deno.test("observer delivery rejects credentials, HTTP, control characters and invalid pins before transport", async () => {
  const invoke = () => {
    throw new Error("transport must not execute");
  };
  for (
    const url of [
      "http://example.test/a",
      "https://user:pass@example.test/a",
      "https://example.test/a#secret",
      "https://example.test/a\n",
      "https://example.test/\\a",
    ]
  ) {
    await assertRejects(() => installObserver(url, pin, invoke));
  }
  await assertRejects(() =>
    installObserver("https://example.test/a", "invalid", invoke)
  );
  await assertRejects(() =>
    installObserver(null, pin, invoke, AbortSignal.abort())
  );
});

Deno.test("observer transport failures and invalid output expose no unbounded diagnostic", async () => {
  await assertRejects(
    () =>
      installObserver(null, pin, () =>
        Promise.resolve({
          success: false,
          stdout: "private-looking-diagnostic",
        })),
    Error,
    "Observer installation has no confirmed outcome",
  );
  await assertRejects(
    () =>
      installObserver(null, pin, () =>
        Promise.resolve({
          success: true,
          stdout: '{"changed":true,"unknown":"private-looking-diagnostic"}',
        })),
    Error,
    "Observer installation returned no bounded result",
  );
  assertEquals(
    await installObserver(null, pin, () =>
      Promise.resolve({
        success: true,
        stdout: '{"changed":false,"error_code":"agent.release_unavailable"}',
      })),
    { changed: false, error_code: "agent.release_unavailable" },
  );
});

const isolatedInstaller = String.raw`
import pathlib,tempfile,os,hashlib,io
directory=tempfile.TemporaryDirectory();root=pathlib.Path(directory.name)
for path in ['usr','usr/local','usr/local/bin','run/lock']:(root/path).mkdir(parents=True,exist_ok=True)
program=ns['INSTALL_PROGRAM']
for path in ['/usr/local/bin/orbit-agent','/usr/local/bin','/usr/local','/usr','/run/lock/orbit-self-update.lock','/run/lock']:
 program=program.replace(repr(path),repr(str(root/path.lstrip('/'))))
program=program.replace('s.st_uid!=0','s.st_uid!=os.getuid()').replace('os.fchown(fd,0,0)','os.fchown(fd,os.getuid(),os.getgid())')
ns['INSTALL_PROGRAM']=program
target=root/'usr/local/bin/orbit-agent';target.write_bytes(b'previous');target.chmod(0o755)
candidate=root/'candidate';candidate.write_bytes(b'pinned observer');pin=hashlib.sha256(candidate.read_bytes()).hexdigest()
`;

Deno.test("canonical observer installer atomically publishes pinned bytes and leaves matching inode unchanged", async () => {
  await python(
    isolatedInstaller + String.raw`
assert ns['install_candidate'](candidate,pin)=={'replaced':True,'sha256':pin}
assert target.read_bytes()==b'pinned observer'
assert target.stat().st_mode&0o777==0o755
inode=target.stat().st_ino
assert ns['install_candidate'](candidate,pin)=={'replaced':False,'sha256':pin}
assert target.stat().st_ino==inode
assert not list(target.parent.glob('.orbit-observer-*'))
directory.cleanup()
`,
  );
});

Deno.test("canonical observer installer refuses symlink or writable custody and keeps previous bytes", async () => {
  await python(
    isolatedInstaller + String.raw`
bad='b'*64
assert ns['install_candidate'](candidate,bad)=={'error_code':'node.bootstrap_install_failed'}
assert target.read_bytes()==b'previous'
candidate.unlink();candidate.symlink_to(target)
assert ns['install_candidate'](candidate,pin)=={'error_code':'node.bootstrap_install_failed'}
candidate.unlink();candidate.write_bytes(b'pinned observer')
target.chmod(0o777)
assert ns['install_candidate'](candidate,pin)=={'error_code':'node.bootstrap_install_failed'}
assert target.read_bytes()==b'previous'
target.chmod(0o755);target.parent.chmod(0o777)
assert ns['install_candidate'](candidate,pin)=={'error_code':'node.bootstrap_install_failed'}
assert target.read_bytes()==b'previous'
directory.cleanup()
`,
  );
});

Deno.test("canonical installer checks the staged copy again before replacing the observer", async () => {
  await python(
    isolatedInstaller + String.raw`
original=pathlib.Path.open;reads=0
def altered(self,*args,**kwargs):
 global reads
 if self==candidate:
  reads+=1
  if reads==2:
   with original(self,'wb') as changed:changed.write(b'changed after first checksum')
 return original(self,*args,**kwargs)
pathlib.Path.open=altered
try:assert ns['install_candidate'](candidate,pin)=={'error_code':'node.bootstrap_install_failed'}
finally:pathlib.Path.open=original
assert reads==2 and target.read_bytes()==b'previous'
assert not list(target.parent.glob('.orbit-observer-*'))
directory.cleanup()
`,
  );
});

const downloadFixture = String.raw`
import pathlib,tempfile,hashlib,io
class Response:
 def __init__(self,status=200,data=b'pinned observer',headers=None):self.status=status;self.data=io.BytesIO(data);self.headers=headers or {};self.reads=[]
 def __enter__(self):return self
 def __exit__(self,*args):self.data.close()
 def read(self,size):self.reads.append(size);return self.data.read(size)
`;

Deno.test("effect-side download streams fixed chunks and checks HTTPS redirects and digest", async () => {
  await python(
    downloadFixture + String.raw`
seen=[];body=Response()
def opened(url,timeout):
 seen.append(url)
 return Response(302,headers={'Location':'/final'}) if len(seen)==1 else body
ns['open_url']=opened
with tempfile.TemporaryDirectory() as d:
 p=pathlib.Path(d)/'candidate';pin=hashlib.sha256(b'pinned observer').hexdigest()
 ns['download']('https://example.test/start',p,pin)
 assert p.read_bytes()==b'pinned observer' and seen==['https://example.test/start','https://example.test/final']
 assert body.reads==[65536,65536]
 p.unlink();ns['open_url']=lambda url,timeout:Response()
 try:ns['download']('https://example.test/start',p,'a'*64);raise AssertionError('bad digest accepted')
 except ns['Refusal']:pass
`,
  );
});

Deno.test("effect-side download refuses insecure redirects, oversized streams and redirect loops", async () => {
  await python(
    downloadFixture + String.raw`
with tempfile.TemporaryDirectory() as d:
 p=pathlib.Path(d)/'candidate';pin=hashlib.sha256(b'pinned observer').hexdigest()
 seen=[]
 def loop(url,timeout):seen.append(url);return Response(302,headers={'Location':'/next'})
 ns['open_url']=loop
 try:ns['download']('https://example.test/start',p,pin);raise AssertionError('loop accepted')
 except ns['Refusal']:pass
 assert len(seen)==6 and not p.exists()
 ns['open_url']=lambda url,timeout:Response(302,headers={'Location':'http://example.test/next'})
 try:ns['download']('https://example.test/start',p,pin);raise AssertionError('insecure redirect accepted')
 except ns['Refusal']:pass
 assert not p.exists()
 ns['MAX_BYTES']=5;ns['open_url']=lambda url,timeout:Response()
 try:ns['download']('https://example.test/start',p,pin);raise AssertionError('stream limit ignored')
 except ns['Refusal']:pass
 assert p.stat().st_size==0
`,
  );
});

Deno.test("observer delivery refuses unsupported effect architecture before download or installation", async () => {
  await python(String.raw`
ns['platform'].machine=lambda:'aarch64'
ns['install_candidate']=lambda *args:(_ for _ in ()).throw(AssertionError('installer called'))
try:ns['install']({'url':'https://example.test/observer','sha256':'a'*64});raise AssertionError('unsupported architecture accepted')
except ns['Refusal'] as e:assert e.code=='agent.platform_unsupported'
`);
});

Deno.test("canonical installer accepts only the root-owned sticky shared lock directory", async () => {
  await python(
    isolatedInstaller + String.raw`
(root/'run/lock').chmod(0o1777)
assert ns['install_candidate'](candidate,pin)=={'replaced':True,'sha256':pin}
target.write_bytes(b'previous')
(root/'run/lock').chmod(0o0777)
assert ns['install_candidate'](candidate,pin)=={'error_code':'node.bootstrap_install_failed'}
assert target.read_bytes()==b'previous'
directory.cleanup()
`,
  );
});

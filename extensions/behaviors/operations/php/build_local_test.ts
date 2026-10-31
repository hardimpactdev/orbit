import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
  installPhpBuild,
  InstallPhpBuildInput,
  PhpLocalError,
} from "./build_local.ts";
import { phpBuildProgram } from "./build_program.ts";
import { buildFixture } from "./build_test_fixture.ts";
import { pythonFixture } from "./test_fixture.ts";

Deno.test("PHP build exact downloads, origins, metadata, package rollback and disabled pools", async () => {
  await pythonFixture(
    phpBuildProgram,
    buildFixture + String.raw`
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));i=input_build();h.mirror_missing=True
 # Each missing mirror artifact retries its original HTTPS origin. Never install an index package.
 out=install_build(i,h)
 assert out['outcome']=='applied' and out['data']['changed']==['php8.5-cli','php8.5-fpm']
 assert len([c for c in h.calls if c[0]=='curl'])==2
 apt=[c for c in h.calls if c[0]=='apt-get'];assert len(apt)==1 and '--no-remove' in apt[0] and '--allow-downgrades' in apt[0]
 assert all(p.endswith('.deb') for p in apt[0][apt[0].index('--')+1:])
 assert not h.path('/usr/sbin/policy-rc.d').exists()
 assert out['data']['restarted']==['php8.5-fpm.service','orbit-example-php8.5-fpm.service']
 # Repeat uses cache/versions without downloading or touching masters.
 h.calls.clear();out=install_build(i,h)
 assert out['outcome']=='current' and not any(c[0] in ('curl','apt-get') for c in h.calls)
 assert not out['data']['restarted'] and not out['data']['reloaded']
for corrupt,metadata in ((True,False),(False,True)):
 with tempfile.TemporaryDirectory() as tmp:
  h=Fake(P(tmp));h.bad_digest=corrupt;h.bad_metadata=metadata
  # For metadata mismatch use the digest of the wrong architecture to isolate the metadata guard.
  i=input_build()
  if metadata:
   for p in i['versions']['8.5']['packages']:p['sha256']=hashlib.sha256(artifact(p['name'],'2.0','arm64')).hexdigest()
  try:install_build(i,h);raise AssertionError('bad download accepted')
  except Refused as e:assert e.code=='php.build_download_failed'
  assert not any(c[0]=='apt-get' for c in h.calls)
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));i=input_build();h.packages={'php8.5-cli':'1.0','php8.5-fpm':'1.0'}
 for n in h.packages:h.cache(n,'1.0')
 # Keep a formerly disabled default pool disabled through both apply and rollback.
 remove(h.path('/etc/php/8.5/fpm/pool.d/www.conf'));h.fail_install=True
 before=module_links(h,'8.5')
 try:install_build(i,h);raise AssertionError('failed apt accepted')
 except Refused as e:assert e.code=='php.build_install_failed' and e.details['restored']
 assert h.packages=={'php8.5-cli':'1.0','php8.5-fpm':'1.0'} and module_links(h,'8.5')==before
 assert not h.path('/etc/php/8.5/fpm/pool.d/www.conf').exists()
 assert not h.path('/usr/sbin/policy-rc.d').exists()
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));i=input_build();h.packages={'php8.5-cli':'1.0','php8.5-fpm':'1.0'}
 # Uncached installed versions must be repacked, not fetched from a moving apt index.
 out=install_build(i,h)
 assert len(h.repack)==2 and all(not p.exists() for p in h.repack)
 assert len(list(h.path(CACHE).glob('*_*.deb')))==4
 assert [c for c in h.calls if c[0]=='apt-get' and '--' not in c][0][-1]=='dpkg-repack=1.0'
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));h.arch='arm64'
 try:install_build(input_build(),h);raise AssertionError('architecture substituted')
 except Refused as e:assert e.code=='php.build_platform_unsupported'
 assert not any(c[0]=='apt-get' for c in h.calls)
print('ok')
`,
  );
});

Deno.test("PHP build extras, ownership backups, restoration, FPM defer and settings-only reload", async () => {
  await pythonFixture(
    phpBuildProgram,
    buildFixture + String.raw`
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));source=h.write('/etc/apt/sources.list.d/foreign.sources','URIs: https://packages.sury.org/php\n')
 try:install_build(input_build(),h);raise AssertionError('Sury source accepted')
 except Refused as e:assert e.code=='php.build_conflicting_source'
 assert source.exists() and not any(c[0]=='apt-get' for c in h.calls)
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));i=input_build();i['extras']='purge';h.packages={'php8.5-extra':'1.0'};h.cache('php8.5-extra','1.0')
 source=h.write('/etc/apt/sources.list.d/foreign.list','deb https://ppa.launchpadcontent.net/ondrej/php ubuntu main\n')
 ini=h.write('/etc/php/8.5/mods-available/custom.ini','extension=custom.so\n');so=h.write('/usr/lib/php/2026/custom.so','custom binary')
 link=h.path('/etc/php/8.5/cli/conf.d/20-custom.ini');link.symlink_to('../../mods-available/custom.ini')
 # A module mismatch restores exact files, links, versions, and removes newly installed pins.
 h.fail_modules=True
 try:install_build(i,h);raise AssertionError('unexpected module accepted')
 except Refused as e:assert e.code=='php.build_modules_mismatch' and e.details['restored']
 assert ini.read_text()=='extension=custom.so\n' and so.read_text()=='custom binary' and link.is_symlink()
 assert h.packages=={'php8.5-extra':'1.0'} and not source.exists()
 assert not h.path('/etc/php/8.5/fpm/conf.d/99-cve-2026-109618-upload-progress.ini').exists()
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));i=input_build();i['extras']='purge'
 ini=h.write('/etc/php/8.5/mods-available/custom.ini','extension=custom.so\n');so=h.write('/usr/lib/php/2026/custom.so','custom binary')
 out=install_build(i,h)
 assert out['data']['disabled']==['php8.5:custom'] and not ini.exists() and not so.exists()
 assert len(list(h.path(BACKUP).rglob('custom.so')))==1
for mode in ('shared','none','all'):
 with tempfile.TemporaryDirectory() as tmp:
  h=Fake(P(tmp));i=input_build();i['fpm']=mode;h.bad_fpm=True
  if mode=='all':
   try:install_build(i,h);raise AssertionError('invalid FPM accepted')
   except Refused as e:assert e.code=='php.build_fpm_invalid' and e.details['restored']
   assert not h.packages
  else:
   out=install_build(i,h)
   assert out['data']['deferred']==(['php8.5-fpm.service'] if mode=='shared' else [])
   assert out['data']['stale_masters']==(['orbit-example-php8.5-fpm.service'] if mode=='shared' else [])
   assert not out['data']['restarted']
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));i=input_build();i['fpm']='none';h.packages={'php8.5-cli':'2.0','php8.5-fpm':'2.0'}
 # Only the upload-progress setting changes: reload ALL active masters, defer invalid ones.
 h.bad_fpm=True;out=install_build(i,h)
 assert out['data']['deferred']==['php8.5-fpm.service','orbit-example-php8.5-fpm.service']
 assert out['data']['changed']==[] and out['outcome']=='applied' and not any(c[0]=='curl' for c in h.calls)
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));i=input_build();h.bad_ping=True
 try:install_build(i,h);raise AssertionError('dead FastCGI accepted')
 except Refused as e:assert e.code=='php.build_rolled_back' and e.details['restored']
 assert not h.packages
print('ok')
`,
  );
});

const input: InstallPhpBuildInput = {
  versions: {
    "8.5": {
      packages: [{
        name: "php8.5-cli",
        version: "2.0",
        architecture: "amd64",
        url: "https://origin.example/php.deb",
        sha256: "a".repeat(64),
      }],
      extensions: ["curl"],
    },
  },
  pcov: false,
  opentelemetry: false,
  extras: "keep",
  fpm: "none",
  repack: { package: "dpkg-repack", version: "1.0" },
};
Deno.test("PHP build wrapper rejects invalid pins and confines output and input", async () => {
  let calls = 0;
  const runner = (command: { args: string[]; stdin: string }) => {
    calls++;
    assertEquals(
      command.args.includes(input.versions["8.5"].packages[0].url),
      false,
    );
    assertEquals(JSON.parse(command.stdin).input.versions, input.versions);
    assertEquals(
      command.args.some((arg) => arg.includes("orbit-agent")),
      false,
    );
    return Promise.resolve({
      code: 0,
      stdout: JSON.stringify({
        outcome: "current",
        data: {
          changed: [],
          repaired: [],
          purged: [],
          disabled: [],
          restarted: [],
          reloaded: [],
          deferred: [],
          stale_masters: [],
          fpm_disabled: false,
        },
      }),
    });
  };
  assertEquals(
    (await installPhpBuild(input, undefined, runner)).outcome,
    "current",
  );
  assertEquals(calls, 1);
  const bad = structuredClone(input);
  bad.versions["8.5"].packages[0].url =
    "https://user:password@origin.example/php.deb";
  assertThrows(() => installPhpBuild(bad, undefined, runner));
  bad.versions["8.5"].packages[0].url = "http://origin.example/php.deb";
  assertThrows(() => installPhpBuild(bad, undefined, runner));
  assertEquals(calls, 1);
  for (
    const stdout of [
      "private stderr",
      '{"outcome":"current","data":{"secret":"private"}}',
      "a".repeat(2097153),
    ]
  ) {
    await assertRejects(
      () =>
        installPhpBuild(
          input,
          undefined,
          () => Promise.resolve({ code: 0, stdout }),
        ),
      PhpLocalError,
      "php.build_execution_failed",
    );
  }
  const abort = new AbortController();
  abort.abort();
  await assertRejects(
    () => installPhpBuild(input, abort.signal, runner),
    PhpLocalError,
  );
  assertEquals(calls, 1);
  await assertRejects(
    () =>
      installPhpBuild(input, undefined, () =>
        Promise.resolve({
          code: 0,
          stdout: '{"error_code":"php.build_rollback_failed","restored":false}',
        })),
    PhpLocalError,
    "php.build_rollback_failed",
  );
});

Deno.test("PHP build verifies archive contents, repairs damage, preserves package policy and rolls back cancellation", async () => {
  await pythonFixture(
    phpBuildProgram,
    buildFixture + String.raw`
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));h.packages={'php8.5-cli':'1.0','php8.5-fpm':'1.0'}
 archive=h.write('/var/cache/apt/archives/php8.5-cli_old.deb',artifact('php8.5-cli','1.0'))
 md5=hashlib.md5(b'installed').hexdigest();h.write('/var/lib/dpkg/info/php8.5-cli.md5sums',md5+'  usr/bin/php8.5\n')
 with tempfile.TemporaryDirectory(dir=h.tmp) as work:
  prior=previous_packages(h,['php8.5-cli'],h.packages,dict(package='dpkg-repack',version='1.0'),P(work))
 assert prior['php8.5-cli'].parent==h.path(CACHE) and not h.repack
 # An archive with the same version but different installed contents is not a rollback copy.
 h.write('/var/lib/dpkg/info/php8.5-cli.md5sums','0'*32+'  usr/bin/php8.5\n')
 for p in h.path(CACHE).glob('php8.5-cli_*.deb'):remove(p)
 with tempfile.TemporaryDirectory(dir=h.tmp) as work:
  previous_packages(h,['php8.5-cli'],h.packages,dict(package='dpkg-repack',version='1.0'),P(work))
 assert len(h.repack)==1
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));h.packages={'php8.5-cli':'2.0','php8.5-fpm':'2.0'};h.damaged={'php8.5-cli'}
 for n in h.packages:h.cache(n,'2.0')
 policy_file=h.write('/usr/sbin/policy-rc.d','operator policy\n')
 out=install_build(input_build(),h)
 assert out['data']['changed']==[] and out['data']['repaired']==['php8.5-cli']
 assert '--reinstall' in next(c for c in h.calls if c[0]=='apt-get') and policy_file.read_text()=='operator policy\n'
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));i=input_build();i['fpm']='disabled';out=install_build(i,h)
 assert out['data']['fpm_disabled'] and not h.active and h.enabled=='disabled'
 assert not out['data']['restarted'] and not out['data']['reloaded']
with tempfile.TemporaryDirectory() as tmp:
 class Cancelled(Fake):
  def run(self,args,required=True,env=None,timeout=300):
   result=super().run(args,required,env,timeout)
   if str(args[0])=='apt-get' and '--' in args and not getattr(self,'cancelled_once',False):
    self.cancelled_once=True;raise KeyboardInterrupt()
   return result
 h=Cancelled(P(tmp));h.packages={'php8.5-cli':'1.0','php8.5-fpm':'1.0'}
 for n in h.packages:h.cache(n,'1.0')
 try:install_build(input_build(),h);raise AssertionError('cancellation accepted')
 except Refused as e:assert e.code=='php.build_execution_failed' and e.details['restored']
 assert h.packages=={'php8.5-cli':'1.0','php8.5-fpm':'1.0'}
 assert not h.path('/usr/sbin/policy-rc.d').exists()
print('ok')
`,
  );
});

Deno.test("PHP build FastCGI completion validates request identity, size and end records", async () => {
  await pythonFixture(
    phpBuildProgram,
    String.raw`
import threading
for bad in ('none','identity','length','bound'):
 with tempfile.TemporaryDirectory() as tmp:
  path=P(tmp)/'fpm.sock';server=socket.socket(socket.AF_UNIX);server.bind(str(path));server.listen(1)
  def respond():
   try:
    with server.accept()[0] as connection:
     request=connection.recv(4096)
     assert b'/orbit-health-check.php' in request and b'ORBIT_OPCACHE_ACTION' not in request
     if bad=='bound':
      body=b'x'*65535
      for _ in range(17):connection.sendall(struct.pack('!BBHHBB',1,6,1,len(body),0,0)+body)
     else:
      length=7 if bad=='length' else 8;request_id=2 if bad=='identity' else 1
      connection.sendall(struct.pack('!BBHHBB',1,3,request_id,length,0,0)+bytes(length))
   except (BrokenPipeError,ConnectionResetError):pass
   finally:server.close()
  thread=threading.Thread(target=respond);thread.start()
  try:Host().ping(path);assert bad=='none'
  except Refused:assert bad!='none'
  thread.join()
print('ok')
`,
  );
});

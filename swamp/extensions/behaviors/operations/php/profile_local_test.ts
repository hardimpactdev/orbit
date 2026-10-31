import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { configurePhpRuntime, PhpLocalError } from "./profile_local.ts";
import { phpProfileProgram } from "./profile_program.ts";
import { buildFixture } from "./build_test_fixture.ts";
import { pythonFixture } from "./test_fixture.ts";

const ini = `opcache.enable = On
opcache.memory_consumption = 512
opcache.interned_strings_buffer = 64
opcache.max_accelerated_files = 65407
opcache.jit = disable
opcache.jit_buffer_size = 0
post_max_size = 64M
upload_max_filesize = 64M
`;
Deno.test("PHP profile validates eight directives, absent binaries, valid reloads and deferred masters", async () => {
  await pythonFixture(
    phpProfileProgram,
    buildFixture + "\nINI=" + JSON.stringify(ini) + String.raw`
def request():return dict(version='8.5',runtime_ini=INI,release=dict(id='ubuntu',codenames=['resolute']))
for post,upload,valid in [('1G','1024M',True),('64M','65536K',True),('64M','65537K',False),('2G','64M',False),('0','0',False),('1K','1025',False)]:
 data=request();data['runtime_ini']=INI.replace('post_max_size = 64M','post_max_size = '+post).replace('upload_max_filesize = 64M','upload_max_filesize = '+upload)
 try:settings(data);assert valid
 except Refused as e:assert not valid and e.code=='php.runtime_input_invalid'
for text in (INI+'post_max_size = 64M\n',INI.replace('opcache.enable = On','opcache.enable = Off'),INI.replace('opcache.memory_consumption = 512','opcache.memory_consumption = 4294967296'),INI+'extension = /tmp/private.so\n'):
 try:settings(dict(runtime_ini=text));raise AssertionError('invalid INI accepted')
 except Refused as e:assert e.code=='php.runtime_input_invalid'
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));binary=h.path('/usr/sbin/php-fpm8.5');remove(binary);before=list(h.root.rglob('*'))
 assert configure_runtime(request(),h)==dict(changed=False,reloaded=False,deferred=[])
 assert list(h.root.rglob('*'))==before and not h.calls
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));out=configure_runtime(request(),h)
 assert out==dict(changed=True,reloaded=True,deferred=[])
 enabled=h.path('/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini')
 assert enabled.is_symlink() and os.readlink(enabled)=='../../mods-available/orbit-runtime.ini'
 assert h.path('/etc/php/8.5/mods-available/orbit-runtime.ini').read_text()==INI
 assert h.path('/etc/php/8.5/fpm/php-fpm.conf').read_text().count('process_control_timeout = 30s')==1
 assert h.custom.read_text().count('process_control_timeout = 30s')==1
 assert [c[-1] for c in h.calls if c[:2]==['systemctl','reload']]==['php8.5-fpm.service','orbit-example-php8.5-fpm.service']
 h.calls.clear();assert configure_runtime(request(),h)==dict(changed=False,reloaded=False,deferred=[])
 assert not any(c[:2]==['systemctl','reload'] for c in h.calls)
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));h.bad_fpm=True
 out=configure_runtime(request(),h)
 assert out==dict(changed=True,reloaded=False,deferred=['php8.5-fpm.service','orbit-example-php8.5-fpm.service'])
 assert h.path('/etc/php/8.5/mods-available/orbit-runtime.ini').read_text()==INI
 assert not any(c[:2]==['systemctl','reload'] for c in h.calls)
print('ok')
`,
  );
});
Deno.test("PHP profile restores exact INI, links, master configuration and reloads on failure", async () => {
  await pythonFixture(
    phpProfileProgram,
    buildFixture + "\nINI=" + JSON.stringify(ini) + String.raw`
for override,fail_reload in ((True,False),(False,True)):
 with tempfile.TemporaryDirectory() as tmp:
  class Failing(Fake):
   def run(self,args,required=True,env=None,timeout=300):
    args=list(map(str,args))
    if fail_reload and args==['systemctl','reload','orbit-example-php8.5-fpm.service'] and not getattr(self,'failed_once',False):
     self.failed_once=True;self.calls.append(args);raise Refused('php.build_execution_failed')
    return super().run(args,required,env,timeout)
  h=Failing(P(tmp));h.info_override=override
  available=h.write('/etc/php/8.5/mods-available/orbit-runtime.ini','; original\n')
  old=h.write('/etc/php/8.5/mods-available/original.ini','; original\n')
  enabled=h.path('/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini');enabled.symlink_to('../../mods-available/original.ini')
  shared=h.path('/etc/php/8.5/fpm/php-fpm.conf');shared.write_bytes(shared.read_bytes().replace(b'\n',b'\r\n'));h.custom.write_bytes(h.custom.read_bytes().replace(b'\n',b'\r\n'));before_shared=shared.read_bytes();before_master=h.custom.read_bytes()
  try:configure_runtime(dict(version='8.5',runtime_ini=INI,release=dict(id='ubuntu',codenames=['resolute'])),h);raise AssertionError('failed runtime accepted')
  except Refused as e:assert e.code=='php.runtime_configuration_failed' and e.details['restored']
  assert available.read_text()=='; original\n' and os.readlink(enabled)=='../../mods-available/original.ini'
  assert shared.read_bytes()==before_shared and h.custom.read_bytes()==before_master
  if fail_reload:assert len([c for c in h.calls if c[:2]==['systemctl','reload']])==4
print('ok')
`,
  );
});
Deno.test("PHP profile stdin and bounded error handling", async () => {
  const input = {
    version: "8.5",
    runtime_ini: ini,
    release: { id: "ubuntu", codenames: ["resolute"] },
  };
  const result = await configurePhpRuntime(input, undefined, (command) => {
    assertEquals(command.args.includes(ini), false);
    assertEquals(JSON.parse(command.stdin).input, input);
    return Promise.resolve({
      code: 0,
      stdout:
        '{"changed":true,"reloaded":false,"deferred":["php8.5-fpm.service"]}',
    });
  });
  assertEquals(result.deferred, ["php8.5-fpm.service"]);
  await assertRejects(
    () =>
      configurePhpRuntime(input, undefined, () =>
        Promise.resolve({
          code: 0,
          stdout:
            '{"error_code":"php.runtime_configuration_failed","restored":true}',
        })),
    PhpLocalError,
    "php.runtime_configuration_failed",
  );
  await assertRejects(
    () =>
      configurePhpRuntime(
        input,
        undefined,
        () => Promise.resolve({ code: 1, stdout: ini }),
      ),
    PhpLocalError,
    "php.runtime_configuration_failed",
  );
});

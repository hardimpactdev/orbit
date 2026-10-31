import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { configCacheProgram } from "./runtime_cache_program.ts";
import { refreshConfigCache } from "./runtime_cache.ts";

Deno.test("native config refresh preserves the old cache on build, boot and missing-output failures", async () => {
  const fixture = String.raw`
import types
with tempfile.TemporaryDirectory() as tmp:
 root=P(tmp);root.chmod(0o755)
 real=pwd.getpwuid(os.getuid()) if os.getuid()!=0 else pwd.getpwnam('nobody')
 home=root/'home';home.mkdir()
 php=root/'bin';php.mkdir();php.chmod(0o755)
 executable=php/'php8.5'
 executable.write_text('''#!/bin/sh
if [ "$*" = "artisan --version --no-interaction" ]; then grep -q 'return \\[' "$APP_CONFIG_CACHE"; exit; fi
[ "$*" = "artisan config:cache" ] || exit 97
fail=$(cat fail)
[ "$fail" = missing ] && exit 0
printf '<?php return [123];' > "$APP_CONFIG_CACHE"
echo 'synthetic-secret' >&2
[ "$fail" = build ] && exit 96
[ "$fail" = boot ] && printf '<?php return false;' > "$APP_CONFIG_CACHE"
exit 0
''');executable.chmod(0o755)
 class FixtureHost(Host):
  def account(self,user):return types.SimpleNamespace(pw_uid=real.pw_uid,pw_gid=real.pw_gid,pw_name=real.pw_name,pw_dir=str(home))
 host=FixtureHost();host.php=php
 for suffix in ('','apps/site'):
  app=home/'releases'/'r1'/suffix;cache=app/'bootstrap'/'cache'/'config.php';cache.parent.mkdir(parents=True,exist_ok=True)
  for path in (home,*home.rglob('*')):
   if path.is_dir():os.chown(path,real.pw_uid,real.pw_gid)
  data=dict(user=real.pw_name,home=str(home),version='8.5',application=str(home/'current'/suffix))
  assert refresh(data,host)==dict(outcome='no_current_release')
  (home/'current').symlink_to('releases/r1')
  assert refresh(data,host)==dict(outcome='config_uncached')
  for fail in ('none','build','boot','missing'):
   cache.write_text('old config');cache.chmod(0o640);os.chown(cache,real.pw_uid,real.pw_gid)
   (app/'fail').write_text(fail)
   try:
    result=refresh(data,host)
    assert fail=='none' and result==dict(outcome='config_cached')
   except Refused:assert fail!='none'
   assert cache.read_text()==('<?php return [123];' if fail=='none' else 'old config')
   assert stat.S_IMODE(cache.stat().st_mode)==0o640 and cache.stat().st_uid==real.pw_uid
   assert not list(cache.parent.glob('.orbit-config-*'))
  # A cache link is never followed or replaced.
  cache.unlink();outside=root/'outside';outside.write_text('leave');cache.symlink_to(outside)
  try:refresh(data,host);raise AssertionError('foreign cache accepted')
  except Refused:pass
  assert outside.read_text()=='leave'
  cache.unlink();(home/'current').unlink()
 # A foreign release link is refused before application execution.
 (home/'current').symlink_to(root)
 try:refresh(data,host);raise AssertionError('foreign current accepted')
 except Refused:pass
print('ok')
`;
  const child = new Deno.Command("/usr/bin/python3", {
    args: [
      "-I",
      "-c",
      "import json,sys\np=json.load(sys.stdin);n={'__name__':'fixture'};exec(p['program'],n);exec(p['fixture'],n)",
    ],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(
    new TextEncoder().encode(
      JSON.stringify({ program: configCacheProgram, fixture }),
    ),
  );
  await writer.close();
  const result = await child.output();
  assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
  assertEquals(new TextDecoder().decode(result.stdout), "ok\n");
});

Deno.test("config-cache wrapper sends only private stdin and sanitizes unconfirmed replies", async () => {
  const input = {
    user: "orbit-app-31",
    home: "/home/orbit-app-31",
    version: "8.5",
    application: "/home/orbit-app-31/current/apps/site",
  };
  const result = await refreshConfigCache(input, undefined, (command) => {
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(JSON.parse(command.stdin!), input);
    assertEquals(
      command.args.some((value) => value.includes(input.application)),
      false,
    );
    return Promise.resolve({
      success: true,
      stdout: '{"outcome":"config_cached"}',
    });
  });
  assertEquals(result, { outcome: "config_cached" });
  for (
    const reply of [
      { success: false, stdout: "synthetic-secret" },
      { success: true, stdout: '{"outcome":"synthetic-secret"}' },
      { success: true, stdout: '{"error_code":"synthetic-secret"}' },
    ]
  ) {
    await assertRejects(
      () => refreshConfigCache(input, undefined, () => Promise.resolve(reply)),
      Error,
      "env.config_cache_refresh_failed",
    );
  }
});

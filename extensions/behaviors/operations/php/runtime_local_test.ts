import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  convergeProductionRuntime,
  inspectProductionPool,
  PhpRuntimeError,
  refreshProductionCache,
  removeProductionRuntime,
} from "./runtime_local.ts";
import { productionRuntimeProgram } from "./runtime_program.ts";

Deno.test("native PHP inspection, monitoring recovery and failed activation preserve owned runtime files", async () => {
  const fixture = String.raw`
import tempfile,types
with tempfile.TemporaryDirectory() as tmp:
 base=P(tmp); base.chmod(0o700)
 class FakeHost(Host):
  def __init__(self):
   self.homes=base/'home';self.etc=base/'etc'/'orbit';self.units=base/'units';self.tmp=base/'tmp';self.locks=base/'locks'
   self.sockets=base/'sockets';self.root_uid=os.getuid();self.root_gid=self.caddy_gid=os.getgid();self.calls=[];self.fail=False
   for path in (self.homes,self.etc.parent,self.units,self.tmp,self.locks): path.mkdir()
  def active(self,service): return True
  def ok(self,*args): self.calls.append(args);return not (self.fail and args[0]=='restart')
 h=FakeHost();user=pwd.getpwuid(os.getuid()).pw_name;home=h.homes/user;home.mkdir()
 initial=home/'releases'/'initial';initial.mkdir(parents=True)
 data=dict(user=user,home=str(home),version='8.5',marker='fixture-owned',mode='monitor',application=str(home/'current'),initial_application=str(initial),configuration=dict(main='main\n',pool='[orbit-'+user+']\nchdir = '+str(home/'current'),initial_pool='[orbit-'+user+']\nchdir = '+str(initial),local_defaults='; local\n',master_ini='opcache.enable=1\n',unit='unit\n'))
 # Monitoring absence never creates or adopts a runtime.
 assert converge(data,h)==dict(published=False) and not h.etc.exists() and not h.calls
 r=Runtime(h,data);r.generated.mkdir(parents=True)
 for path,text in ((r.marker,'fixture-owned'),(r.generated/'php-fpm.conf','main\n'),(r.generated/'pool.conf','old pool\n'),(r.generated/'local.sha256',digest(b'; local\n')+'\n'),(r.local,'; local\n'),(r.unit,'old unit\n')):
  path.write_text(text);path.chmod(0o644)
 result=inspect(data,h)
 assert result==dict(pool='old pool\n',fingerprint=digest(b'main\nold pool\n; local\n'))
 r.marker.write_text('foreign')
 try: inspect(data,h);raise AssertionError('foreign marker accepted')
 except Refused: pass
 r.marker.write_text('fixture-owned')
 r.generated.joinpath('pool.conf').chmod(0o666)
 try: inspect(data,h);raise AssertionError('writable pool accepted')
 except Refused: pass
 r.generated.joinpath('pool.conf').chmod(0o644)
 # A failed monitoring reload keeps its journal; recovery consumes it once.
 h.fail=False
 reload_before=reload
 def failed_reload(*args): raise Refused('fixture reload')
 reload=failed_reload
 try: monitoring(h,r,b'new pool\n',True,True);raise AssertionError('reload failure ignored')
 except Refused: pass
 assert r.receipt(METRICS_PENDING).exists() and r.receipt(METRICS_BACKUP).read_bytes()==b'old pool\n'
 reload=reload_before
 monitoring(h,r,b'new pool\n',True,True)
 assert r.generated.joinpath('pool.conf').read_bytes()==b'new pool\n' and not r.receipt(METRICS_PENDING).exists()
 # Runtime activation failure restores exact prior generated content and unit.
 prior={p.name:p.read_bytes() for p in r.generated.iterdir()};before_unit=r.unit.read_bytes()
 validate=lambda *args: None
 data['mode']='converge';h.fail=True
 try: converge(data,h);raise AssertionError('restart failure ignored')
 except Refused: pass
 assert {p.name:p.read_bytes() for p in r.generated.iterdir()}==prior and r.unit.read_bytes()==before_unit
 # Selection and tuning boundaries are local, before systemd activation.
 assert b'initial' in select_pool(data)
 (home/'current').mkdir()
 try: select_pool(data);raise AssertionError('directory current accepted')
 except Refused: pass
 assert not tuning_allowed('listen = /tmp/foreign\n','orbit-'+user,False)
 assert not tuning_allowed('pm.status_path = /foreign\n','orbit-'+user,True)
 assert tuning_allowed('pm.max_children = 8\n','orbit-'+user,False)
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
      JSON.stringify({ program: productionRuntimeProgram, fixture }),
    ),
  );
  await writer.close();
  const result = await child.output();
  assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
  assertEquals(new TextDecoder().decode(result.stdout), "ok\n");
});

Deno.test("PHP local wrapper keeps private configuration on stdin and exposes only typed results", async () => {
  const identity = {
    user: "orbit-app-9",
    version: "8.5",
    marker: "instance:9",
  };
  let request = "";
  const result = await inspectProductionPool(identity, undefined, (command) => {
    assertEquals(command.args.join(" ").includes(identity.marker), false);
    request = command.stdin;
    return Promise.resolve({
      code: 0,
      stdout: JSON.stringify({ pool: "pool", fingerprint: "a".repeat(64) }),
    });
  });
  assertEquals(result.pool, "pool");
  assertEquals(JSON.parse(request), { action: "inspect", input: identity });
  await assertRejects(
    () =>
      inspectProductionPool(
        identity,
        undefined,
        () => Promise.resolve({ code: 1, stdout: "private config" }),
      ),
    PhpRuntimeError,
    "php.runtime_execution_failed",
  );
  await assertRejects(
    () =>
      convergeProductionRuntime(
        {
          ...identity,
          mode: "monitor",
          home: "/home/orbit-app-9",
          application: "/home/orbit-app-9/current",
          initial_application: "/home/orbit-app-9/releases/initial",
          configuration: {
            main: "private",
            pool: "pool",
            initial_pool: "pool",
            local_defaults: "",
            master_ini: "",
            unit: "unit",
          },
        },
        undefined,
        () =>
          Promise.resolve({
            code: 0,
            stdout: '{"error_code":"php.runtime_invalid"}',
          }),
      ),
    PhpRuntimeError,
    "php.runtime_invalid",
  );
});

Deno.test("all production PHP lowers use canonical placed argv and private stdin", async () => {
  const { nodePhpRunner } = await import("./local.ts");
  const { commandLaunch } = await import("../_lib/node-commands.ts");
  const { proxyAddress } = await import("../_lib/node-proxy-config.ts");
  const identity = {
    user: "orbit-app-9",
    version: "8.5",
    marker: "instance:9",
  };
  for (const node of [1, 18] as const) {
    const actions: string[] = [];
    const command: import("../_lib/node-commands.ts").NodeCommand = (
      argv,
      stdin,
      user,
      options,
    ) => {
      assertEquals(argv, [
        "/usr/bin/python3",
        "-I",
        "-c",
        productionRuntimeProgram,
      ]);
      assertEquals(user, "root");
      assertEquals(options?.deadlineMs, 1020000);
      assertEquals(options?.maxOutputBytes, 2097152);
      const input = JSON.parse(new TextDecoder().decode(stdin));
      actions.push(input.action);
      assertEquals(input.input.marker, identity.marker);
      const launch = commandLaunch(argv, user, {
        node_id: node,
        host: proxyAddress(node),
        user: "orbit",
      }, options?.deadlineMs);
      assertEquals(launch.args.includes("orbit@" + proxyAddress(node)), true);
      assertEquals(
        JSON.stringify(launch).includes("private-configuration"),
        false,
      );
      assertEquals(JSON.stringify(launch).includes(identity.marker), false);
      const result = input.action === "inspect"
        ? { pool: "pool", fingerprint: "a".repeat(64) }
        : input.action === "converge"
        ? { published: true }
        : input.action === "remove"
        ? { removed: true }
        : { complete: true };
      return Promise.resolve({
        success: true,
        code: 0,
        stdout: new TextEncoder().encode(JSON.stringify(result)),
      });
    };
    const runner = nodePhpRunner(command);
    assertEquals(await inspectProductionPool(identity, undefined, runner), {
      pool: "pool",
      fingerprint: "a".repeat(64),
    });
    assertEquals(
      await convergeProductionRuntime(
        {
          ...identity,
          mode: "monitor",
          home: "/home/orbit-app-9",
          application: "/home/orbit-app-9/current",
          initial_application: "/home/orbit-app-9/releases/initial",
          configuration: {
            main: "private-configuration",
            pool: "pool",
            initial_pool: "pool",
            local_defaults: "",
            master_ini: "",
            unit: "unit",
          },
        },
        undefined,
        runner,
      ),
      { published: true },
    );
    assertEquals(await removeProductionRuntime(identity, undefined, runner), {
      removed: true,
    });
    assertEquals(
      await refreshProductionCache(
        { ...identity, home: "/home/orbit-app-9", deadline_seconds: 30 },
        undefined,
        runner,
      ),
      { complete: true },
    );
    assertEquals(actions, ["inspect", "converge", "remove", "refresh"]);
  }
});

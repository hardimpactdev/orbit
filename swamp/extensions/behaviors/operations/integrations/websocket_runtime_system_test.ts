import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  type WebSocketRuntimeInput,
  websocketRuntimeSystem,
} from "./websocket_runtime_system.ts";
import type { SystemCommand, SystemTransport } from "../node/system_command.ts";

const input: WebSocketRuntimeInput = {
  user: "orbit",
  group: "orbit",
  install_path: "/opt/orbit/websocket",
  repository: "https://github.com/hardimpactdev/orbit-reverb.git",
  ref: "a".repeat(40),
  port: 8790,
  environment: "REVERB_PRIVATE=private-websocket-sentinel\n",
  unit:
    "# Managed by Orbit.\n[Service]\nUser=orbit\nGroup=orbit\nWorkingDirectory=/opt/orbit/websocket\nExecStart=/usr/bin/php /opt/orbit/websocket/artisan orbit:reverb:start --host=127.0.0.1 --port=8790\n",
};
Deno.test("WebSocket runtime sends existing private environment only on stdin to fixed code", async () => {
  const calls: SystemCommand[] = [];
  const runtime = websocketRuntimeSystem((command) => {
    calls.push(command);
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify({
        release: "/opt/orbit/websocket.releases/release." + "a".repeat(32),
      }),
    });
  });
  const result = await runtime.converge(input);
  assertEquals(result.release.includes("websocket.releases/release."), true);
  assertEquals(calls.length, 1);
  assertEquals(calls[0].command, "/usr/bin/sudo");
  assertEquals(calls[0].args.slice(0, 4), [
    "-n",
    "--",
    "/usr/bin/python3",
    "-c",
  ]);
  assertEquals(
    calls[0].args.some((arg) => arg.includes("private-websocket-sentinel")),
    false,
  );
  assertEquals(JSON.parse(calls[0].stdin!).environment, input.environment);
  assertEquals(
    JSON.stringify(result).includes("private-websocket-sentinel"),
    false,
  );
});
Deno.test("WebSocket invalid admission data refuses before invoking any program", async () => {
  let calls = 0;
  const runtime = websocketRuntimeSystem(() => {
    calls++;
    throw new Error("must not execute");
  });
  for (
    const change of [
      { user: "bad;user" },
      { group: "bad group" },
      { install_path: "/opt/../etc" },
      {
        repository:
          "https://private-token@github.com/hardimpactdev/orbit-reverb.git",
      },
      { ref: "main" },
      { port: 0 },
      { unit: input.unit.replace("User=orbit", "User=root") },
    ]
  ) {
    await assertRejects(
      () => runtime.converge({ ...input, ...change }),
      Error,
      "websocket.runtime_invalid",
    );
  }
  assertEquals(calls, 0);
});
Deno.test("WebSocket removal carries no credential and never runs converge", async () => {
  const calls: SystemCommand[] = [];
  const runtime = websocketRuntimeSystem((command) => {
    calls.push(command);
    return Promise.resolve({ success: true, stdout: "{}" });
  });
  assertEquals(
    await runtime.remove({ install_path: input.install_path, purge: true }),
    {},
  );
  assertEquals(JSON.parse(calls[0].stdin!), {
    operation: "remove",
    install_path: input.install_path,
    purge: true,
  });
});
Deno.test("WebSocket unknown system outcomes discard private diagnostics", async () => {
  for (
    const transport of [
      () => Promise.reject(new Error(input.environment)),
      () => Promise.resolve({ success: false, stdout: input.environment }),
      () => Promise.resolve({ success: true, stdout: input.environment }),
      () => Promise.resolve({ success: true, stdout: '{"unknown":true}' }),
    ] satisfies SystemTransport[]
  ) {
    await assertRejects(
      () => websocketRuntimeSystem(transport).converge(input),
      Error,
      "websocket.outcome_unknown",
    );
  }
});
Deno.test("WebSocket preflight refuses a foreign install before lock or service changes", async () => {
  const root = await Deno.makeTempDir({ prefix: "orbit-ws-system-" });
  const install = root + "/existing";
  await Deno.writeTextFile(install, "foreign bytes");
  const transport: SystemTransport = async (command) => {
    const source = command.args[4].replace(
      "UNIT=pathlib.Path('/etc/systemd/system/orbit-websocket.service')",
      "UNIT=pathlib.Path(" + JSON.stringify(root + "/unit") + ")",
    );
    const child = new Deno.Command("/usr/bin/python3", {
      args: ["-c", source],
      stdin: "piped",
      stdout: "piped",
      stderr: "null",
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(command.stdin));
    await writer.close();
    const result = await child.output();
    return {
      success: result.success,
      stdout: new TextDecoder().decode(result.stdout),
    };
  };
  try {
    await assertRejects(
      () =>
        websocketRuntimeSystem(transport).remove({
          install_path: install,
          purge: true,
        }),
      Error,
      "websocket.runtime_ownership_drift",
    );
    assertEquals(await Deno.readTextFile(install), "foreign bytes");
    const files = [];
    for await (const entry of Deno.readDir(root)) files.push(entry.name);
    assertEquals(files, ["existing"]);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});

async function runtimeFilesystem(body: string) {
  let program = "";
  await websocketRuntimeSystem((command) => {
    program = command.args[4];
    return Promise.resolve({ success: true, stdout: "{}" });
  }).remove({ install_path: input.install_path, purge: false });
  const main = program.indexOf(
    "try:print(json.dumps(execute(json.load(sys.stdin))))",
  );
  if (main < 0) throw new Error("Fixed program entry is missing");
  const fixture = String.raw`
root=pathlib.Path(tempfile.mkdtemp(prefix='orbit-ws-lifecycle-'))
UNIT=root/'orbit-websocket.service'
install=root/'websocket';releases=root/'websocket.releases';releases.mkdir()
old=releases/('release.'+'a'*32);old.mkdir();(old/'.env').write_text('OLD_PRIVATE=retain\n')
install.symlink_to(old)
account=pwd.getpwuid(os.getuid());group=grp.getgrgid(account.pw_gid)
v=json.loads(INPUT)
v.update(user=account.pw_name,group=group.gr_name,install_path=str(install),operation='converge')
v['unit']=v['unit'].replace('User=orbit','User='+account.pw_name).replace('Group=orbit','Group='+group.gr_name).replace('/opt/orbit/websocket',str(install))
UNIT.write_text(v['unit']);oldunit=UNIT.read_bytes()
real_fchown=os.fchown
os.fchown=lambda fd,uid,gid:real_fchown(fd,os.getuid() if uid==0 else uid,os.getgid() if gid==0 else gid)
commands=[];active=True;enabled=True
fail_new=False

def run(args,step,check=True):
 global active,enabled
 commands.append((args,str(install.resolve()) if install.exists() else None))
 if '/usr/bin/git' in args and 'clone' in args:
  candidate=pathlib.Path(args[-1]);(candidate/'artisan').write_text('artisan');(candidate/'vendor').mkdir()
 if args[0]=='/usr/bin/systemctl':
  action=args[1]
  if action=='is-active':return active
  if action=='is-enabled':return enabled
  if action=='stop' or action=='disable' and '--now' in args:active=False
  if action=='restart':active=True
  if action=='enable':enabled=True
  if action=='disable':enabled=False
 return True

def listener(port):return not fail_new or install.resolve()==old
`;
  const source = program.slice(0, main).replace(
    "if os.fstat(lock.fileno()).st_uid!=0:",
    "if os.fstat(lock.fileno()).st_uid!=os.getuid():",
  ) + "\nINPUT=" + JSON.stringify(JSON.stringify(input)) + "\n" + fixture +
    "\ntry:\n" + body.split("\n").map((line) => " " + line).join("\n") +
    "\nfinally:shutil.rmtree(root)\n";
  const child = new Deno.Command("/usr/bin/python3", {
    args: ["-"],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writing = (async () => {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(source));
    await writer.close();
  })();
  const [result] = await Promise.all([child.output(), writing]);
  if (!result.success) throw new Error(new TextDecoder().decode(result.stderr));
}

Deno.test("WebSocket real filesystem activation keeps private modes and preserve-data removal leaves releases", async () => {
  await runtimeFilesystem(String.raw`
result=execute(v)
current=pathlib.Path(result['release'])
assert install.resolve()==current and current!=old
assert (current/'.env').read_text()==v['environment']
assert (current/'.env').stat().st_mode&0o777==0o600
assert UNIT.stat().st_mode&0o777==0o644
assert (old/'.env').read_text()=='OLD_PRIVATE=retain\n'
stop=next(path for argv,path in commands if argv[:2]==['/usr/bin/systemctl','stop'])
restart=next(path for argv,path in commands if argv[:2]==['/usr/bin/systemctl','restart'])
assert stop==str(old) and restart==str(current)
assert execute({'operation':'remove','install_path':str(install),'purge':False})=={}
assert not UNIT.exists() and install.resolve()==current and (current/'.env').exists() and old.exists()
`);
});
Deno.test("WebSocket real filesystem failed activation restores old unit/link/service and removes failed secret release", async () => {
  await runtimeFilesystem(String.raw`
fail_new=True
try:execute(v);raise AssertionError('accepted unavailable runtime')
except Refused as error:assert str(error)=='websocket.runtime_not_listening'
assert install.resolve()==old and UNIT.read_bytes()==oldunit and active and enabled
assert list(releases.iterdir())==[old]
assert (old/'.env').read_text()=='OLD_PRIVATE=retain\n'
`);
});
Deno.test("WebSocket purge refuses foreign release inventory before stopping or unlinking its active runtime", async () => {
  await runtimeFilesystem(String.raw`
foreign=releases/'foreign';foreign.write_text('preserve foreign inventory')
try:execute({'operation':'remove','install_path':str(install),'purge':True});raise AssertionError('accepted foreign release')
except Refused as error:assert str(error)=='websocket.runtime_ownership_drift'
assert commands==[] and install.resolve()==old and UNIT.read_bytes()==oldunit and active
assert foreign.read_text()=='preserve foreign inventory'
`);
});

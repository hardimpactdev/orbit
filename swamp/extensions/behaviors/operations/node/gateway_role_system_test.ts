import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  gatewayCaddyOrdering,
  GatewayRoleProgram,
  gatewayRoleSystem,
} from "./gateway_role_system.ts";
const GatewayCaddyOrderingProgram = await Deno.readTextFile(
  new URL("../fleet-recipe/scripts/caddy_ordering.py", import.meta.url),
);
import type { SystemTransport } from "./system_command.ts";

const input = {
  operation: "converge",
  address: "10.42.0.1",
  domain: "orbit",
} as const;
const Fixture = String.raw`
import shutil
root = Path(tempfile.mkdtemp(prefix='orbit-gateway-dns-'))
DROP_IN = root/'etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf'
PEER_STATE = root/'etc/wireguard/orbit.dns-link'
LOCK = root/'wireguard-peer.lock'
UID, GID = os.getuid(), os.getgid()
commands=[]
link=True
fail=None
def command(argv, required=True):
    # Every external effect must run while the canonical shared peer lock is held.
    with LOCK.open('a') as second:
        try:
            fcntl.flock(second.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
            raise AssertionError('effect escaped shared lock')
        except BlockingIOError: pass
    commands.append(argv)
    if argv[0]=='/usr/bin/ip': return link
    if fail is not None and argv[1]==fail: raise RuntimeError('private command diagnostic')
    return True
data={'operation':'converge','address':'10.42.0.1','domain':'orbit'}
def outcome(data):
    import contextlib,io
    stream=io.StringIO()
    with contextlib.redirect_stdout(stream): main(data)
    return json.loads(stream.getvalue())
`;

async function python(body: string) {
  const source = GatewayRoleProgram + Fixture + "\ntry:\n" +
    body.split("\n").map((line) => "    " + line).join("\n") +
    "\nfinally:\n    shutil.rmtree(root)\n";
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
  const [output] = await Promise.all([child.output(), writing]);
  if (!output.success) {
    throw new Error(new TextDecoder().decode(output.stderr));
  }
  return new TextDecoder().decode(output.stdout);
}

Deno.test("Gateway DNS writes the guarded drop-in and applies suffix/default/server in order under the peer lock", async () => {
  await python(String.raw`
assert outcome(data)=={'completed':True,'changed':True}
assert DROP_IN.read_text()==('# Managed by Orbit.\n[Service]\n'
    "ExecStartPost=-/bin/sh -c 'test -e /etc/wireguard/orbit.dns-link || { resolvectl domain orbit \"~orbit\" && resolvectl default-route orbit false && resolvectl dns orbit 10.42.0.1; }'\n")
assert DROP_IN.stat().st_mode&0o777==0o644
assert DROP_IN.stat().st_uid==UID and DROP_IN.stat().st_gid==GID
assert DROP_IN.parent.stat().st_mode&0o777==0o755
assert commands==[
    ['/usr/bin/systemctl','daemon-reload'],
    ['/usr/bin/ip','link','show','dev','orbit'],
    ['/usr/bin/resolvectl','domain','orbit','~orbit'],
    ['/usr/bin/resolvectl','default-route','orbit','false'],
    ['/usr/bin/resolvectl','dns','orbit','10.42.0.1']]
inode=DROP_IN.stat().st_ino
commands.clear()
assert outcome(data)=={'completed':True,'changed':False}
assert DROP_IN.stat().st_ino==inode
assert commands[0][0]=='/usr/bin/ip' and len(commands)==4
assert not list(DROP_IN.parent.glob('*.candidate'))
`);
});

Deno.test("Gateway DNS preserves managed-peer resolver ownership on converge and cleanup", async () => {
  await python(String.raw`
PEER_STATE.parent.mkdir(parents=True)
PEER_STATE.write_text('peer-owned-resolver-policy')
assert outcome(data)=={'completed':True,'changed':True}
assert commands==[['/usr/bin/systemctl','daemon-reload']]
commands.clear()
assert outcome({'operation':'remove'})=={'completed':True,'changed':True}
assert commands==[['/usr/bin/systemctl','daemon-reload']]
assert PEER_STATE.read_text()=='peer-owned-resolver-policy'
assert not DROP_IN.exists()
`);
});

Deno.test("Gateway DNS missing orbit link leaves live resolution untouched while retaining restart policy", async () => {
  await python(String.raw`
link=False
assert outcome(data)=={'completed':True,'changed':True}
assert DROP_IN.exists()
assert commands==[['/usr/bin/systemctl','daemon-reload'],['/usr/bin/ip','link','show','dev','orbit']]
commands.clear()
assert outcome({'operation':'remove'})=={'completed':True,'changed':True}
assert not DROP_IN.exists()
assert commands==[['/usr/bin/systemctl','daemon-reload'],['/usr/bin/ip','link','show','dev','orbit']]
`);
});

Deno.test("Gateway DNS failure before server selection cannot make the link a default DNS route", async () => {
  for (const phase of ["domain", "default-route"]) {
    const output = await python(`
fail=${JSON.stringify(phase)}
result=outcome(data)
assert result=={'completed':False,'error_code':'vpn.dns_resolver_failed'}
assert DROP_IN.exists()
assert not any(argv[0]=='/usr/bin/resolvectl' and argv[1]=='dns' for argv in commands)
print(json.dumps(result))
`);
    assertEquals(JSON.parse(output), {
      completed: false,
      error_code: "vpn.dns_resolver_failed",
    });
  }
});

Deno.test("Gateway DNS cleanup reverts only its own existing policy and preserves sibling drop-ins", async () => {
  await python(String.raw`
DROP_IN.parent.mkdir(parents=True)
DROP_IN.write_text(drop_in(data['address'],data['domain']))
sibling=DROP_IN.parent/'foreign.conf';sibling.write_text('keep')
assert outcome({'operation':'remove'})=={'completed':True,'changed':True}
assert sibling.read_text()=='keep'
assert commands==[
    ['/usr/bin/systemctl','daemon-reload'],
    ['/usr/bin/ip','link','show','dev','orbit'],
    ['/usr/bin/resolvectl','revert','orbit']]
commands.clear()
assert outcome({'operation':'remove'})=={'completed':True,'changed':False}
assert commands==[]
`);
});

Deno.test("Gateway DNS invalid input and shared-lock refusal make no resolver or filesystem changes", async () => {
  await python(String.raw`
for patch in [{'address':'10.1.2.3; touch /tmp/no'}, {'domain':'orbit;evil'}, {'domain':'-orbit'}, {'domain':'a'*64+'.orbit'}]:
    assert outcome({**data,**patch})=={'completed':False,'error_code':'vpn.configuration_invalid'}
assert not DROP_IN.exists() and not LOCK.exists() and commands==[]
tick=[0]
def clock():tick[0]+=31;return tick[0]
time.monotonic=clock
def held(*args):raise BlockingIOError()
fcntl.flock=held
assert outcome(data)=={'completed':False,'error_code':'vpn.dns_resolver_failed'}
assert not DROP_IN.exists() and commands==[]
`);
});

Deno.test("Gateway DNS public API validates before dispatch and returns only bounded outcomes", async () => {
  let calls = 0;
  const execute: SystemTransport = (command, signal) => {
    calls++;
    assertEquals(signal, undefined);
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(command.args, ["-n", "/usr/bin/python3", "-"]);
    assertEquals(command.timeoutMs, 90000);
    assertEquals(command.maxOutputBytes, 4096);
    assertEquals(command.stdin?.includes(GatewayRoleProgram), true);
    return Promise.resolve({
      success: true,
      stdout: '{"completed":true,"changed":true}',
    });
  };
  for (
    const value of [
      { ...input, domain: "bad;domain" },
      { ...input, domain: "orbit\n" },
      { ...input, domain: "-orbit" },
      { ...input, domain: "a".repeat(64) },
      { ...input, address: "127.0.0.1;evil" },
      { ...input, source: "foreign code" },
      { operation: "remove", domain: "orbit" },
    ]
  ) {
    assertEquals(await gatewayRoleSystem(value, execute), {
      completed: false,
      error_code: "vpn.configuration_invalid",
    });
  }
  assertEquals(calls, 0);
  assertEquals(await gatewayRoleSystem(input, execute), {
    completed: true,
    changed: true,
  });
  assertEquals(calls, 1);
  assertEquals(
    await gatewayRoleSystem({
      ...input,
      address: "fd00::1",
      domain: "VPN.Corp.",
    }, execute),
    { completed: true, changed: true },
  );
  for (
    const stdout of [
      "private diagnostic",
      '{"completed":true}',
      "x".repeat(4097),
    ]
  ) {
    assertEquals(
      await gatewayRoleSystem(
        input,
        () => Promise.resolve({ success: true, stdout }),
      ),
      { completed: false, error_code: "vpn.dns_resolver_failed" },
    );
  }
  assertEquals(
    await gatewayRoleSystem(input, () => Promise.reject(new Error("private"))),
    {
      completed: false,
      error_code: "vpn.dns_resolver_failed",
    },
  );
  await assertRejects(() =>
    gatewayRoleSystem(input, execute, AbortSignal.abort())
  );
});

Deno.test("Gateway DNS public API runs its fixed program against real scratch filesystem", async () => {
  assertEquals(
    await gatewayRoleSystem(input, async (command) => {
      const offset = command.stdin!.lastIndexOf("\nmain(");
      const source = command.stdin!.slice(0, offset) + Fixture +
        "\ntry:\n    " + command.stdin!.slice(offset + 1).trim() +
        "\nfinally:\n    shutil.rmtree(root)\n";
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
      if (!result.success) {
        throw new Error(new TextDecoder().decode(result.stderr));
      }
      return { success: true, stdout: new TextDecoder().decode(result.stdout) };
    }),
    { completed: true, changed: true },
  );
});

async function orderingPython(body: string) {
  const fixture = String.raw`
import shutil
ROOT=Path(tempfile.mkdtemp(prefix='orbit-caddy-order-'))
(ROOT/'etc/systemd/system').mkdir(parents=True)
UID,GID=os.getuid(),os.getgid()
commands=[];active=True;failures=[]
def control(args,probe=False):
    commands.append(args)
    if len(commands) in failures:raise Refused()
    if probe:return active
    return True
import contextlib,io
def outcome():
    output=io.StringIO()
    with contextlib.redirect_stdout(output):main()
    return json.loads(output.getvalue())
directory=ROOT/'etc/systemd/system/caddy.service.d';path=directory/'orbit-vpn.conf'
`;
  const source = GatewayCaddyOrderingProgram + fixture + "\ntry:\n" +
    body.split("\n").map((line) => "    " + line).join("\n") +
    "\nfinally:shutil.rmtree(ROOT)\n";
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
Deno.test("Gateway Caddy ordering installs exact dependency drop-in, repeats without restart and restarts inactive Caddy", async () => {
  await orderingPython(String.raw`
assert outcome()=={'completed':True,'changed':True}
assert path.read_bytes()==b'# Managed by Orbit.\n[Unit]\nAfter=wg-quick@orbit.service\nWants=wg-quick@orbit.service\n'
assert path.stat().st_mode&0o7777==0o644 and directory.stat().st_mode&0o7777==0o755
assert commands==[['is-active','--quiet','caddy'],['daemon-reload'],['restart','caddy']]
inode=path.stat().st_ino;commands.clear()
assert outcome()=={'completed':True,'changed':False} and path.stat().st_ino==inode
assert commands==[['is-active','--quiet','caddy']]
active=False;commands.clear()
assert outcome()=={'completed':True,'changed':True}
assert commands==[['is-active','--quiet','caddy'],['restart','caddy']]
`);
});
Deno.test("Gateway Caddy ordering repairs only owned file/directory modes and preserves sibling drop-ins", async () => {
  await orderingPython(String.raw`
directory.mkdir();path.write_text('prior bytes\n');path.chmod(0o640)
sibling=directory/'foreign.conf';sibling.write_text('keep')
assert outcome()=={'completed':True,'changed':True}
assert sibling.read_text()=='keep' and path.read_bytes()==CONTENT
commands.clear();directory.chmod(0o700)
assert outcome()=={'completed':True,'changed':True}
assert commands==[['is-active','--quiet','caddy']]
assert directory.stat().st_mode&0o7777==0o755
`);
});
Deno.test("Gateway Caddy ordering refuses a symlink ancestor before service calls or writes", async () => {
  await orderingPython(String.raw`
outside=ROOT/'outside';outside.mkdir()
directory.symlink_to(outside)
assert outcome()=={'completed':False,'error_code':'service.ordering_unsafe'}
assert commands==[] and list(outside.iterdir())==[]
`);
});
Deno.test("Gateway Caddy ordering restores exact prior file/directory modes and active state on confirmed activation failure", async () => {
  await orderingPython(String.raw`
directory.mkdir();directory.chmod(0o700);path.write_text('prior bytes\n');path.chmod(0o640)
failures=[3]
assert outcome()=={'completed':False,'error_code':'service.ordering_failed'}
assert path.read_text()=='prior bytes\n' and path.stat().st_mode&0o7777==0o640 and directory.stat().st_mode&0o7777==0o700
assert commands[-1]==['restart','caddy']
commands.clear();active=False;failures=[3]
assert outcome()=={'completed':False,'error_code':'service.ordering_failed'}
assert commands[-1]==['stop','caddy']
commands.clear();active=True;failures=[3,5]
assert outcome()=={'completed':False,'error_code':'service.ordering_recovery_failed'}
`);
});
Deno.test("Gateway Caddy first-install failure restores absence and transport uncertainty throws sanitized errors", async () => {
  await orderingPython(String.raw`
failures=[3]
assert outcome()=={'completed':False,'error_code':'service.ordering_failed'}
assert not directory.exists()
`);
  const refusal = {
    completed: false,
    error_code: "service.ordering_failed",
  } as const;
  assertEquals(
    await gatewayCaddyOrdering(() =>
      Promise.resolve({ success: true, stdout: JSON.stringify(refusal) }),
      undefined,
      GatewayCaddyOrderingProgram,
    ),
    refusal,
  );
  for (
    const invoke of [
      () => Promise.reject(new Error("private sentinel")),
      () => Promise.resolve({ success: false, stdout: "private sentinel" }),
      () => Promise.resolve({ success: true, stdout: "unknown output" }),
      () => Promise.resolve({ success: true, stdout: '{"completed":true}' }),
    ]
  ) {
    const error = await assertRejects(() => gatewayCaddyOrdering(invoke, undefined, GatewayCaddyOrderingProgram));
    assertEquals(String(error).includes("private sentinel"), false);
  }
  await assertRejects(() =>
    gatewayCaddyOrdering(
      () => Promise.reject(new Error("must not run")),
      AbortSignal.abort(),
    )
  );
});

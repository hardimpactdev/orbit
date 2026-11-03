/** The shipped Python rule program against the UFW double; no host firewall calls. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { python, setup } from "./native_fixture.ts";

Deno.test("any writes one commented line per protocol and family above managed lines", async () => {
  const r = await python(
    setup + String.raw`
result=converge(protocol='any',source='any');before=copy.deepcopy(ufw.rows)
converge(protocol='any',source='any')
print(json.dumps([result,ufw.tuples(),list({r['comment'] for r in ufw.rows if r['to'].startswith('443')}),before==ufw.rows]))`,
  );
  assertEquals(r, [
    { backend: "active" },
    [
      ["443/tcp", "allow", "Anywhere"],
      ["443/udp", "allow", "Anywhere"],
      ["Anywhere on orbit", "allow", "10.44.0.0/24"],
      ["443/tcp (v6)", "allow", "Anywhere (v6)"],
      ["443/udp (v6)", "allow", "Anywhere (v6)"],
      ["22/tcp (v6)", "allow", "Anywhere (v6)"],
    ],
    ["orbit web"],
    true,
  ]);
});
Deno.test("a deny sits above wireguard trust and an allowed source above the deny", async () => {
  assertEquals(
    await python(
      setup + String.raw`
converge(action='deny',port='5432',source='any');converge(port='5432',source='10.44.0.6')
print(json.dumps(ufw.tuples()[:3]))`,
    ),
    [["5432/tcp", "allow", "10.44.0.6"], ["5432/tcp", "deny", "Anywhere"], [
      "Anywhere on orbit",
      "allow",
      "10.44.0.0/24",
    ]],
  );
});
Deno.test("narrower rules sit above broader ones whatever their action", async () => {
  const r = await python(
    setup + String.raw`
converge(action='deny',port='5432',source='any');converge(port='5432',source='192.0.2.10');first=ufw.tuples('5432')
converge(port='25',source='any');converge(action='deny',port='25',source='192.0.2.10');converge(port='25',source='192.0.2.0/24');second=ufw.tuples('25')[:3]
converge(action='deny',port='20:30',source='192.0.0.0/16')
def order():return [t for t in ufw.tuples() if not t[0].endswith('(v6)') and t[0][:2] in ('25','20')]
third=order();converge('absent',port='25',source='192.0.2.0/24')
print(json.dumps([first,second,third,order()]))`,
  );
  const narrow = ["25/tcp", "deny", "192.0.2.10"],
    network = ["25/tcp", "allow", "192.0.2.0/24"],
    broad = ["25/tcp", "allow", "Anywhere"],
    range = ["20:30/tcp", "deny", "192.0.0.0/16"];
  assertEquals(r, [
    [
      ["5432/tcp", "allow", "192.0.2.10"],
      ["5432/tcp", "deny", "Anywhere"],
      ["5432/tcp (v6)", "deny", "Anywhere (v6)"],
    ],
    [narrow, network, broad],
    [narrow, network, range, broad],
    [narrow, range, broad],
  ]);
});
Deno.test("a misplaced line moves and the check verifies order", async () => {
  const r = await python(
    setup + String.raw`
ufw.rows[1:1]=[{'to':'80/tcp','action':'allow','source':'Anywhere','comment':''},{'to':'80/tcp','action':'deny','source':'192.0.2.10','comment':'orbit'}]
converge(port='80',source='192.0.2.10',action='deny',comment='orbit');rows=ufw.tuples('80');ufw.misplace=True
print(json.dumps([rows,error(lambda:converge(port='80',source='192.0.2.11',action='deny'))]))`,
  );
  assertEquals(r, [[["80/tcp", "deny", "192.0.2.10"], [
    "80/tcp",
    "allow",
    "Anywhere",
  ]], "firewall.verify_failed"]);
});
Deno.test("matching ignores the comment and removal keeps other tuples", async () => {
  const r = await python(
    setup + String.raw`
ufw.rows[:0]=[{'to':'443/tcp','action':'allow','source':'192.0.2.1','comment':'set by hand'},{'to':'400:500/tcp','action':'allow','source':'192.0.2.1','comment':'orbit range'}]
converge();count=ufw.tuples().count(('443/tcp','allow','192.0.2.1'));result=converge('absent')
print(json.dumps([count,result,ufw.tuples()]))`,
  );
  assertEquals(r[0], 1);
  assertEquals(r[1], { backend: "absent" });
  assert(
    !r[2].some((row: string[]) => row[0] === "443/tcp" && row[2] === "192.0.2.1"),
  );
  assert(
    r[2].some((row: string[]) =>
      JSON.stringify(row) ===
        JSON.stringify(["400:500/tcp", "allow", "192.0.2.1"])
    ),
  );
});
Deno.test("a retained removal leaves the managed line", async () => {
  const rows = await python(
    setup + String.raw`
converge(action='deny',port='25');converge('absent',action='deny',port='25',retain=True)
print(json.dumps(ufw.tuples()))`,
  );
  assert(
    rows.some((r: string[]) =>
      JSON.stringify(r) ===
        JSON.stringify(["25/tcp", "deny", "192.0.2.1"])
    ),
  );
});
Deno.test("inactive failed and invalid input refuse", async () => {
  const errors = await python(
    setup + String.raw`
ufw.active=False;errors=[error(lambda:converge())];ufw.active=True;ufw.fail=True;errors.append(error(lambda:converge()))
for bad in ({'port':'ssh'},{'protocol':'icmp'},{'comment':"orbit it's"},{'comment':'web'},{'port':'9:8'},{'action':'allowlist'}):errors.append(error(lambda:converge(**bad)))
print(json.dumps(errors))`,
  );
  assertEquals(errors.length, 8);
  assertEquals(errors.slice(0, 2), [
    "firewall.backend_inactive",
    "firewall.apply_failed",
  ]);
  for (const error of errors.slice(2)) assert(typeof error === "string");
});
Deno.test("lock is created 0600 and a loose or linked lock is refused", async () => {
  const r = await python(
    setup + String.raw`
with tempfile.TemporaryDirectory() as directory:
 module.LOCK_UID=os.getuid();lock=Path(directory)/'orbit-firewall.lock';module.LOCK=str(lock)
 previous=os.umask(0o022)
 try:
  with module.transaction_lock():pass
 finally:os.umask(previous)
 mode=stat.S_IMODE(lock.stat().st_mode);lock.chmod(0o644)
 def acquire():
  with module.transaction_lock():pass
 loose=error(acquire);lock.unlink();lock.symlink_to(Path(directory)/'elsewhere');linked=error(acquire)
 print(json.dumps([mode,loose,linked]))`,
  );
  assertEquals(r[0], 0o600);
  for (const error of r.slice(1)) {
    assertStringIncludes(error, "firewall.lock_failed");
  }
});

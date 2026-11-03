import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { LOCAL_PIN, publicationFixture, run } from "../../../../tests/_support/route_fixture.ts";
async function fixture() {
  const f = await publicationFixture();
  const before = {
    records: "host-record=moving.test,10.44.0.9\n",
    catalog: JSON.stringify({ routes: { "moving.test": "10.44.0.9" } }),
  };
  const after = {
    records: "host-record=moving.test,10.44.0.10\n",
    catalog: JSON.stringify({ routes: { "moving.test": "10.44.0.10" } }),
  };
  await Deno.writeTextFile(f.records, before.records + "host-record=survivor.test,10.44.0.11\n");
  await Deno.writeTextFile(
    f.catalog,
    JSON.stringify({ routes: { "moving.test": "10.44.0.9", "survivor.test": "10.44.0.11" } }),
  );
  const dns = (success = true) =>
    run(
      f.repo,
      "route-dns-publish",
      {
        node_id: 1,
        control_node_id: 209,
        connection: f.connection,
        host_keys: [LOCAL_PIN],
        before,
        after,
        alternatives: [],
      },
      "node.add",
      success,
    );
  return { ...f, before, after, dns };
}
const activation = [["dnsmasq", "--test"], ["systemctl", "restart", "dnsmasq"], [
  "systemctl",
  "enable",
  "--now",
  "orbit-private-dns.socket",
]];
Deno.test("DNS publication keeps survivors and validates before activation", async () => {
  await using f = await fixture();
  assertEquals((await f.dns()).run.status, "succeeded");
  const records = await Deno.readTextFile(f.records);
  assert(records.includes("host-record=moving.test,10.44.0.10"));
  assert(records.includes("host-record=survivor.test,10.44.0.11"));
  assertEquals(JSON.parse(await Deno.readTextFile(f.catalog)).routes["survivor.test"], "10.44.0.11");
  assertEquals(f.calls(), activation);
});
Deno.test("DNS validation failure skips backend and listener", async () => {
  await using f = await fixture();
  f.repo.env.FIXTURE_FAIL = "validate";
  await f.dns(false);
  assertEquals(f.calls(), activation.slice(0, 1));
});

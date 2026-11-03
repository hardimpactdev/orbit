import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { LOCAL_PIN, publicationFixture, run, sha256 } from "../../../../tests/_support/route_fixture.ts";
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
  const standalone = async (success = true) => {
    const digest = await sha256(after.catalog);
    const result = await run(
      f.repo,
      "operation-route-publication",
      { node_id: 1, connection: f.connection, host_keys: [LOCAL_PIN], after: { ...after, digest } },
      "route.private_dns_publish",
      success,
    );
    const terminal = result.history.jobs[0].steps.find((s) => s.name === "result")!;
    return { ...result, result: JSON.parse(String(terminal.outputs?.stdout)), digest };
  };
  return { ...f, before, after, dns, standalone };
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
Deno.test("standalone DNS publication replaces the whole admitted catalog", async () => {
  await using f = await fixture();
  const { run, result, digest } = await f.standalone();
  assertEquals(run.status, "succeeded");
  assertEquals(JSON.parse(await Deno.readTextFile(f.catalog)), JSON.parse(f.after.catalog));
  assert(!(await Deno.readTextFile(f.records)).includes("survivor.test"));
  assertEquals(result.data.digest, digest);
});
for (const backend of [false, true]) {
  Deno.test(`standalone DNS ${backend ? "backend" : "validation"} failure preserves the original failure`, async () => {
    await using f = await fixture();
    f.repo.env.FIXTURE_FAIL = backend ? "backend" : "validate";
    const before = JSON.parse(await Deno.readTextFile(f.catalog));
    const { run, result } = await f.standalone(false);
    assertEquals(run.status, "failed");
    assertEquals(result, { ready: false, error_code: "private_dns.publication_failed", restored: backend });
    assertEquals(JSON.parse(await Deno.readTextFile(f.catalog)), before);
    assertEquals(f.calls(), backend ? [...activation.slice(0, 2), ...activation] : [activation[0], activation[0]]);
  });
}

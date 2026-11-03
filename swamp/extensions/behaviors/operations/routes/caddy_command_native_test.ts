import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { LOCAL_PIN, publicationFixture, run, sha256 } from "../../../../tests/_support/route_fixture.ts";
async function fixture() {
  const f = await publicationFixture();
  const content = "# Managed by Orbit: Node Caddy build\n{ admin off }\n";
  const version = (await sha256(content)).slice(0, 32);
  const caddy = (fail = "", success = true) => {
    f.repo.env.FIXTURE_FAIL = fail;
    return run(
      f.repo,
      "operation-route-caddy",
      {
        caddy_publication: {
          node_id: 9,
          user: "orbit",
          control_node_id: 209,
          host_keys: [LOCAL_PIN],
          connection: f.connection,
          caddy: { content, version, listen_addresses: ["10.44.0.9"], unserved: false },
        },
      },
      "route.caddy_converge",
      success,
    );
  };
  return { ...f, content, version, caddy };
}
Deno.test("Caddy publication replaces a symlink and rerun converges", async () => {
  await using f = await fixture();
  await Deno.writeTextFile(`${f.host}/old-config`, "previous");
  await Deno.symlink(`${f.host}/old-config`, f.live);
  const { run, history } = await f.caddy();
  assertEquals(run.status, "succeeded");
  const terminal = history.jobs.find((j) => j.name === "completion")!.steps.find((s) => s.name === "result")!;
  assertEquals(JSON.parse(String(terminal.outputs?.stdout)), { ready: true, result: "published", version: f.version });
  assertEquals(await Deno.readTextFile(f.live), f.content);
  assert(!(await Deno.lstat(f.live)).isSymlink);
  assertEquals(f.calls().map((c) => c[0]), ["caddy", "systemctl"]);
  assertEquals(f.calls().at(-1), ["systemctl", "reload", "caddy"]);
  await Deno.remove(f.audit);
  await f.caddy();
  assertEquals(await Deno.readTextFile(f.live), f.content);
});
Deno.test("Caddy validation failure skips reload", async () => {
  await using f = await fixture();
  const { run } = await f.caddy("validate", false);
  assertEquals(run.status, "failed");
  assertEquals(f.calls().map((c) => c[0]), ["caddy"]);
  assertEquals(await Deno.readTextFile(f.live), f.content);
});
Deno.test("Caddy reload failure leaves no journal and rerun succeeds", async () => {
  await using f = await fixture();
  assertEquals((await f.caddy("reload", false)).run.status, "failed");
  assertEquals([...Deno.readDirSync(f.host)].map((e) => e.name).sort(), ["Caddyfile", "php"]);
  assertEquals((await f.caddy()).run.status, "succeeded");
});

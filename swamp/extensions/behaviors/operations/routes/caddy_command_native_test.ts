import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { LOCAL_PIN, publicationFixture, run, sha256 } from "../../../../tests/_support/route_fixture.ts";
async function fixture() {
  const f = await publicationFixture();
  f.repo.env.FIXTURE_REQUIRE_PIN = "1";
  f.repo.env.FIXTURE_ALLOW_CFGMGMT = "1";
  const content = "# Managed by Orbit: Node Caddy build\n{ admin off }\n";
  const version = (await sha256(content)).slice(0, 32);
  const caddy = (fail = "", success = true, pins = [LOCAL_PIN]) => {
    f.repo.env.FIXTURE_FAIL = fail;
    f.repo.env.FIXTURE_CADDY_DRIFT = fail === "drift" ? f.live : "";
    return run(
      f.repo,
      "operation-route-caddy",
      {
        caddy_publication: {
          node_id: 9,
          user: "orbit",
          control_node_id: 209,
          host_keys: pins,
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
Deno.test("Caddy publication replaces a symlink", async () => {
  await using f = await fixture();
  await Deno.writeTextFile(`${f.host}/old-config`, "previous");
  await Deno.symlink(`${f.host}/old-config`, f.live);
  const { run, history } = await f.caddy();
  assertEquals(run.status, "succeeded");
  const steps = history.jobs[0].steps;
  assertEquals(steps.at(-1)!.name, "Check the publication");
  assertEquals(steps.at(-1)!.status, "succeeded");
  assertEquals(steps.find((s) => s.name === "Check the Caddyfile")!.outputs?.status, "compliant");
  assert(!steps.some((s) => s.name === "result"));
  assertEquals(await Deno.readTextFile(f.live), f.content);
  assert(!(await Deno.lstat(f.live)).isSymlink);
  assertEquals(f.calls().map((c) => c[0]), ["caddy", "systemctl"]);
  assertEquals(f.calls().at(-1), ["systemctl", "reload", "caddy"]);
});

Deno.test("Caddy drift after reload fails the native end assertion", async () => {
  await using f = await fixture();
  const { history } = await f.caddy("drift", false);
  const steps = history.jobs[0].steps;
  assertEquals(steps.find((s) => s.name === "Check the Caddyfile")!.outputs?.status, "non_compliant");
  assertEquals(steps.at(-1)!.status, "failed");
});
Deno.test("Caddy refuses a wrong host pin before writing", async () => {
  await using f = await fixture();
  const { history } = await f.caddy("", false, [LOCAL_PIN.slice(0, -4) + "AgIC"]);
  assertEquals(history.jobs[0].steps.find((s) => s.name === "Check the connection")!.status, "failed");
  assertEquals(f.calls(), []);
  assert(![...Deno.readDirSync(f.host)].some((entry) => entry.name === "Caddyfile"));
});

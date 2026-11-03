import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { type Data, exists, instanceFixture, KEY, sha256, TOOL } from "../../../../tests/_support/instance_fixture.ts";
async function fixture() {
  const f = await instanceFixture("operations/instance-route-workload");
  const audit = `${f.repo.dir}/audit`;
  await f.ts(
    "ssh",
    `
import { shellSplit, readStdin, passThrough } from ${JSON.stringify(TOOL)};
if (Deno.args.some(a => ["-M", "-O", "-N"].includes(a))) Deno.exit(0);
const body = await readStdin(), remote = Deno.args.at(-1)!;
const audit = (action: string) => Deno.writeTextFileSync(${JSON.stringify(audit)}, action + "\\n", { append: true });
if (remote.includes("setfacl")) { audit("acl " + shellSplit(remote).at(-1)); Deno.exit(Number(Deno.env.get("FIXTURE_ACL_EXIT") ?? 0)); }
if (remote.includes("/usr/bin/cmp")) Deno.exit(0);
if (remote.includes("php-fpm8.5")) { audit("validate"); Deno.exit(Number(Deno.env.get("FIXTURE_INVALID_POOL") ?? 0)); }
if (remote.includes("/usr/bin/systemctl")) { audit("reload"); Deno.exit(0); }
if (remote.includes("/etc/")) throw new Error("Refusing real /etc in workload fixture");
let args = shellSplit(remote); if (args[0] === "sudo") args = args.slice(args.indexOf("--") + 1);
await passThrough(args, new TextEncoder().encode(body));
`,
  );
  const pool = `${f.node}/generated/pool.conf`;
  await Deno.mkdir(`${f.node}/generated`);
  await Deno.writeTextFile(pool, "old-pool");
  await f.workflow("instance-route-workload", (flow: Data) => {
    for (const step of flow.jobs[0].steps) {
      if (step.name === "runtime-pool") {
        step.task.globalArgs.path = pool;
        delete step.task.globalArgs.owner;
        delete step.task.globalArgs.group;
      }
    }
  });
  const inputs: Data = {
    operation_id: 7, node_id: 9, kind: "route.update",
    execution_secrets: {},
    intent: {
      node_id: 9, instance_id: 5,
      connection: {
        nodeHost: "fixture", nodeUser: f.user, nodePort: 22,
        nodeIdentityFile: `${f.repo.dir}/key`,
        become: true,
      },
      host_keys: [KEY],
      plan: { acl: [], links: [], runtime: null },
    },
  };
  const production = async () => {
    inputs.intent.plan.runtime = {
      user: "orbit-app-5", version: "8.5", marker: "public-marker\n", pool: "candidate-pool",
      pool_sha256: await sha256("candidate-pool"),
    };
  };
  return {
    f, pool, audit, inputs, production, run: (success = true) => f.run("instance-route-workload", inputs, success),
  };
}
const grant = (path: string) => ({ node_id: 9, argv: ["/usr/bin/setfacl", "-m", "u:caddy:--x", "--", path] });
Deno.test("workload grants run one at a time in list order", async () => {
  const { f, inputs, run, pool, audit } = await fixture();
  await using _ = f;
  inputs.intent.plan.acl = ["/a", "/b", "/c"].map(grant);
  await run();
  assertEquals((await Deno.readTextFile(audit)).trim().split("\n"), ["acl /a", "acl /b", "acl /c"]);
  assertEquals(await Deno.readTextFile(pool), "old-pool");
});
Deno.test("a refused workload grant fails the phase", async () => {
  const { f, inputs, run } = await fixture();
  await using _ = f;
  inputs.intent.plan.acl = [grant("/a")];
  f.repo.env.FIXTURE_ACL_EXIT = "1";
  assertEquals((await run(false)).run.status, "failed");
});
Deno.test("an empty workload plan skips every effect", async () => {
  const { f, run, pool, audit } = await fixture();
  await using _ = f;
  await run();
  assertFalse(exists(audit));
  assertEquals(await Deno.readTextFile(pool), "old-pool");
});
Deno.test("production workload writes the official file before validation and reload", async () => {
  const { f, run, production, pool, audit } = await fixture();
  await using _ = f;
  await production();
  await run();
  assertEquals(await Deno.readTextFile(pool), "candidate-pool");
  assertEquals((await Deno.readTextFile(audit)).trim().split("\n"), ["validate", "reload"]);
});
Deno.test("an invalid pool blocks workload reload", async () => {
  const { f, run, production, audit } = await fixture();
  await using _ = f;
  await production();
  f.repo.env.FIXTURE_INVALID_POOL = "1";
  await run(false);
  assertFalse((await Deno.readTextFile(audit)).split("\n").includes("reload"));
});

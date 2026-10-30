import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { ParentFixture } from "./parent_fixture.ts";

Deno.test("production clone parent prepares web-root URLs and dedicated PHP before Caddy and activation", async () => {
  const f = new ParentFixture();
  f.operation.kind = "route.converge";
  f.mode = "converge";
  f.production = true;
  f.webRoot = true;
  f.immediate = true;
  f.keys = [
    "workload-certificate.cert",
    "prepare-web-root.12",
    "synchronize-web-root-urls.12",
    "production-runtime.12",
    "workload-caddy.node-2",
  ];
  assertEquals((await f.execute()).status, "succeeded");
  const phases = f.calls.filter((c) => c.path.endsWith("/run")).map((c) =>
    c.body.key
  );
  assertEquals(
    phases.indexOf("stage-commit.synchronize-web-root-urls") <
      phases.indexOf("stage-open.production-runtime"),
    true,
  );
  assertEquals(
    phases.indexOf("stage-commit.production-runtime") <
      phases.indexOf("stage-open.workload-caddy"),
    true,
  );
  assertEquals(
    phases.indexOf("stage-commit.workload-caddy") <
      phases.indexOf("stage-open.activate-route"),
    true,
  );
});

Deno.test("withdrawal re-entry settles Caddy before certificates and records ownership only after all proof", async () => {
  const f = new ParentFixture();
  f.operation.kind = "route.withdraw";
  f.mode = "withdraw";
  f.keys = ["caddy.node-2", "certificates.node-2"];
  assertEquals((await f.execute()).status, "waiting");
  assertEquals([...f.children.keys()], [f.keys[0]]);
  f.settle();
  assertEquals((await f.execute()).status, "waiting");
  assertEquals([...f.children.keys()], f.keys);
  assertEquals(f.calls.some((c) => c.body.key === "stage-open.record"), false);
  f.settle();
  assertEquals((await f.execute()).status, "succeeded");
});

Deno.test("a saved Gateway rollback branch survives parsing and never restarts the forward web-root effects", async () => {
  const f = new ParentFixture();
  f.operation.kind = "route.update";
  f.mode = "web_root";
  f.immediate = true;
  f.operation.execution_context.result = {
    route_branch: "rollback_web_root",
    failure_code: "route.workload_failed",
  };
  f.childPhases = [
    { stage: "workload-certificate", key: "workload-certificate.forward" },
    { stage: "synchronize-web-root-urls", key: "synchronize-web-root-urls.12" },
    {
      stage: "rollback-workload-certificate",
      key: "rollback-workload-certificate.restore",
    },
    {
      stage: "synchronize-web-root-urls.rollback",
      key: "synchronize-web-root-urls.rollback.12",
    },
  ];
  assertEquals((await f.execute()).status, "failed");
  assertEquals([...f.children.keys()], [
    "rollback-workload-certificate.restore",
    "synchronize-web-root-urls.rollback.12",
  ]);
  assertEquals(
    f.calls.some((c) => c.body.key === "stage-open.web-root-stored"),
    false,
  );
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/complete"))[0].body.error_code,
    "route.workload_failed",
  );
});

Deno.test("a lost parent completion reply replays only permanent completion in a new workflow instance", async () => {
  const f = new ParentFixture();
  f.operation.kind = "route.withdraw";
  f.mode = "withdraw";
  f.keys = ["caddy.node-2"];
  f.immediate = true;
  f.loseComplete = true;
  await assertRejects(() => f.execute(), Error, "lost parent completion reply");
  const before = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.slice(before).map((call) => call.path), [
    "/api/v1/operations/17",
    "/api/v1/operation-executor/17/heartbeat",
    "/api/v1/operation-executor/17/complete",
  ]);
  assertEquals(f.children.size, 1);
});

Deno.test("an active private development domain replacement waits for publication before cutover and source cleanup", async () => {
  const f = new ParentFixture();
  f.operation.kind = "route.update";
  f.mode = "replacement";
  f.keys = ["workload-caddy.node-2", "dns-published.node-1"];

  assertEquals((await f.execute()).status, "waiting");
  assertEquals([...f.children.keys()], ["workload-caddy.node-2"]);
  assertEquals(
    f.calls.some((c) => c.body.key === "stage-open.database-cutover"),
    false,
  );
  f.settle();
  assertEquals((await f.execute()).status, "waiting");
  assertEquals([...f.children.keys()], f.keys);
  assertEquals(
    f.calls.some((c) => c.body.key === "stage-open.prepare-cleanup"),
    false,
  );
  f.settle();
  assertEquals((await f.execute()).status, "succeeded");
  const commits = f.calls.filter((c) =>
    c.path.endsWith("/run") && String(c.body.key).startsWith("stage-commit.")
  ).map((c) => c.body.key);
  assertEquals(
    commits.indexOf("stage-commit.dns-published") <
      commits.indexOf("stage-commit.database-cutover"),
    true,
  );
  assertEquals(
    commits.indexOf("stage-commit.database-cutover") <
      commits.indexOf("stage-commit.prepare-cleanup"),
    true,
  );
  assertEquals(commits.at(-1), "stage-commit.complete-replacement");
});

Deno.test("an unserved pending domain replacement completes without publishing a Node", async () => {
  const f = new ParentFixture();
  f.operation.kind = "route.update";
  f.mode = "replace_pending";
  f.keys = [];

  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.children.size, 0);
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/run")).map((c) => c.body.key),
    [
      "parent-prepare",
      "stage-open.replace-pending",
      "stage-commit.replace-pending",
      "route-proof",
    ],
  );
});

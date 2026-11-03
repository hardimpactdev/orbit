import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { type Data } from "../../../../tests/_support/instance_fixture.ts";
async function fixture(mode: string) {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await Deno.mkdir(`${repo.dir}/extensions/behaviors`, { recursive: true });
  await repo.copyBehaviors(".");
  await Deno.writeTextFile(
    `${repo.dir}/models/orbit-gateway.yaml`,
    stringify({
      type: "@nckrtl/orbit-gateway", typeVersion: "2026.10.30.1", id: "f5fa45d6-6307-48b6-a094-9aae8f79febc",
      name: "orbit-gateway", version: 1,
      globalArguments: {},
      methods: {},
    }),
  );
  await Deno.mkdir(`${repo.dir}/tools`);
  await repo.fakeBinary("tools/curl", 'console.error("forbidden-gateway-call"); Deno.exit(93);');
  repo.env.PATH = `${repo.dir}/tools:${Deno.env.get("PATH")}`;
  if (mode === "observe") await repo.workflow("project-dependency-evidence");
  await repo.workflow(`project-${mode}`, (flow) => {
    for (const job of flow.jobs) {
      job.steps = JSON.parse(
        JSON.stringify(job.steps).replaceAll("/home/orbit/.cache/orbit-dependency-scans/", `${repo.dir}/scans/`),
      );
    }
  });
  const inputs: Data = {
    operation_id: 901, node_id: 209,
    kind: `project.${mode}`,
    execution_secrets: {},
    intent: {
      project: {
        id: 32,
        repository: "https://github.com/nckrtl/launch.git",
        default_branch: "main", check: "composer check",
      },
      groups: [], admitted_at: "2026-11-01T04:00:00Z",
    },
  };
  const run = async (success = true) => {
    if (mode === "observe") {
      inputs.intent.pr_urls = [
        ...new Set(
          inputs.intent.groups.filter((g: Data) => !["completed", "cancelled", "failed"].includes(g.status)).flatMap((
            g: Data,
          ) => [g.pr_url, g.watched_pr_url]).filter(Boolean),
        ),
      ];
    }
    await repo.cli(["workflow", "validate", `project-${mode}`]);
    const { history } = await repo.runWorkflow(`project-${mode}`, inputs, { success });
    const steps = Object.fromEntries((history as Data).jobs[0].steps.map((s: Data) => [s.name, s]));
    return {
      history, steps,
      result: steps[mode === "observe" ? "Refresh the findings index" : "Select this week's routine"]?.outputs?.result,
    };
  };
  const github = async (fail = false) => {
    const url = "https://github.com/nckrtl/launch/pull/17";
    inputs.intent.groups = [{ id: 55, project_id: 32, status: "running", pr_url: url }];
    await repo.fakeBinary(
      "tools/gh",
      fail
        ? "Deno.exit(23);"
        : `console.log(JSON.stringify(${
          JSON.stringify({ number: 17, url, state: "MERGED", headRefOid: "a".repeat(40) })
        }));`,
    );
    await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
    await repo.cli(["vault", "put", "orbit-control-inputs", "project-dependency-read-901", "fixture-observe-token"]);
    inputs.execution_secrets = { github_token: { vault: "orbit-control-inputs", key: "project-dependency-read-901" } };
    return url;
  };
  return { repo, inputs, run, github };
}
Deno.test("routine returns a backlog request without Gateway calls or group execution", async () => {
  const { repo, run } = await fixture("routine");
  await using _ = repo;
  const { history, result } = await run();
  assertEquals((history as Data).status, "succeeded");
  assertEquals(result.project_id, 32);
  assertEquals(result.requests.length, 1);
  assertEquals(result.requests[0].action, "create_group");
  assertEquals(result.requests[0].input.project_id, 32);
  assertEquals(result.requests[0].input.status, "backlog");
  assertStringIncludes(result.key, "32-routine-2026-10-26");
});
Deno.test("routine names a Project without a maintenance policy", async () => {
  const { repo, inputs, run } = await fixture("routine");
  await using _ = repo;
  inputs.intent.project = {
    id: 999,
    repository: "https://github.com/example/unknown.git",
    default_branch: "main", check: "composer check",
  };
  assertStringIncludes(
    (await run(false)).steps["Select this week's routine"].error,
    "project.maintenance_not_configured",
  );
});
Deno.test("Orbit routine reads its policy and ignores old PR names in ended groups", async () => {
  const { repo, inputs, run } = await fixture("routine");
  await using _ = repo;
  inputs.intent.project = {
    id: 46,
    repository: "https://github.com/hardimpactdev/orbit.git",
    default_branch: "main", check: "composer check",
  };
  inputs.intent.groups = [{
    id: 1, project_id: 46, status: "completed",
    pr_url: "https://github.com/nckrtl/orbit/pull/1025",
    watched_pr_url: null,
  }, {
    id: 2, project_id: 46, status: "backlog",
    pr_url: "https://github.com/hardimpactdev/orbit/pull/2450",
    watched_pr_url: null,
  }];
  const { result, steps } = await run();
  assertEquals(result.project_id, 46);
  assertEquals(steps["Select this week's routine"].status, "succeeded");
});
Deno.test("routine keeps an unfinished group blocked without reading its PR", async () => {
  const { repo, inputs, run } = await fixture("routine");
  await using _ = repo;
  inputs.intent.groups = [{
    id: 1, project_id: 32, status: "running",
    pr_url: "https://github.com/someone/else/pull/1",
    watched_pr_url: null,
  }];
  assertEquals((await run()).result.requests, []);
});
Deno.test("routine rejects a Project mismatch before downstream requests", async () => {
  const { repo, inputs, run } = await fixture("routine");
  await using _ = repo;
  inputs.intent.project.check = "a different check";
  const { history, steps } = await run(false);
  assertEquals((history as Data).status, "failed");
  assertEquals(steps["Select this week's routine"].status, "failed");
});
Deno.test("observe returns public evidence without Gateway calls", async () => {
  const { repo, run } = await fixture("observe");
  await using _ = repo;
  assertEquals((await run()).result, { project_id: 32, observations: [], resume_group_ids: [], requests: [] });
});
Deno.test("observe correlates one bounded PR read with its captured group", async () => {
  const { repo, run, github } = await fixture("observe");
  await using _ = repo;
  const url = await github();
  const { result } = await run();
  assertEquals(result.observations, [{ group_id: 55, url, state: "merged", head: "a".repeat(40) }]);
  assertEquals(result.requests, []);
});
Deno.test("a failed GitHub read projects no partial evidence", async () => {
  const { repo, run, github } = await fixture("observe");
  await using _ = repo;
  await github(true);
  const { history, steps } = await run(false);
  assertEquals((history as Data).status, "failed");
  assertEquals(steps["Read the watched PRs"].status, "failed");
  assertEquals(steps["Refresh the findings index"].status, "skipped");
});

import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { recordQuery } from "../../../../tests/fixtures/worker_query.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { dependencyMerge } from "./mod.ts";
import { fixtureTokens, policyFixture, publicPr } from "./policy_fixture.ts";

const head = "a".repeat(40), merge = "b".repeat(40);
const secret = "fixture-private-token";
class Fixture {
  op = {
    id: 7,
    kind: "tasks.dependency_merge",
    node_id: 209,
    status: "queued",
    cancel_requested: false,
    result: null as unknown,
    input: {
      node_id: 209,
      project_id: 20,
      group_id: 91,
      publish_operation_id: 8,
      repository: "acme/widgets",
      default_branch: "main",
      smoke_workflow: ".github/workflows/production-smoke.yml",
      pr_number: 3,
      head_sha: head,
    },
  };
  pr = publicPr();
  evidence = policyFixture(this.pr);
  revocations: string[] = [];
  calls: string[] = [];
  enabled = true;
  configuration = {
    projectId: 20,
    repository: "acme/widgets",
    defaultBranch: "main",
    smokeWorkflow: ".github/workflows/production-smoke.yml",
  };
  refresh = true;
  writes = 0;
  loseMerge = false;
  loseProjection = false;
  status = 200;
  http: Http = async (method, path, body) => {
    await Promise.resolve();
    this.calls.push(`${method} ${path}`);
    if (path.includes("private-material")) {
      return { status: 200, body: { data: { token: fixtureTokens } } };
    }
    if (method === "POST") {
      const projected = body as Record<string, unknown>;
      if (projected.status === "succeeded" && this.loseProjection) {
        this.loseProjection = false;
        throw new Error("lost projection");
      }
      this.op.status = String(projected.status);
      this.op.result = projected.result ?? this.op.result;
    }
    return { status: 200, body: { data: structuredClone(this.op) } };
  };
  request: typeof fetch = async (url, options) => {
    await Promise.resolve();
    assertEquals(options?.redirect, "error");
    const credential =
      (options?.headers as Record<string, string>).Authorization;
    if (options?.method === "DELETE") {
      this.revocations.push(credential);
      return new Response(null, { status: 204 });
    }
    const expected = String(url).includes("/actions/")
      ? fixtureTokens.actions_token
      : String(url).includes("/check-runs")
      ? fixtureTokens.checks_token
      : String(url).includes("/contents/")
      ? fixtureTokens.read_token
      : fixtureTokens.write_token;
    assertEquals(credential, `Bearer ${expected}`);
    this.calls.push(`${options?.method} ${url}`);
    if (options?.method === "GET") {
      const path = String(url).replace("https://api.github.com", "");
      if (path === "/repos/acme/widgets/pulls/3") return Response.json(this.pr);
      if (!(path in this.evidence)) {
        throw new Error("Unexpected evidence endpoint");
      }
      return Response.json(this.evidence[path]);
    }
    assertEquals(
      String(url),
      "https://api.github.com/repos/acme/widgets/pulls/3/merge",
    );
    assertEquals(JSON.parse(String(options?.body)), {
      sha: head,
      merge_method: "squash",
    });
    this.writes++;
    if (this.status !== 200) {
      return Response.json({ message: secret }, { status: this.status });
    }
    this.pr.merged = true;
    this.pr.state = "closed";
    this.pr.merge_commit_sha = merge;
    if (this.loseMerge) {
      this.loseMerge = false;
      throw new Error(secret);
    }
    return Response.json({ merged: true, sha: merge });
  };
  async run() {
    const ctx = {
      ...createModelTestContext().context,
      globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "fixture" },
      invokeModel: () => Promise.resolve({ ok: this.refresh }),
      queryData: recordQuery(() =>
        Promise.resolve([{
          ...this.configuration,
          enabled: this.enabled,
        }])
      ),
    };
    return await dependencyMerge(() => this.http, this.request).methods.execute
      .execute({
        operation_id: 7,
        node_id: 209,
        kind: "tasks.dependency_merge",
        run_id: "3fc3ad54-423a-4b4b-8187-99514098426b",
      }, ctx);
  }
}
Deno.test("direct merge sends the fixed exact-head squash and projects only public proof", async () => {
  const f = new Fixture();
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(f.op.result, { merged: true, head_sha: head, merge_sha: merge });
  assertEquals(f.writes, 1);
  assertEquals(f.revocations.length, 4);
  await f.run();
  assertEquals(f.writes, 1);
  assertEquals(JSON.stringify(f.op).includes(secret), false);
});
for (const boundary of ["loseMerge", "loseProjection"] as const) {
  Deno.test(`resume after ${boundary} reads the exact merged head without another PUT`, async () => {
    const f = new Fixture();
    f[boundary] = true;
    const error = await assertRejects(() => f.run());
    assertEquals(String(error).includes(secret), false);
    assertEquals(await f.run(), { status: "succeeded" });
    assertEquals(f.writes, 1);
  });
}
Deno.test("private switch disablement or unavailable refresh prevents a PUT", async () => {
  for (const change of ["disabled", "refresh"]) {
    const f = new Fixture();
    if (change === "disabled") f.enabled = false;
    else f.refresh = false;
    await assertRejects(() => f.run());
    assertEquals(f.writes, 0);
  }
});
Deno.test("cancellation before an effect never reads credentials", async () => {
  const f = new Fixture();
  f.op.cancel_requested = true;
  assertEquals(await f.run(), { status: "cancelled" });
  assertEquals(f.writes, 0);
});
Deno.test("changed PR identity and GitHub refusal expose only bounded codes", async () => {
  for (const change of ["head", "base", "repository", "refused"]) {
    const f = new Fixture();
    if (change === "head") {
      (f.pr.head as Record<string, unknown>).sha = "c".repeat(40);
    }
    if (change === "base") (f.pr.base as Record<string, unknown>).ref = "other";
    if (change === "repository") {
      ((f.pr.head as Record<string, unknown>).repo as Record<string, unknown>)
        .full_name = "foreign/widgets";
    }
    if (change === "refused") f.status = 409;
    await assertRejects(() => f.run());
    assertEquals(f.writes, change === "refused" ? 1 : 0);
    assertEquals(f.op.status, "failed");
    assertEquals(JSON.stringify(f.op).includes(secret), false);
  }
});
Deno.test("wrong placed Node refuses before private configuration or GitHub", async () => {
  const f = new Fixture();
  f.op.input.node_id = 14;
  await assertRejects(() => f.run());
  assertEquals(f.writes, 0);
});

Deno.test("configuration scope changes refuse before any PUT", async () => {
  for (
    const change of [
      "projectId",
      "repository",
      "defaultBranch",
      "smokeWorkflow",
    ] as const
  ) {
    const f = new Fixture();
    if (change === "projectId") f.configuration.projectId++;
    else f.configuration[change] = "changed";
    await assertRejects(() => f.run());
    assertEquals(f.writes, 0);
  }
});
for (const boundary of ["loseMerge", "loseProjection"] as const) {
  Deno.test(`disablement after ${boundary} retains the exact merged receipt`, async () => {
    const f = new Fixture();
    f[boundary] = true;
    await assertRejects(() => f.run());
    f.enabled = false;
    assertEquals(await f.run(), { status: "succeeded" });
    assertEquals(f.writes, 1);
  });
}

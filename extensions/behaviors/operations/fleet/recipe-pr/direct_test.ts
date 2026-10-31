import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { recipePr } from "./direct.ts";
import { RecipePrAction } from "./actions.ts";
import { RecipeGitHub } from "./api.ts";
import { GatewayOperation } from "../../_lib/direct.ts";
import { currentDate, Repository } from "./policy.ts";
import { author, head, source } from "./fixture.ts";

const args = {
  operation_id: 17,
  node_id: 14,
  kind: "fleet.recipe_pr" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const context = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/ca" },
};
function fixture(scenario = "retire") {
  const resources = new Map<string, unknown>();
  const fixtureContext = {
    ...context,
    readResource: (name: string) =>
      Promise.resolve(structuredClone(resources.get(name) ?? null)),
    writeResource: (_spec: string, name: string, value: unknown) => {
      resources.set(name, structuredClone(value));
      return Promise.resolve();
    },
  };
  const operation = {
    id: 17,
    node_id: 14,
    kind: "fleet.recipe_pr",
    status: "queued",
    input: {
      action: scenario === "pending-deployment" ? "open" : "retire",
      variant: "snapshot",
      date: currentDate(),
      pull_number: scenario === "pending-deployment" ? 0 : 99,
      head_sha: scenario === "pending-deployment" ? "" : head,
      author,
      required_check: "Required checks",
      deployed_recipe: source(),
    },
    result: null as Record<string, unknown> | null,
    cancel_requested: false,
  };
  const calls: { method: string; path: string; body: unknown }[] = [];
  let mints = 0, revokes = 0, githubReads = 0, loseProjection = false;
  const http = (method: string, path: string, body?: unknown) => {
    calls.push({ method, path, body });
    if (path.includes("/private-material?run_id=")) {
      mints++;
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            github: {
              repository: Repository,
              permissions: {
                contents: scenario === "wrong-grant" ? "read" : "write",
                pull_requests: "write",
                checks: "read",
              },
              token: "fixture-sentinel",
              expires_at: new Date(Date.now() + 3600000).toISOString(),
            },
          },
        },
      });
    }
    if (method === "POST") {
      const report = body as {
        status: string;
        result?: Record<string, unknown>;
      };
      operation.status = report.status;
      operation.result = report.result ?? null;
      if (loseProjection && report.status === "succeeded") {
        loseProjection = false;
        throw new Error("Lost committed projection");
      }
    }
    return Promise.resolve({ status: 200, body: { data: operation } });
  };
  const github: typeof fetch = (url, init) => {
    assertEquals(String(url).startsWith("https://api.github.com/"), true);
    if (init?.method === "DELETE") {
      revokes++;
      return Promise.resolve(new Response(null, { status: 204 }));
    }
    githubReads++;
    if (String(url).endsWith("/git/ref/heads/main")) {
      return Promise.resolve(Response.json({ object: { sha: head } }));
    }
    if (String(url).includes("/contents/")) {
      return Promise.resolve(Response.json({
        content: btoa(source().replace("8.0-1", "8.1-1")),
        encoding: "base64",
        sha: head,
      }));
    }
    return Promise.resolve(Response.json([]));
  };
  const behavior = recipePr(
    () => http,
    (http, args) =>
      new GatewayOperation(http, args, undefined, undefined, github),
    (token, plans) => {
      assertEquals(token, "fixture-sentinel");
      return new RecipePrAction(
        new RecipeGitHub(token, github),
        undefined,
        plans,
      );
    },
  );
  return {
    operation,
    calls,
    execute: () => behavior.methods.execute.execute(args, fixtureContext),
    counts: () => ({ mints, revokes, githubReads }),
    loseProjection: () => {
      loseProjection = true;
    },
  };
}
Deno.test("direct recipe action mints once reads GitHub projects bounded result and revokes", async () => {
  const f = fixture();
  await f.execute();
  assertEquals(f.counts(), { mints: 1, revokes: 1, githubReads: 1 });
  assertEquals(f.operation.status, "succeeded");
  assertEquals(f.operation.result, { completed: true, outcome: "absent" });
  assertEquals(JSON.stringify(f.calls).includes("fixture-sentinel"), false);
  assertEquals(f.calls.map((call) => call.path), [
    "/api/v1/direct-operations/17?run_id=" + args.run_id,
    "/api/v1/direct-operations/17/result",
    "/api/v1/direct-operations/17/private-material?run_id=" + args.run_id,
    "/api/v1/direct-operations/17/result",
  ]);
});
Deno.test("lost committed recipe projection resumes without minting or writing again", async () => {
  const f = fixture();
  f.loseProjection();
  await assertRejects(f.execute);
  await f.execute();
  assertEquals(f.counts(), { mints: 1, revokes: 1, githubReads: 1 });
});
Deno.test("deployment mismatch projects a terminal failure and revokes before any write", async () => {
  const f = fixture("pending-deployment");
  await assertRejects(f.execute, Error, "fleet.recipe_pending_deployment");
  assertEquals(f.operation.status, "failed");
  assertEquals(f.operation.result, {
    error_code: "fleet.recipe_pending_deployment",
  });
  assertEquals(f.counts(), { mints: 1, revokes: 1, githubReads: 2 });
  await assertRejects(f.execute, Error, "fleet.recipe_pending_deployment");
  assertEquals(f.counts(), { mints: 1, revokes: 1, githubReads: 2 });
  assertEquals(JSON.stringify(f.calls).includes("fixture-sentinel"), false);
});
Deno.test("a wrong grant projects a policy refusal and revokes without GitHub effects", async () => {
  const f = fixture("wrong-grant");
  await assertRejects(f.execute, Error, "fleet.recipe_pr_policy_refused");
  assertEquals(f.operation.status, "failed");
  assertEquals(f.operation.result, {
    error_code: "fleet.recipe_pr_policy_refused",
  });
  assertEquals(f.counts(), { mints: 1, revokes: 1, githubReads: 0 });
});
Deno.test("cancellation or wrong admitted Node never obtains a GitHub token", async () => {
  const cancelled = fixture();
  cancelled.operation.cancel_requested = true;
  await cancelled.execute();
  assertEquals(cancelled.operation.status, "cancelled");
  assertEquals(cancelled.counts().mints, 0);
  const foreign = fixture();
  foreign.operation.node_id = 209;
  await assertRejects(foreign.execute);
  assertEquals(foreign.counts().mints, 0);
});
Deno.test("an unbound first read cannot mint or project anything", async () => {
  let requests = 0;
  const behavior = recipePr(() => () => {
    requests++;
    return Promise.resolve({
      status: 409,
      body: { error: { code: "operation.run_mismatch" } },
    });
  });
  await assertRejects(() =>
    behavior.methods.execute.execute(args, {
      ...context,
      readResource: () => {
        throw new Error("Unexpected preparation read");
      },
      writeResource: () => {
        throw new Error("Unexpected preparation write");
      },
    })
  );
  assertEquals(requests, 1);
});

import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { recipePr } from "./direct.ts";
import { RecipePrPlanner } from "./actions.ts";
import { RecipeGitHub } from "./api.ts";
import { author, source } from "./fixture.ts";
import { currentDate } from "./policy.ts";
const token = "fixture-ephemeral-value";
function fixture(mode = "ok") {
  const calls: unknown[] = [];
  const stored: unknown[] = [];
  const behavior = recipePr(
    async (_ctx, args) => {
      calls.push([
        "POST",
        `/api/v1/direct-operations/${args.operation_id}/github-token`,
        { run_id: args.run_id },
      ]);
      return {
        data: {
          github: {
            token,
            expires_at: new Date(Date.now() + 3600000)
              .toISOString(),
            repository: "hardimpactdev/orbit",
            permissions: {
              contents: mode === "scope" ? "read" : "write",
              pull_requests: "write",
              checks: "read",
            },
          },
        },
      };
    },
    () => new RecipeGitHub(token),
    (api) => ({
      prepare: () => {
        if (mode === "secret") {
          return Promise.resolve({
            action: "none",
            token,
          });
        }
        if (mode === "throw") throw new Error(token);
        return Promise.resolve({ action: "none", outcome: "checked" });
      },
    } as Pick<RecipePrPlanner, "prepare">),
    async (_url, init) => {
      calls.push([
        "revoke",
        new Headers(init?.headers).get("Authorization"),
      ]);
      return new Response(null, { status: 204 });
    },
  );
  const args = {
    operation_id: 71,
    node_id: 209 as const,
    kind: "fleet.recipe_pr" as const,
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
    intent: {
      action: "open" as const,
      variant: "snapshot" as const,
      date: currentDate(),
      pull_number: 0,
      head_sha: "",
      author,
      required_check: "Required checks",
      deployed_recipe: source(),
    },
  };
  const ctx = {
    globalArgs: {
      gatewayUrl: "https://gateway.orbit",
      caFile: "/fixture-ca",
    },
    writeResource: (_spec: string, _name: string, value: unknown) => {
      stored.push(value);
      return Promise.resolve(value);
    },
  };
  return { behavior, args, ctx, calls, stored };
}
Deno.test("exact mint-only request, public result and attempted token revocation", async () => {
  const f = fixture();
  await f.behavior.methods.prepare.execute(f.args, f.ctx);
  assertEquals(f.calls, [[
    "POST",
    "/api/v1/direct-operations/71/github-token",
    { run_id: f.args.run_id },
  ], ["revoke", "Bearer " + token]]);
  assertEquals(f.stored, [{ action: "none", outcome: "checked" }]);
});
for (const mode of ["scope", "secret", "throw"]) {
  Deno.test(`recipe ${mode} refuses without private output and revokes`, async () => {
    const f = fixture(mode);
    const error = await assertRejects(
      () => f.behavior.methods.prepare.execute(f.args, f.ctx),
      Error,
    );
    assertEquals(error.message.includes(token), false);
    assertEquals(f.stored, []);
    assertEquals(f.calls.length, 2);
  });
}
Deno.test("native result projects no Gateway callback and needs no credential", async () => {
  const f = fixture();
  await f.behavior.methods.result.execute({
    ...f.args,
    plan: { action: "none", outcome: "absent" },
  }, f.ctx);
  assertEquals(f.calls, []);
  assertEquals(f.stored, [{
    stdout: JSON.stringify({ completed: true, outcome: "absent" }),
  }]);
});

import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { recipePr } from "./direct.ts";
import { RecipePrPlanner } from "./actions.ts";
import { RecipeGitHub } from "./api.ts";
import { author, source } from "./fixture.ts";
import { currentDate } from "./policy.ts";
const token = "fixture-ephemeral-value";
function fixture(mode = "ok") {
  const stored: unknown[] = [];
  const tokens: string[] = [];
  const behavior = recipePr(
    (secret) => {
      tokens.push(secret);
      return new RecipeGitHub(secret);
    },
    () => ({
      prepare: () => {
        if (mode === "secret") return Promise.resolve({ action: "none", token });
        if (mode === "throw") throw new Error(token);
        return Promise.resolve({ action: "none", outcome: "checked" });
      },
    } as Pick<RecipePrPlanner, "prepare">),
  );
  const args = {
    operation_id: 71,
    node_id: 209 as const,
    kind: "fleet.recipe_pr" as const,
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
    env: { GITHUB_TOKEN: token },
  };
  const ctx = {
    writeResource: (_spec: string, _name: string, value: unknown) => {
      stored.push(value);
      return Promise.resolve(value);
    },
  };
  return { behavior, args, ctx, stored, tokens };
}
Deno.test("the planner uses the run-start vault token and stores only the public plan", async () => {
  const f = fixture();
  await f.behavior.methods.prepare.execute(f.args, f.ctx);
  assertEquals(f.tokens, [token]);
  assertEquals(f.stored, [{ action: "none", outcome: "checked" }]);
});
for (const mode of ["secret", "throw"]) {
  Deno.test(`recipe ${mode} refuses without private output`, async () => {
    const f = fixture(mode);
    const error = await assertRejects(() => f.behavior.methods.prepare.execute(f.args, f.ctx), Error);
    assertEquals(error.message, "fleet.recipe_pr_policy_refused");
    assertEquals(f.stored, []);
  });
}
Deno.test("an action without the vault token refuses before any GitHub request", async () => {
  const f = fixture();
  const error = await assertRejects(() => f.behavior.methods.open.execute({ ...f.args, env: undefined, plan: { action: "open" } }, f.ctx), Error);
  assertEquals(error.message, "fleet.recipe_pr_policy_refused");
  assertEquals(f.tokens, []);
});
Deno.test("native result needs no credential", async () => {
  const f = fixture();
  await f.behavior.methods.result.execute({ ...f.args, env: undefined, plan: { action: "none", outcome: "absent" } }, f.ctx);
  assertEquals(f.tokens, []);
  assertEquals(f.stored, [{ stdout: JSON.stringify({ completed: true, outcome: "absent" }) }]);
});

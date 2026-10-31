import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { RecipePrAction } from "./actions.ts";
import { RecipeGitHub } from "./api.ts";
import { rewriteSnapshot } from "./contents.ts";
import { author, openPlanStore, source } from "./fixture.ts";
import { currentDate, RecipePath } from "./policy.ts";

// GitHub persists each write before its transport reply is lost. A new action
// instance represents a native retry; GitHub state and native preparation survive.
for (const lost of ["preparation", "ref", "content", "pull"] as const) {
  Deno.test(`recipe open resumes after committed ${lost} loses its reply`, async () => {
    const before = source();
    const date = currentDate();
    const updated = rewriteSnapshot(before, date + "T000000Z", {
      curl: "8.1-1",
    });
    const branch = "nightly/recipe-" + date;
    const main = "c".repeat(40);
    let created = false, content = before, blob = "d".repeat(40);
    let pull: Record<string, unknown> | null = null;
    let loseReply = true;
    const effects = { ref: 0, content: 0, pull: 0 };
    const send: typeof fetch = (raw, init) => {
      const url = new URL(String(raw));
      assertEquals(url.origin, "https://api.github.com");
      const path = url.pathname.replace("/repos/hardimpactdev/orbit", "");
      const method = init?.method ?? "GET";
      const body = init?.body ? JSON.parse(String(init.body)) : undefined;
      let data: unknown, status = 200, effect: typeof lost | undefined;
      if (method === "GET" && path === "/git/ref/heads/main") {
        data = { object: { sha: main } };
      } else if (method === "GET" && path === "/contents/" + RecipePath) {
        const ref = url.searchParams.get("ref");
        if (ref === branch) assertEquals(created, true);
        data = {
          content: btoa(ref === branch ? content : before),
          encoding: "base64",
          sha: ref === branch ? blob : "d".repeat(40),
        };
      } else if (method === "GET" && path === "/pulls") {
        assertEquals(url.searchParams.get("head"), "hardimpactdev:" + branch);
        data = pull ? [pull] : [];
      } else if (method === "POST" && path === "/git/refs") {
        assertEquals(body, { ref: "refs/heads/" + branch, sha: main });
        if (created) status = 422;
        else {
          created = true;
          status = 201;
          effects.ref++;
          effect = "ref";
        }
        data = {};
      } else if (method === "PUT" && path === "/contents/" + RecipePath) {
        assertEquals(body.sha, blob);
        assertEquals(body.branch, branch);
        content = atob(body.content);
        blob = "e".repeat(40);
        effects.content++;
        effect = "content";
        data = {};
      } else if (method === "POST" && path === "/pulls") {
        assertEquals(pull, null);
        assertEquals(body.head, branch);
        assertEquals(body.base, "main");
        pull = {
          number: 19,
          html_url: "https://github.com/hardimpactdev/orbit/pull/19",
          user: { login: author, type: "Bot" },
          head: { ref: branch, sha: "f".repeat(40) },
        };
        effects.pull++;
        effect = "pull";
        data = pull;
        status = 201;
      } else {throw new Error(
          "Unexpected fixture request " + method + " " + path,
        );}
      if (effect === lost && loseReply) {
        loseReply = false;
        throw new TypeError("Response lost after GitHub committed");
      }
      return Promise.resolve(Response.json(data, { status }));
    };
    const input = {
      action: "open",
      variant: "snapshot",
      date,
      pull_number: 0,
      head_sha: "",
      author,
      required_check: "Required checks",
      deployed_recipe: before,
    };
    const storage = openPlanStore();
    const plans = {
      read: storage.read,
      write: async (plan: unknown) => {
        await storage.write(plan);
        if (lost === "preparation" && loseReply) {
          loseReply = false;
          assertEquals(effects, { ref: 0, content: 0, pull: 0 });
          throw new Error("Native preparation reply lost");
        }
      },
    };
    const execute = () =>
      new RecipePrAction(new RecipeGitHub("fixture-token", send), {
        snapshot: () => Promise.resolve(updated),
        artifacts: (text) => Promise.resolve(text),
      }, plans).execute(input);
    await assertRejects(
      execute,
      Error,
      lost === "preparation"
        ? "Native preparation reply lost"
        : "fleet.recipe_github_unavailable",
    );
    assertEquals(await execute(), {
      completed: true,
      outcome: "checked",
      url: "https://github.com/hardimpactdev/orbit/pull/19",
    });
    assertEquals(content, updated);
    assertEquals(effects, { ref: 1, content: 1, pull: 1 });
    assertEquals(await execute(), {
      completed: true,
      outcome: "checked",
      url: "https://github.com/hardimpactdev/orbit/pull/19",
    });
    assertEquals(effects, { ref: 1, content: 1, pull: 1 });
  });
}

Deno.test("one artifacts intent freezes its candidate before a lost PR-create reply", async () => {
  const before = source(), date = currentDate();
  const main = "c".repeat(40);
  const branches = new Map<string, { content: string; sha: string }>();
  const pulls = new Map<string, Record<string, unknown>>();
  let lost = false, candidateReads = 0;
  const effects = { ref: 0, content: 0, pull: 0 };
  const send: typeof fetch = (raw, init) => {
    const url = new URL(String(raw));
    const path = url.pathname.replace("/repos/hardimpactdev/orbit", "");
    const method = init?.method ?? "GET";
    const body = init?.body ? JSON.parse(String(init.body)) : undefined;
    let data: unknown, status = 200;
    if (method === "GET" && path === "/git/ref/heads/main") {
      data = { object: { sha: main } };
    } else if (method === "GET" && path === "/contents/" + RecipePath) {
      const ref = url.searchParams.get("ref")!;
      const branch = branches.get(ref);
      data = {
        content: btoa(branch?.content ?? before),
        encoding: "base64",
        sha: branch?.sha ?? "d".repeat(40),
      };
    } else if (method === "GET" && path === "/pulls") {
      const branch = url.searchParams.get("head")!.replace(
        "hardimpactdev:",
        "",
      );
      data = pulls.has(branch) ? [pulls.get(branch)] : [];
    } else if (method === "POST" && path === "/git/refs") {
      const branch = body.ref.replace("refs/heads/", "");
      if (branches.has(branch)) status = 422;
      else {
        effects.ref++;
        branches.set(branch, { content: before, sha: "d".repeat(40) });
        status = 201;
      }
      data = {};
    } else if (method === "PUT" && path === "/contents/" + RecipePath) {
      const branch = branches.get(body.branch)!;
      assertEquals(body.sha, branch.sha);
      branch.content = atob(body.content);
      branch.sha = "e".repeat(40);
      effects.content++;
      data = {};
    } else if (method === "POST" && path === "/pulls") {
      assertEquals(pulls.has(body.head), false);
      const number = 19 + effects.pull++;
      const pull = {
        number,
        html_url: "https://github.com/hardimpactdev/orbit/pull/" + number,
        user: { login: author, type: "Bot" },
        head: { ref: body.head, sha: "f".repeat(40) },
      };
      pulls.set(body.head, pull);
      if (!lost) {
        lost = true;
        throw new TypeError("Lost committed create");
      }
      data = pull;
      status = 201;
    } else throw new Error("Unexpected fixture request " + method + " " + path);
    return Promise.resolve(Response.json(data, { status }));
  };
  const indexes = {
    snapshot: (text: string) => Promise.resolve(text),
    artifacts: () => {
      const recipe = JSON.parse(before);
      recipe.tools["php8.5-common"].version = `8.5.${13 + candidateReads++}-1`;
      return Promise.resolve(JSON.stringify(recipe, null, 4) + "\n");
    },
  };
  const input = {
    action: "open",
    variant: "artifacts",
    date,
    pull_number: 0,
    head_sha: "",
    author,
    required_check: "Required checks",
    deployed_recipe: before,
  };
  const plans = openPlanStore();
  const execute = () =>
    new RecipePrAction(new RecipeGitHub("fixture-token", send), indexes, plans)
      .execute(input);
  await assertRejects(execute, Error, "fleet.recipe_github_unavailable");
  await execute();
  assertEquals(effects, { ref: 1, content: 1, pull: 1 });
  assertEquals(candidateReads, 1);
});

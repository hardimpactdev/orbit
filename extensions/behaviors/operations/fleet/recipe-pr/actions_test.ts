import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { RecipePrAction } from "./actions.ts";
import { RecipeGitHub } from "./api.ts";
import { currentDate, RecipePath } from "./policy.ts";
import { rewriteSnapshot } from "./contents.ts";
import {
  author,
  branch,
  check,
  commit,
  head,
  openPlanStore,
  parent,
  pull,
  source,
} from "./fixture.ts";
const main = "c".repeat(40);
function fixture() {
  const before = source(),
    after = rewriteSnapshot(before, currentDate() + "T000000Z", {
      curl: "8.1-1",
    });
  const oldLines = before.split("\n"),
    newLines = after.split("\n"),
    patch: string[] = [];
  let changes = 0;
  for (let index = 0; index < oldLines.length; index++) {
    if (oldLines[index] !== newLines[index]) {
      patch.push("-" + oldLines[index], "+" + newLines[index]);
      changes++;
    }
  }
  const state = {
    metadata: pull(),
    commits: [commit()],
    checks: [structuredClone(check)],
    files: [{
      filename: RecipePath,
      status: "modified",
      patch: "@@\n" + patch.join("\n"),
      additions: changes,
      deletions: changes,
      changes: 2 * changes,
    }],
    pulls: [pull()],
    existing: [] as unknown[],
    timeline: [{ event: "committed", sha: head }] as unknown[],
    edits: [] as unknown[],
    hasNextPage: false,
    compare: "ahead",
    errorMutation: false,
    changedMetadata: false,
    losePushReply: false,
  };
  state.metadata.head.ref = "nightly/recipe-" + currentDate();
  state.pulls[0].head.ref = state.metadata.head.ref;
  const calls: {
    method: string;
    path: string;
    body: Record<string, unknown> | undefined;
  }[] = [];
  const send: typeof fetch = (_input, init) => {
    const url = new URL(String(_input));
    assertEquals(url.origin, "https://api.github.com");
    const path = url.pathname.replace("/repos/hardimpactdev/orbit", ""),
      method = init?.method ?? "GET";
    const body = init?.body ? JSON.parse(String(init.body)) : undefined;
    calls.push({ method, path: path + url.search, body });
    if (state.errorMutation && method !== "GET") {
      return Promise.resolve(
        new Response("SECRET diagnostic", { status: 503 }),
      );
    }
    let data: unknown;
    if (path === "/pulls" && method === "GET") {
      data = url.searchParams.get("state") === "all"
        ? state.existing
        : state.pulls;
    } else if (path === "/pulls/19" && method === "GET") {
      data = state.changedMetadata && calls.filter((call) =>
            call.path === path
          ).length > 1
        ? { ...state.metadata, title: "human edit" }
        : state.metadata;
    } else if (path === "/pulls/19/commits") data = state.commits;
    else if (path === "/pulls/19/files") data = state.files;
    else if (path.endsWith("/check-runs")) {
      data = { total_count: state.checks.length, check_runs: state.checks };
    } else if (path === "/contents/" + RecipePath && method === "GET") {
      data = {
        content: btoa(url.searchParams.get("ref") === head ? after : before),
        encoding: "base64",
        sha: parent,
      };
    } else if (path === "/git/ref/heads/main") data = { object: { sha: main } };
    else if (path.startsWith("/compare/")) data = { status: state.compare };
    else if (path === "/issues/19/timeline") data = state.timeline;
    else if (path === "/graphql") {
      data = body.query.startsWith("query")
        ? {
          data: {
            repository: {
              id: "repo-id",
              pullRequest: {
                userContentEdits: {
                  nodes: state.edits,
                  pageInfo: { hasNextPage: state.hasNextPage },
                },
              },
            },
          },
        }
        : { data: { updateRefs: {} } };
      if (body.query.startsWith("mutation") && state.losePushReply) {
        state.losePushReply = false;
        state.pulls[0].head.sha = body.variables.input.refUpdates[0].afterOid;
        throw new TypeError("Lost committed expected-head push reply");
      }
    } else if (path === "/git/commits/" + main) {
      data = { tree: { sha: "d".repeat(40) } };
    } else if (path === "/git/trees" || path === "/git/commits") {
      data = { sha: "e".repeat(40) };
    } else if (path === "/pulls/19/merge") data = { merged: true };
    else if (path === "/pulls" && method === "POST") {
      data = { html_url: "https://github.com/hardimpactdev/orbit/pull/999" };
    } else if (method !== "GET") data = {};
    else throw new Error("Unexpected fixture path: " + path);
    return Promise.resolve(
      Response.json(data, { status: path === "/git/refs" ? 201 : 200 }),
    );
  };
  const github = new RecipeGitHub("SECRET", send);
  const action = new RecipePrAction(github, {
    snapshot: () => Promise.resolve(after),
    artifacts: (content) => Promise.resolve(content),
  }, openPlanStore());
  const input = {
    action: "merge" as "open" | "update" | "merge" | "retire",
    variant: "snapshot",
    date: currentDate(),
    pull_number: 19,
    head_sha: head,
    author,
    required_check: check.name,
    deployed_recipe: before,
  };
  const mutations = () => calls.filter((call) => call.method !== "GET");
  return { state, calls, action, github, input, mutations, before, after };
}
Deno.test("recipe merge validates signed exact patch and sends one expected-head squash", async () => {
  const f = fixture();
  await f.action.execute(f.input);
  assertEquals(f.mutations(), [{
    method: "PUT",
    path: "/pulls/19/merge",
    body: { merge_method: "squash", sha: head },
  }]);
});
Deno.test("missing pending spoofed or wrong-head checks cause no merge", async () => {
  for (
    const runs of [
      [],
      [{ ...check, conclusion: "failure" }],
      [{ ...check, head_sha: parent }],
      [{ ...check, app: { slug: "spoof" } }],
      [{ ...check, status: "in_progress" }],
    ]
  ) {
    const f = fixture();
    f.state.checks = runs;
    await f.action.execute(f.input);
    assertEquals(f.mutations(), []);
  }
});
Deno.test("foreign App signature extra commits or extra files block before any GitHub write", async () => {
  for (
    const alteration of ["author", "signature", "commits", "files", "patch"]
  ) {
    const f = fixture();
    if (alteration === "author") f.state.metadata.user.login = "foreign[bot]";
    if (alteration === "signature") {
      f.state.commits[0].commit.verification
        .verified = false;
    }
    if (alteration === "commits") f.state.commits.push(commit());
    if (alteration === "files") f.state.files.push(f.state.files[0]);
    if (alteration === "patch") f.state.files[0].patch = "-old\n+unexpected";
    await assertRejects(() => f.action.execute(f.input));
    assertEquals(f.mutations(), []);
  }
});
Deno.test("changed admitted head is absent and an obsolete App nightly closes with a recheck", async () => {
  const stale = fixture();
  assertEquals(
    await stale.action.execute({ ...stale.input, head_sha: parent }),
    { completed: true, outcome: "absent" },
  );
  assertEquals(stale.mutations(), []);
  const f = fixture();
  f.state.metadata.head.ref = branch.replace("20261030", "20261028");
  f.state.pulls[0].head.ref = f.state.metadata.head.ref;
  await f.action.execute({ ...f.input, action: "retire" });
  assertEquals(f.mutations()[0], {
    method: "PATCH",
    path: "/pulls/19",
    body: { state: "closed" },
  });
});
Deno.test("closed date PR remains closed during an open request", async () => {
  const f = fixture();
  f.state.existing = [{ ...pull(), state: "closed" }];
  const result = await f.action.execute({
    ...f.input,
    action: "open",
    pull_number: 0,
    head_sha: "",
  });
  assertEquals(result, {
    completed: true,
    outcome: "checked",
    url: pull().html_url,
  });
  assertEquals(f.mutations(), []);
});
Deno.test("open performs only the fixed branch file and pull writes", async () => {
  const f = fixture();
  await f.action.execute({
    ...f.input,
    action: "open",
    pull_number: 0,
    head_sha: "",
  });
  assertEquals(f.mutations().map((call) => [call.method, call.path]), [
    ["POST", "/git/refs"],
    ["PUT", "/contents/" + RecipePath],
    ["POST", "/pulls"],
  ]);
});
Deno.test("refresh uses expected-head compare-and-swap after trusted failed CI and untouched history", async () => {
  const f = fixture();
  f.state.checks[0].conclusion = "failure";
  await f.action.execute({ ...f.input, action: "update" });
  const mutation = f.mutations().find((call) =>
    call.path === "/graphql" && String(call.body?.query).startsWith("mutation")
  );
  assertEquals((mutation!.body!.variables as { input: unknown }).input, {
    repositoryId: "repo-id",
    refUpdates: [{
      name: "refs/heads/nightly/recipe-" + currentDate(),
      beforeOid: head,
      afterOid: "e".repeat(40),
      force: true,
    }],
  });
});
Deno.test("refresh refuses human edits truncated history and pending CI without branch writes", async () => {
  for (
    const alteration of [
      "timeline",
      "edit",
      "truncated",
      "pending",
      "unchanged_parent",
    ]
  ) {
    const f = fixture();
    f.state.checks[0].conclusion = "failure";
    if (alteration === "timeline") {
      f.state.timeline = [{
        event: "closed",
        actor: { login: author, type: "Bot" },
      }];
    }
    if (alteration === "edit") {
      f.state.edits = [{ editor: { login: "operator" } }];
    }
    if (alteration === "truncated") f.state.hasNextPage = true;
    if (alteration === "pending") f.state.checks[0].status = "in_progress";
    if (alteration === "unchanged_parent") f.state.compare = "identical";
    await f.action.execute({ ...f.input, action: "update" });
    assertEquals(
      f.mutations().filter((call) =>
        !String(call.body?.query).startsWith("query")
      ),
      [],
    );
  }
});
Deno.test("uncertain mutation throws sanitized native failure and is never retried", async () => {
  const f = fixture();
  f.state.errorMutation = true;
  const error = await assertRejects(() => f.action.execute(f.input), Error);
  assertEquals(error.message, "fleet.recipe_github_unavailable");
  assertEquals(f.mutations().length, 1);
});
Deno.test("GitHub client refuses off-origin URLs and changed check pagination", async () => {
  const f = fixture();
  await assertRejects(() => f.github.get("https://foreign.test/secret"));
  assertEquals(f.calls.length, 0);
  let calls = 0;
  const github = new RecipeGitHub("SECRET", () => {
    calls++;
    return Promise.resolve(
      Response.json({ total_count: calls === 1 ? 2 : 3, check_runs: [check] }),
    );
  });
  await assertRejects(() => github.checks(head, check.name));
  assertEquals(calls, 2);
});

Deno.test("refresh retry never repeats a committed expected-head push", async () => {
  const f = fixture();
  f.input.action = "update";
  f.state.checks[0].conclusion = "failure";
  f.state.losePushReply = true;
  await assertRejects(
    () => f.action.execute(f.input),
    Error,
    "fleet.recipe_github_unavailable",
  );
  assertEquals(await f.action.execute(f.input), {
    completed: true,
    outcome: "absent",
  });
  assertEquals(
    f.mutations().filter((call) =>
      call.path === "/graphql" &&
      String(call.body?.query).startsWith("mutation")
    ).length,
    1,
  );
});

import { RecipePrPlanner } from "./actions.ts";
import { RecipeGitHub } from "./api.ts";
import { rewriteSnapshot } from "./contents.ts";
import { source } from "./fixture.ts";
import { RecipePath, Repository } from "./policy.ts";
const statePath = "recipe-native.json";
const read = async () => JSON.parse(await Deno.readTextFile(statePath));
const save = (state: unknown) =>
  Deno.writeTextFile(statePath, JSON.stringify(state));
const token = () => Deno.env.get("ORBIT_RECIPE_FIXTURE_SENTINEL")!;
export const fixtureMint = async (
  _ctx: unknown,
  args: { operation_id: number; run_id: string },
) => {
  const s = await read();
  s.calls.push({
    method: "POST",
    path: `/api/v1/direct-operations/${args.operation_id}/github-token`,
    body: { run_id: args.run_id },
  });
  s.mints++;
  await save(s);
  return {
    data: {
      github: {
        token: token(),
        expires_at: new Date(Date.now() + 3600000).toISOString(),
        repository: Repository,
        permissions: {
          contents: s.mode === "scope" ? "read" : "write",
          pull_requests: "write",
          checks: "read",
        },
      },
    },
  };
};
export const github: typeof fetch = async (raw, init) => {
  const u = new URL(String(raw)),
    method = init?.method ?? "GET",
    s = await read();
  if (
    u.origin !== "https://api.github.com" ||
    new Headers(init?.headers).get("Authorization") !== "Bearer " + token()
  ) throw new Error("Invalid fixture credential");
  if (u.pathname === "/installation/token" && method === "DELETE") {
    s.revokes++;
    await save(s);
    return new Response(null, { status: 204 });
  }
  const path = u.pathname.replace("/repos/" + Repository, "");
  s.github.push({ method, path });
  const body = init?.body ? JSON.parse(String(init.body)) : {};
  let data: unknown = {}, status = 200, phase = "";
  if (method === "GET" && path === "/git/ref/heads/main") {
    data = { object: { sha: "a".repeat(40) } };
  } else if (method === "GET" && path === "/contents/" + RecipePath) {
    const branch = s.branches[u.searchParams.get("ref")!];
    data = {
      content: btoa(branch?.content ?? source()),
      encoding: "base64",
      sha: branch?.sha ?? "b".repeat(40),
    };
  } else if (method === "GET" && path === "/pulls") {
    data = s.pull ? [s.pull] : [];
  } else if (method === "POST" && path === "/git/refs") {
    const branch = body.ref.replace("refs/heads/", "");
    if (s.branches[branch]) status = 422;
    else {
      s.branches[branch] = { content: source(), sha: "b".repeat(40) };
      s.writes++;
      status = 201;
      phase = "branch";
    }
  } else if (method === "PUT" && path === "/contents/" + RecipePath) {
    if (body.sha !== s.branches[body.branch]?.sha) {
      throw new Error("Branch drift");
    }
    s.branches[body.branch] = {
      content: atob(body.content),
      sha: "c".repeat(40),
    };
    s.writes++;
    phase = "file";
  } else if (method === "POST" && path === "/pulls") {
    if (s.pull) throw new Error("Duplicate PR");
    s.pull = {
      number: 999,
      html_url: "https://github.com/hardimpactdev/orbit/pull/999",
      user: { login: s.intent.author, type: "Bot" },
      head: { ref: body.head, sha: "d".repeat(40) },
    };
    s.writes++;
    data = s.pull;
    phase = "pull";
  } else throw new Error("Unexpected GitHub fixture request");
  await save(s);
  if (s.lose === phase) {
    s.lose = null;
    await save(s);
    throw new Error(token());
  }
  return Response.json(data, { status });
};
export const fixtureApi = (secret: string) => new RecipeGitHub(secret, github);
export const fixturePlanner = (api: RecipeGitHub) =>
  new RecipePrPlanner(api, {
    snapshot: async (content, next) => {
      const s = await read();
      s.prepares++;
      await save(s);
      return rewriteSnapshot(content, next, { curl: "8.1-1" });
    },
    artifacts: async (content) => {
      const s = await read();
      s.prepares++;
      await save(s);
      const recipe = JSON.parse(content);
      recipe.tools["php8.5-common"].version = "8.5." + (12 + s.prepares) +
        "-1";
      return JSON.stringify(recipe, null, 4) + "\n";
    },
  });

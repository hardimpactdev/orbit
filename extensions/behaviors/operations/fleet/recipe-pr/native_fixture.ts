import { type DirectIntent, GatewayOperation } from "../../_lib/direct.ts";
import type { Http } from "../../../automatic-deploy/gateway.ts";
import { type OpenPlanStore, RecipePrAction } from "./actions.ts";
import { RecipeGitHub } from "./api.ts";
import { source } from "./fixture.ts";
import { RecipePath, Repository } from "./policy.ts";
import { rewriteSnapshot } from "./contents.ts";

const statePath = "recipe-native.json";
async function read() {
  return JSON.parse(await Deno.readTextFile(statePath));
}
async function save(state: unknown) {
  await Deno.writeTextFile(statePath, JSON.stringify(state));
}
const token = () => Deno.env.get("ORBIT_RECIPE_FIXTURE_SENTINEL")!;

export const fixtureHttp: Http = async (method, path, raw, key) => {
  const state = await read();
  const body = raw as Record<string, unknown> | undefined;
  state.calls.push({ method, path, body, key });
  if (state.maintenance) {
    let data: unknown = {};
    if (path.endsWith("/peer-dns/plan")) data = [];
    else if (path.endsWith("/recipe-pr/plan")) data = state.maintenance;
    else if (path.endsWith("/recipe/plan") || path.endsWith("/rollout/plan")) {
      data = { status: "disabled" };
    } else if (method === "POST" && path === "/api/v1/fleet/recipe-pr") {
      if (!key) throw new Error("Missing Gateway intent key");
      state.intentKeys ??= {};
      state.intentKeys[key] ??= Object.keys(state.intentKeys).length + 1;
      data = { id: state.intentKeys[key] };
    }
    await save(state);
    return { status: 200, body: { data } };
  }
  if (
    !state.bound ||
    (state.root &&
      !(method === "GET"
        ? path.endsWith(state.root)
        : body?.run_id === state.root))
  ) {
    await save(state);
    return { status: 409, body: { error: { code: "operation.run_mismatch" } } };
  }
  if (path.includes("/private-material?run_id=")) {
    state.mints++;
    await save(state);
    return {
      status: 200,
      body: {
        data: {
          github: {
            repository: Repository,
            permissions: {
              contents: state.mode === "wrong-grant" ? "read" : "write",
              pull_requests: "write",
              checks: "read",
            },
            token: token(),
            expires_at: new Date(Date.now() + 3600000).toISOString(),
          },
        },
      },
    };
  }
  if (method === "POST") {
    state.operation.status = body!.status;
    state.operation.result = body!.result ?? null;
  }
  await save(state);
  if (state.loseProjection && body?.status === "succeeded") {
    state.loseProjection = false;
    await save(state);
    throw new Error("Lost committed projection");
  }
  return { status: 200, body: { data: state.operation } };
};

const github: typeof fetch = async (raw, init) => {
  const url = new URL(String(raw));
  if (
    url.origin !== "https://api.github.com" ||
    new Headers(init?.headers).get("Authorization") !== "Bearer " + token()
  ) {
    throw new Error("fixture request invalid");
  }
  const state = await read(), method = init?.method ?? "GET";
  if (url.pathname === "/installation/token" && method === "DELETE") {
    state.revokes++;
    await save(state);
    return new Response(null, { status: 204 });
  }
  const path = url.pathname.replace("/repos/" + Repository, "");
  if (state.mode === "artifacts-lost-create") {
    state.github.push({ method, path });
    state.branches ??= {};
    state.pulls ??= {};
    let data: unknown, status = 200;
    const body = init?.body ? JSON.parse(String(init.body)) : undefined;
    if (method === "GET" && path === "/git/ref/heads/main") {
      data = { object: { sha: "a".repeat(40) } };
    } else if (method === "GET" && path === "/contents/" + RecipePath) {
      const branch = state.branches[url.searchParams.get("ref")!];
      data = {
        content: btoa(branch?.content ?? source()),
        encoding: "base64",
        sha: branch?.sha ?? "b".repeat(40),
      };
    } else if (method === "GET" && path === "/pulls") {
      const branch = url.searchParams.get("head")!.replace(
        "hardimpactdev:",
        "",
      );
      data = state.pulls[branch] ? [state.pulls[branch]] : [];
    } else if (method === "POST" && path === "/git/refs") {
      const branch = body.ref.replace("refs/heads/", "");
      if (state.branches[branch]) status = 422;
      else {
        state.branches[branch] = { content: source(), sha: "b".repeat(40) };
        state.writes++;
        status = 201;
      }
      data = {};
    } else if (method === "PUT" && path === "/contents/" + RecipePath) {
      if (body.sha !== state.branches[body.branch]?.sha) {
        throw new Error("Fixture branch changed");
      }
      state.branches[body.branch] = {
        content: atob(body.content),
        sha: "c".repeat(40),
      };
      state.writes++;
      data = {};
    } else if (method === "POST" && path === "/pulls") {
      if (state.pulls[body.head]) throw new Error("Duplicate fixture PR");
      state.pulls[body.head] = {
        number: 999,
        html_url: "https://github.com/hardimpactdev/orbit/pull/999",
        user: { login: state.operation.input.author, type: "Bot" },
        head: { ref: body.head, sha: "d".repeat(40) },
      };
      state.writes++;
      data = state.pulls[body.head];
      status = 201;
      if (!state.lostCreate) {
        state.lostCreate = true;
        await save(state);
        throw new Error("Committed PR create reply lost");
      }
    } else throw new Error("Unexpected artifact fixture request");
    await save(state);
    return Response.json(data, { status });
  }
  state.github.push({ method, path });
  if (method !== "GET") state.writes++;
  await save(state);
  if (method !== "GET" && state.mode === "uncertain-write") {
    throw new Error(token());
  }
  let data: unknown;
  if (path === "/git/ref/heads/main") {
    data = { object: { sha: "a".repeat(40) } };
  } else if (path === "/contents/" + RecipePath && method === "GET") {
    data = {
      content: btoa(
        state.mode === "pending-deployment"
          ? source().replace("8.0-1", "8.1-1")
          : source(),
      ),
      encoding: "base64",
      sha: "b".repeat(40),
    };
  } else if (path === "/pulls" && method === "GET") data = [];
  else if (path === "/pulls" && method === "POST") {
    data = { html_url: "https://github.com/hardimpactdev/orbit/pull/999" };
  } else if (method !== "GET") data = {};
  else throw new Error("Unexpected fixture request");
  return Response.json(data, { status: path === "/git/refs" ? 201 : 200 });
};
export function fixtureGateway(http: Http, args: DirectIntent) {
  return new GatewayOperation(http, args, undefined, undefined, github);
}
export function fixtureAction(secret: string, plans: OpenPlanStore) {
  return new RecipePrAction(new RecipeGitHub(secret, github), {
    snapshot: (content, next) =>
      Promise.resolve(rewriteSnapshot(content, next, {
        curl: "8.1-1",
      })),
    artifacts: async (content) => {
      const state = await read();
      if (state.mode !== "artifacts-lost-create") return content;
      state.candidateReads = (state.candidateReads ?? 0) + 1;
      await save(state);
      const recipe = JSON.parse(content);
      recipe.tools["php8.5-common"].version = `8.5.${
        12 + state.candidateReads
      }-1`;
      return JSON.stringify(recipe, null, 4) + "\n";
    },
  }, plans);
}

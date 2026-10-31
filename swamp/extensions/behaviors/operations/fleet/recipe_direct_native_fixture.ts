import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { footprintActions } from "../node/converge_footprint.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { RecipeAction } from "./recipe_direct.ts";
import { recipeChanges } from "./recipe_direct_fixture.ts";
const file = "native-recipe.json";
const endpoint = Deno.env.get("ORBIT_RECIPE_HTTP_FIXTURE");
const privateHttp = endpoint
  ? gatewayHttp(endpoint, "/etc/ssl/certs/ca-certificates.crt")
  : null;
export const fixtureNow = () =>
  new Date(JSON.parse(Deno.readTextFileSync(file)).now ?? Date.now());
export const fixtureHttp: Http = async (method, path, body) => {
  if (privateHttp && path.includes("/private-material")) {
    return privateHttp(method, path, body);
  }
  const state = JSON.parse(await Deno.readTextFile(file));
  state.calls.push({ method, path, body });
  if (method === "POST") {
    const projection = body as Record<string, unknown>;
    if (path.endsWith("/failure")) {
      if (
        !["succeeded", "failed", "cancelled"].includes(state.operation.status)
      ) {
        state.operation.status = "failed";
        state.operation.error_code = "operation.native_failed";
      }
    } else {
      state.operation.status = projection.status;
      state.operation.result = projection.result ?? null;
      state.operation.error_code = projection.error_code ?? null;
    }
    if (state.loseProjection && projection.status === "succeeded") {
      state.loseProjection = false;
      await Deno.writeTextFile(file, JSON.stringify(state));
      throw new Error("Lost result reply");
    }
  }
  await Deno.writeTextFile(file, JSON.stringify(state));
  return { status: 200, body: { data: state.operation } };
};
export const fixtureAction: RecipeAction = async (stage, input, gateway) => {
  const state = JSON.parse(await Deno.readTextFile(file));
  state.effects.push(stage);
  if (state.cancelAfter === stage) state.operation.cancel_requested = true;
  if (state.uncertain === stage) {
    state.uncertain = null;
    await Deno.writeTextFile(file, JSON.stringify(state));
    throw new Error("Uncertain command outcome");
  }
  await Deno.writeTextFile(file, JSON.stringify(state));
  if (privateHttp && stage === "agent") {
    return {
      stage,
      ok: true,
      footprint: await footprintActions({
        system: (call) => {
          const input = JSON.parse(call.stdin!);
          const expected = Deno.env.get("ORBIT_RECIPE_PRIVATE_HASH");
          if (
            input.secret_hash !== expected ||
            input.allow_secret_rotation !== false
          ) throw new Error("Unexpected private configuration input");
          if (Deno.env.get("ORBIT_RECIPE_PRIVATE_FAILURE")) {
            throw new Error(expected!);
          }
          return Promise.resolve({
            success: true,
            stdout: JSON.stringify({
              configured: true,
              restarted: false,
              unclosed: [],
              secret_hash: expected,
            }),
          });
        },
        action: () => Promise.reject(new Error("Unexpected footprint action")),
        installWorker: () =>
          Promise.resolve({ configured: false, changed: false }),
        account: () => Promise.resolve({ home: "/home/orbit", group: "orbit" }),
        install: () => Promise.reject(new Error("Unexpected install")),
        dns: () => Promise.reject(new Error("Unexpected DNS")),
        residue: () => Promise.reject(new Error("Unexpected residue")),
        network: () => Promise.reject(new Error("Unexpected network")),
      })("agent", input.footprint, gateway, false),
    };
  }
  if (state.refusal === stage) {
    return { stage, ok: false, error_code: "node.recipe_failed" };
  }
  if (stage === "recipe-apply") {
    return { stage, ok: true, changes: recipeChanges() };
  }
  if (stage === "delivery") {
    return {
      stage,
      ok: true,
      delivery: {
        identity: input.identity,
        agent_sha256: input.agent_sha256,
        cli_version: input.cli_version,
      },
    };
  }
  if (stage === "agent-install") {
    return {
      stage,
      ok: true,
      footprint: { stage, ok: true, binary_changed: false },
    };
  }
  const artifact = input.footprint.artifacts.find((a) => a.name === stage);
  if (artifact) {
    return {
      stage,
      ok: true,
      footprint: {
        stage: stage as "agent",
        ok: true,
        entry: { name: artifact.name, status: "unchanged" },
      },
    };
  }
  if (
    [
      "caddy-package",
      "caddy",
      "peer-dns",
      "private-dns",
      "route-residue",
      "php-runtime",
      "tmpfiles",
      "agent",
    ].includes(stage)
  ) {
    return {
      stage,
      ok: true,
      footprint: { stage: stage as "agent", ok: true },
    };
  }
  return { stage, ok: true };
};

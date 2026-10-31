import { z } from "npm:zod@4.4.3";
import { routeWorkloadLocal } from "./route-workload-local.ts";
import { Globals } from "../../operation-runner/mod.ts";
const ObjectInput = z.record(z.string(), z.unknown());
import type { inspectFirewall } from "../firewall/inspection.ts";
type FirewallSnapshot = Extract<
  Awaited<ReturnType<typeof inspectFirewall>>,
  { live: unknown }
>;
type FixturePlan = {
  cleanup: { node_id: number; scope: string }[];
  public_verifications: { node_id: number; verification: unknown }[];
};
type FixtureRoute = Record<string, unknown> & { node_ids: number[] };
type FixtureState = {
  upstream_live?: FirewallSnapshot["live"];
  upstream_backend?: FirewallSnapshot["backend"];
  events: string[];
  placements?: { name: string; node?: number }[];
  cancel_after?: string;
  fail_once?: string;
  fail?: string;
  analytics_rollback?: boolean;
  route_rollback?: boolean;
  protected_scope?: string;
  operation: Record<string, unknown> & {
    node_id: number;
    status: string;
    cancel_requested: boolean;
    input: {
      route: FixtureRoute;
      update?: {
        previous: FixtureRoute;
        node_ids: number[];
        analytics_changes?: { publish: FixturePlan; rollback: FixturePlan }[];
      };
      publication: Record<string, unknown>;
      upstream_firewall?: { node_id: number; expected: unknown[] };
      workloads: { node_id: number }[];
    };
  };
  private: Record<string, { input: { content?: string } }[]>;
};
async function withState<T>(
  use: (state: FixtureState) => T,
  save = true,
): Promise<T> {
  let lock: Deno.FsFile | undefined;
  for (let attempt = 0; attempt < 400 && !lock; attempt++) {
    try {
      lock = await Deno.open("native-convergence.lock", {
        createNew: true,
        write: true,
      });
    } catch (error) {
      if (!(error instanceof Deno.errors.AlreadyExists)) throw error;
      await new Promise((resolve) => setTimeout(resolve, 5));
    }
  }
  if (!lock) throw new Error("Fixture lock deadline");
  try {
    const state: FixtureState = JSON.parse(
      await Deno.readTextFile("native-convergence.json"),
    );
    const result = use(state);
    if (save) {
      await Deno.writeTextFile(
        "native-convergence.json",
        JSON.stringify(state),
      );
    }
    return result;
  } finally {
    lock.close();
    await Deno.remove("native-convergence.lock");
  }
}
export async function event(name: string, node?: number) {
  const failure = await withState((state) => {
    state.events.push(name);
    state.placements ??= [];
    state.placements.push({ name, node });
    if (state.cancel_after === name) state.operation.cancel_requested = true;
    const once = state.fail_once === name;
    if (once) delete state.fail_once;
    return once || state.fail === name;
  });
  if (failure) throw new Error("route.fixture_failed");
  return { ready: true };
}
export async function fixtureHttp(
  method: string,
  path: string,
  body?: Record<string, unknown>,
  args?: { target_node_id?: number; node_id?: number | null },
) {
  return await withState((state) => {
    if (method === "GET") {
      if (path.includes("/private-material")) {
        for (
          const writes of Object.values(state.private) as {
            input: { content?: string };
          }[][]
        ) {
          for (const write of writes) {
            if (write.input.content === "__secret__") {
              write.input.content = Deno.env.get("ROUTE_NATIVE_SECRET");
            }
          }
        }
        return { status: 200, body: { data: state.private } };
      }
      const node = args?.target_node_id ?? args?.node_id ??
        state.operation.node_id;
      const input = state.operation.input, update = input.update;
      const rollback = state.analytics_rollback || state.route_rollback;
      const plans = (update?.analytics_changes ?? []).map((change) =>
        change[rollback ? "rollback" : "publish"]
      );
      const route = state.route_rollback && update
        ? update.previous
        : input.route;
      return {
        status: 200,
        body: {
          data: {
            ...state.operation,
            input: {
              ...input,
              route: { ...route, node_ids: update?.node_ids ?? route.node_ids },
              publication: { ...input.publication, node_id: node },
              workloads: input.workloads.filter((
                workload: { node_id: number },
              ) => workload.node_id === node),
              upstream_firewall: input.upstream_firewall?.node_id === node
                ? input.upstream_firewall
                : null,
              analytics_plans: plans,
              cleanup: plans.flatMap((plan) => plan.cleanup).filter((
                leaf: { node_id: number; scope: string },
              ) =>
                leaf.node_id === node && leaf.scope !== state.protected_scope
              ),
              public_verification: plans.flatMap((plan) =>
                plan.public_verifications
              ).filter((probe: { node_id: number }) => probe.node_id === node)
                .map((probe: { verification: unknown }) => probe.verification),
            },
          },
        },
      };
    }
    const checkpoint = (body?.result as { checkpoint?: string } | undefined)
      ?.checkpoint;
    if (checkpoint) {
      state.events.push(checkpoint);
      if (state.cancel_after === checkpoint) {
        state.operation.cancel_requested = true;
      }
      if (checkpoint === "analytics-rollback") state.analytics_rollback = true;
      else if (checkpoint.startsWith("rollback-")) state.route_rollback = true;
    }
    state.operation.status = path.endsWith("/failure")
      ? "failed"
      : String(body?.status);
    if (["succeeded", "failed", "cancelled"].includes(String(body?.status))) {
      state.events.push("finished:" + body?.status);
    }
    return { status: 200, body: { data: state.operation } };
  }, method !== "GET");
}
export const fixtureNetwork = {
  lan: () => event("lan-published").then(() => ({ matches: true as const })),
  firewall: () =>
    event("firewall-published").then(() => ({ matches: true as const })),
  verify: (raw: unknown) =>
    event(
      (raw as { probe: { domain: string } }).probe.domain.startsWith("tracking")
        ? "analytics-verified"
        : "route-verified",
    ),
};
export const fixtureAnalytics = {
  hostPrepare: async (raw: unknown) => {
    const leaf = raw as { node_id: number; scope: string };
    await event("analytics-request", leaf.node_id);
    return {
      node_id: leaf.node_id,
      scope: leaf.scope,
      state: "current" as const,
    };
  },
  publish: (raw: unknown) =>
    event("analytics-certificate", (raw as { node_id: number }).node_id),
  remove: (raw: unknown) =>
    event("analytics-withdraw", (raw as { node_id: number }).node_id),
  hostWithdraw: (raw: unknown) =>
    event("analytics-withdraw", (raw as { node_id: number }).node_id),
};
export const fixtureGrace = () => event("dns-grace31").then(() => {});
export const fixtureFirewall: typeof inspectFirewall = async () => {
  await event("upstream-firewall-read");
  return await withState(
    (state) => ({
      backend: state.upstream_backend ?? "active",
      live: state.upstream_live ?? [],
      observed_at: new Date().toISOString(),
    }),
    false,
  );
};
export async function fixtureInvoke(call: { name: string }) {
  await event(call.name);
  if (call.name === "certificate.request-leaf") return { state: "current" };
  if (call.name === "certificate.inspect-leaf") return { present: true };
  if (call.name === "php.install-build") return { outcome: "current" };
  if (call.name === "php.converge-production-runtime") {
    return { published: true };
  }
  if (call.name.startsWith("firewall.")) return { matches: true };
  return {};
}
export function publisher() {
  return {
    type: "@nckrtl/route-publication",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: Object.fromEntries(
      ["publishDns", "publishCaddy", "publishPhp", "publishFirewall"].map(
        (method) => [method, {
          description: method,
          arguments: ObjectInput,
          execute: async (
            args: { target_node_id?: number; recovery?: boolean },
          ) => {
            await withState((state) => {
              if (state.operation.cancel_requested && !args.recovery) {
                throw new Error("operation.cancelled");
              }
            }, false);
            return await event(method, args.target_node_id);
          },
        }],
      ),
    ),
  };
}

export function fixtureWorkload() {
  return {
    ...routeWorkloadLocal(),
    grantSourceAccess: () => event("source-access"),
    prepareRuntime: async (raw: unknown) => {
      const input = raw as { production: boolean; runtime: unknown };
      if (input.production && input.runtime) {
        await event("runtime-packages");
        await event("runtime-profile");
        await event("runtime-published");
      }
      return { ready: true };
    },
    prepareWebRoots: () => event("served-web-roots"),
    synchronizeEnvironment: async (raw: unknown) => {
      for (const _write of raw as unknown[]) await event("environment-written");
      return { ready: true };
    },
  };
}

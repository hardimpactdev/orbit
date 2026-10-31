import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  nodeRemovalLocalActions,
  type RemovalActionDependencies,
} from "./removal_actions.ts";
import { removeNodeRoutes, restoreNodeRoutes } from "./removal_routes.ts";
import { caddyProgram } from "../routes/caddy-program.ts";
const endpoint = Deno.env.get("ORBIT_REMOVAL_HTTP_FIXTURE")!;
export const fixtureHttp = gatewayHttp(
  endpoint,
  "/etc/ssl/certs/ca-certificates.crt",
);
export const fixtureLocal = (deps: RemovalActionDependencies) =>
  nodeRemovalLocalActions({
    ...deps,
    read: async (s, p, signal) => {
      await deps.read(s, p, signal);
      return (await (await fetch(endpoint + "/_participant")).json()).data
        .input;
    },
    execute: async (_argv, stdin) => {
      if (
        JSON.parse(stdin!).name !== "wireguard.repair-peer-dns"
      ) throw new Error("Unexpected cleanup command");
      const reply = await (await fetch(endpoint + "/_agent")).json();
      return { success: true, stdout: JSON.stringify(reply) };
    },
    residue: async () => await (await fetch(endpoint + "/_residue")).json(),
    routes: (raw, _invoke, signal, dependencies) =>
      removeNodeRoutes(
        raw,
        (argv) => {
          if (argv[3] !== caddyProgram) {
            throw new Error("Unexpected Route command");
          }
          return {
            success: true,
            stdout: JSON.stringify({ result: "published" }),
          };
        },
        signal,
        {
          ...dependencies,
          workload: {
            prepareRuntime: async () => {
              await fetch(endpoint + "/_runtime");
              return { ready: true };
            },
            synchronizeEnvironment: async (writes) => {
              const value = writes as {
                instance_id: number;
                input: { private: string };
              }[];
              if (
                value.length !== 1 || value[0].instance_id !== 51 ||
                value[0].input.private !==
                  Deno.env.get("ORBIT_REMOVAL_PRIVATE_FIXTURE")
              ) throw new Error("Unexpected private Route material");
              if (
                Deno.env.get("ORBIT_REMOVAL_PRIVATE_FAILURE") === "1"
              ) throw new Error(value[0].input.private);
              await fetch(endpoint + "/_url");
              return { ready: true };
            },
          },
        },
      ),
    restoreRoutes: (raw, _invoke, signal, dependencies) =>
      restoreNodeRoutes(
        raw,
        (argv) => {
          if (argv[3] !== caddyProgram) {
            throw new Error("Unexpected Route command");
          }
          return {
            success: true,
            stdout: JSON.stringify({ result: "published" }),
          };
        },
        signal,
        {
          ...dependencies,
          workload: {
            prepareRuntime: async () => {
              await fetch(endpoint + "/_runtime");
              return { ready: true };
            },
            synchronizeEnvironment: async (writes) => {
              const value = writes as {
                instance_id: number;
                input: { private: string };
              }[];
              if (
                value.length !== 1 || value[0].instance_id !== 51 ||
                value[0].input.private !==
                  Deno.env.get("ORBIT_REMOVAL_PRIVATE_FIXTURE")
              ) throw new Error("Unexpected private Route material");
              if (
                Deno.env.get("ORBIT_REMOVAL_PRIVATE_FAILURE") === "1"
              ) throw new Error(value[0].input.private);
              await fetch(endpoint + "/_url");
              return { ready: true };
            },
          },
        },
      ),
  });
export const fixtureRetire = async (
  input: { target: { node_id: number } },
  identity: string,
) => {
  if (identity !== Deno.env.get("ORBIT_REMOVAL_PRIVATE_FIXTURE")) {
    throw new Error("Unexpected protected identity");
  }
  const response = await fetch(
    endpoint + "/_retire?node=" + input.target.node_id,
  );
  if (!response.ok) throw new Error("Synthetic retirement refused");
  return { ready: true as const };
};

export const fixtureMetrics = async (
  phase: string,
  node: { node_id: number },
  _metrics: string,
  before?: unknown,
) => {
  await fetch(endpoint + "/_metrics?phase=" + phase + "&node=" + node.node_id);
  if (phase === "restore" && !before) throw new Error("Snapshot missing");
  return phase === "snapshot"
    ? { node_exporter: {}, cadvisor: {}, service: { config: null }, pools: [] }
    : {};
};
export const fixtureMetricsRuntime = {
  credential: async () => {
    await fetch(endpoint + "/_metrics?phase=credentials");
    return { verified: true };
  },
  runtime: async () => {
    await fetch(endpoint + "/_metrics?phase=runtime");
    return { changed: true };
  },
};
export const fixtureCertificates = {
  request: async () => {
    await fetch(endpoint + "/_cert?phase=request");
    return { state: "current" };
  },
  publish: async () => {
    await fetch(endpoint + "/_cert?phase=publish");
    return { applied: true };
  },
  inspect: () => Promise.resolve({ present: true }),
};

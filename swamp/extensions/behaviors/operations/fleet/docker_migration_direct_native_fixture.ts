// deno-lint-ignore-file require-await
import type { Http } from "../../automatic-deploy/gateway.ts";
import { migrateDocker } from "./docker_migration_direct.ts";
const path = "docker-native.json";
function state() {
  return JSON.parse(Deno.readTextFileSync(path));
}
function save(value: unknown) {
  Deno.writeTextFileSync(path, JSON.stringify(value));
}
export const fixtureHttp: Http = async (method, path, body) => {
  const s = state();
  s.calls.push({ method, path });
  if (path.split("?")[0].endsWith("/private-material")) {
    save(s);
    return {
      status: 200,
      body: {
        data: {
          processes: [{
            process_id: 7,
            container: "orbit-process-7-web",
            desired: "running",
            spec: "a".repeat(64),
            environment: { SECRET: Deno.env.get("ORBIT_DOCKER_SENTINEL") },
          }],
        },
      },
    };
  }
  if (path.endsWith("/failure")) {
    if (!["succeeded", "failed"].includes(s.operation.status)) {
      s.operation.status = "failed";
    }
    save(s);
    return { status: 200, body: { data: s.operation } };
  }
  if (method === "POST") {
    Object.assign(s.operation, body);
    save(s);
    return { status: 200, body: { data: s.operation } };
  }
  save(s);
  return { status: 200, body: { data: s.operation } };
};
export const fixtureAction: typeof migrateDocker = async (
  input,
  gateway,
  signal,
) =>
  await migrateDocker(input, gateway, signal, {
    migrate: async () => {
      const s = state();
      s.effects.push("prepare");
      save(s);
      if (s.mode === "refused") {
        return {
          ok: false,
          error_code: "docker.migration_failed",
          stage: "fallback-required",
          resumable: true,
          healthy: false,
          fallback: false,
          containers: [],
          process_ids: [],
        };
      }
      return { ok: true, fallback: false, containers: [], process_ids: ["7"] };
    },
    reconcile: async () => {
      const s = state();
      s.effects.push("reconcile");
      save(s);
      if (s.mode === "uncertain") throw new Error("Unknown subprocess result");
      return {};
    },
    observe: async (mode) => {
      const s = state();
      s.effects.push(mode);
      save(s);
      return {
        healthy: true,
        fallback: false,
        containers: [{
          name: "orbit-process-7-web",
          state: "running",
          health: "healthy",
        }],
        process_ids: ["7"],
      };
    },
  });

import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { initializePlausible } from "./initialization.ts";
import { processSystem } from "../processes-schedules/process-system.ts";
const path = "update-native.json";
const read = () => JSON.parse(Deno.readTextFileSync(path));
const save = (state: unknown) =>
  Deno.writeTextFileSync(path, JSON.stringify(state));
const endpoint = read();
export const http = gatewayHttp(endpoint.gatewayUrl, endpoint.caFile);
export async function invoke(
  name: Parameters<ReturnType<typeof processSystem>>[0],
  input: Record<string, unknown>,
  signal?: AbortSignal,
  beforeReplacement?: Parameters<ReturnType<typeof processSystem>>[3],
) {
  const state = read();
  state.calls.push({ name, image: input.image ?? null });
  save(state);
  const environment = input.environment as Record<string, string>;
  if (state.throwPrivate) throw new Error(String(environment.SECRET_KEY_BASE));
  if (state.privatePayload) return { ignored: environment.SECRET_KEY_BASE };
  if (state.devDocker) {
    const desired = structuredClone(input);
    if (state.refuseHealth && input.health) {
      desired.health = {
        url: "http://127.0.0.1:1/",
        attempts: 1,
        interval_seconds: 0,
      };
    }
    return await processSystem()(name, desired, signal, beforeReplacement);
  }
  if (state.refuseHealth && input.health) {
    return { error_code: "process.health_failed" };
  }
  return await beforeReplacement?.() ?? {};
}

export const initialize: typeof initializePlausible = (
  desired,
  environment,
  action,
  signal,
) => {
  const state = read();
  if (state.devDocker && !state.throwPrivate && !state.privatePayload) {
    return initializePlausible(desired, environment, action, signal);
  }
  return Promise.resolve({ ready: true });
};

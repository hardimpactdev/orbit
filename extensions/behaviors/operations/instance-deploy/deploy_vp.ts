import { z } from "npm:zod@4.4.3";
// @deno-types="npm:@types/semver@7.7.1"
import { gte, valid } from "npm:semver@7.7.2";
import { localTool } from "../tools/local.ts";
import { ToolIntentInput } from "../tools/direct-contract.ts";
import { type VpLocal, vpLocal } from "./vp_local.ts";
import { vpRequirements, VpRuntime } from "./vp_requirements.ts";

const Input = z.object({
  node_id: z.number().int().positive(),
  platform: z.enum(["linux", "macos"]),
  architecture: z.enum(["amd64", "arm64"]),
  path: z.string().startsWith("/").max(4096),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/),
  node_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/),
  materialize: z.record(z.string(), z.unknown()),
}).strict();
type Input = z.infer<typeof Input>;
type Port = {
  local?: VpLocal;
  materialize?: typeof localTool;
  mutation?: <T>(action: () => Promise<T>) => Promise<T>;
  current: () => Promise<{ cancel_requested: boolean }>;
  reference: (
    runtime: VpRuntime,
    status: "checking" | "active" | "failed",
  ) => Promise<void>;
  now?: () => number;
};
type Outcome = { runtime: VpRuntime | null } | { error_code: string };
class Refused extends Error {}

/** Native local prerequisite; Project data and registry settings never become method output. */
export async function prepareDeployVp(
  raw: Input,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
): Promise<Outcome> {
  const input = Input.parse(raw),
    local = port.local ?? vpLocal,
    now = port.now ?? Date.now;
  let environment: VpRuntime | null = null;
  async function call(
    mode: Parameters<VpLocal>[0],
    data: Record<string, unknown>,
    account: string,
    mutates = false,
  ) {
    if ((await port.current()).cancel_requested || signal?.aborted) {
      throw new Refused("operation.cancelled");
    }
    const remaining = Math.floor(deadline - now());
    if (!Number.isFinite(remaining) || remaining <= 0) {
      throw new Refused("deployment.deadline_exceeded");
    }
    let result: Record<string, unknown>;
    try {
      const action = () =>
        local(mode, data, {
          user: account,
          platform: input.platform,
          architecture: input.architecture,
          deadline,
          signal,
        });
      result = mutates && port.mutation
        ? await port.mutation(action)
        : await action();
    } catch {
      throw new Refused(
        mutates
          ? "deployment.execution_unconfirmed"
          : "deployment.vp_store_failed",
      );
    }
    if (typeof result.error_code === "string") {
      throw new Refused(result.error_code);
    }
    return result;
  }
  try {
    const configuration = await call("project", {
      path: input.path,
      user: input.user,
    }, input.user);
    environment = vpRequirements(configuration, "/unresolved");
    if (environment === null) return { runtime: null };
    if (input.materialize.user !== input.node_user) {
      throw new Refused("deployment.identity_changed");
    }
    if ((await port.current()).cancel_requested || signal?.aborted) {
      throw new Refused("operation.cancelled");
    }
    if (deadline <= now()) throw new Refused("deployment.deadline_exceeded");
    const manager = () =>
      (port.materialize ?? localTool)(
        "tool.materialize-vp",
        input.materialize,
        ToolIntentInput.parse({
          node_id: input.node_id,
          manager: "vp",
          user: input.node_user,
          platform: input.platform,
          architecture: input.architecture,
        }),
        signal,
      );
    let materialized: Record<string, unknown>;
    try {
      materialized = port.mutation
        ? await port.mutation(manager)
        : await manager();
    } catch {
      throw new Refused("deployment.execution_unconfirmed");
    }
    if (typeof materialized.error_code === "string") {
      throw new Refused(materialized.error_code);
    }
    const scope = await call("scope", {}, input.node_user);
    const binary = z.string().startsWith("/").endsWith("/bin/vp").parse(
      scope.binary,
    );
    const store = binary.slice(0, -"/bin/vp".length);
    if (
      !store || store.split("/").some((part, index) =>
        index > 0 && ["", ".", ".."].includes(part)
      ) || store.includes("\0")
    ) {
      throw new Refused("deployment.vp_store_failed");
    }
    environment = VpRuntime.parse({ ...environment, store });
    const version = await call("version", {}, input.node_user);
    const observed = typeof version.version === "string"
      ? valid(version.version.trim().replace(/^vp\s+/, ""))
      : null;
    if (observed === null || !gte(observed, environment.vp_version)) {
      throw new Refused("deployment.vp_store_failed");
    }
    await port.reference(environment, "checking");
    const { vp_version: _version, ...runtime } = environment;
    const inspected = await call("inspect", {
      environments: [runtime],
    }, input.node_user);
    const installed =
      z.object({ installed: z.tuple([z.boolean()]) }).parse(inspected)
        .installed[0];
    if (!installed) {
      const npmrc = z.string().max(1048576).parse(configuration.npmrc);
      await call(
        "install",
        { environment: runtime, npmrc },
        input.node_user,
        true,
      );
      const verify = await call("inspect", {
        environments: [runtime],
      }, input.node_user);
      if (
        !z.object({ installed: z.tuple([z.boolean()]) }).parse(verify)
          .installed[0]
      ) {
        throw new Refused("deployment.vp_store_failed");
      }
    }
    await port.reference(environment, "active");
    return { runtime: environment };
  } catch (error) {
    if (
      !(error instanceof Refused &&
        error.message === "deployment.execution_unconfirmed") &&
      environment !== null && environment.store !== "/unresolved"
    ) {
      try {
        await port.reference(environment, "failed");
      } catch { /* Keep the existing checking reference on projection loss. */ }
    }
    const code = error instanceof Refused
      ? error.message
      : error instanceof Error &&
          error.message === "deployment.vp_requirements_invalid"
      ? error.message
      : "deployment.vp_store_failed";
    return {
      error_code: /^[a-z0-9_.-]{1,100}$/.test(code)
        ? code
        : "deployment.vp_store_failed",
    };
  }
}

import { z } from "npm:zod@4.4.3";
// @deno-types="npm:@types/semver@7.7.1"
import { gte, valid } from "npm:semver@7.7.2";
import { runNodeAgent } from "../_lib/node-agent.ts";
import { vpRequirements, VpRuntime } from "./vp_requirements.ts";

const Input = z.object({
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  path: z.string().startsWith("/").max(4096),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/),
  node_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/),
  materialize: z.record(z.string(), z.unknown()),
}).strict();
type Input = z.infer<typeof Input>;
type Port = {
  invoke?: typeof runNodeAgent;
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
    invoke = port.invoke ?? runNodeAgent,
    now = port.now ?? Date.now;
  let environment: VpRuntime | null = null;
  async function call(
    name: string,
    data: Record<string, unknown>,
    sudo: boolean,
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
      result = await invoke({
        name,
        input: data,
        pin: input.agent_sha256,
        sudo,
        mutates,
        deadlineMs: Math.min(remaining, 900000),
      }, signal);
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
    const configuration = await call("tool.read-vp-project", {
      path: input.path,
      user: input.user,
    }, true);
    environment = vpRequirements(configuration, "/unresolved");
    if (environment === null) return { runtime: null };
    if (input.materialize.user !== input.node_user) {
      throw new Refused("deployment.identity_changed");
    }
    await call("tool.materialize-vp", input.materialize, true, true);
    const scope = await call("tool.inspect-vp-scope", {}, false);
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
    const version = await call("tool.vp-version", {}, false);
    const observed = typeof version.version === "string"
      ? valid(version.version.trim().replace(/^vp\s+/, ""))
      : null;
    if (observed === null || !gte(observed, environment.vp_version)) {
      throw new Refused("deployment.vp_store_failed");
    }
    await port.reference(environment, "checking");
    const { vp_version: _version, ...runtime } = environment;
    const inspected = await call("tool.inspect-vp-store", {
      environments: [runtime],
    }, false);
    const installed =
      z.object({ installed: z.tuple([z.boolean()]) }).parse(inspected)
        .installed[0];
    if (!installed) {
      const npmrc = z.string().max(1048576).parse(configuration.npmrc);
      await call(
        "tool.install-vp-environment",
        { environment: runtime, npmrc },
        true,
        true,
      );
      const verify = await call("tool.inspect-vp-store", {
        environments: [runtime],
      }, false);
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

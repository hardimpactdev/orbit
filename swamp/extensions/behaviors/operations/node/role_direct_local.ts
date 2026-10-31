import { z } from "npm:zod@4.4.3";
import { RoleSnapshot } from "./role_direct_contract.ts";
import {
  type BaselineOutcome,
  prepareHibernationDirectories,
  prepareStorageRoot,
  readRoleAccount,
} from "./role_baseline_system.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";
import { installRecipeJavascript } from "../fleet/recipe_local.ts";
import { installRolePrerequisites } from "../routes/router-preparation.ts";
import { convergeRouterLan } from "../routes/router-lan.ts";
import { convergeFirewall } from "../routes/firewall-local.ts";
import { runLocalProgram } from "../routes/local-program.ts";
import { convergeCiHost } from "./ci_host_system.ts";

export const BaselineStage = z.enum([
  "storage",
  "prerequisites",
  "javascript",
  "hibernation",
  "manager-vp",
  "manager-composer",
  "runtime",
  "gateway-dns",
  "ci-host",
  "firewall",
  "router-lan",
]);
const Outcome = z.union([
  z.object({ ok: z.literal(true) }).strict(),
  z.object({
    ok: z.literal(false),
    error_code: z.string().regex(/^[a-z][a-z0-9_-]*\.[a-z0-9_.-]{1,90}$/),
  }).strict(),
]);
export type BaselineIntegrations = {
  program?: typeof runLocalProgram;
  javascript?: (
    input: Record<string, unknown>,
    signal?: AbortSignal,
  ) => Promise<BaselineOutcome>;
  ciHost?: (signal?: AbortSignal) => Promise<BaselineOutcome>;
};

function systemOutcome(raw: unknown): BaselineOutcome {
  const result = z.union([
    z.object({}).strict(),
    z.object({ matches: z.literal(true) }).strict(),
    z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
  ]).parse(raw);
  return "error_code" in result
    ? { ok: false, error_code: result.error_code }
    : { ok: true };
}

/** Each placed stage runs system commands; shared family actions are injected by the root. */
export function roleBaseline(
  transport: SystemTransport = localSystemCommand,
  integrations: BaselineIntegrations = {},
) {
  return async (
    raw: unknown,
    phase: z.infer<typeof BaselineStage>,
    signal?: AbortSignal,
  ): Promise<BaselineOutcome> => {
    const snapshot = RoleSnapshot.parse(raw);
    const stage = BaselineStage.parse(phase);
    signal?.throwIfAborted();
    if (stage === "storage") {
      if (snapshot.role !== "app-dev") return { ok: true };
      const account = await readRoleAccount(snapshot.user, transport, signal);
      if (account === null) {
        return { ok: false, error_code: "node.storage_root_unusable" };
      }
      return await prepareStorageRoot(
        {
          root: snapshot.storage_root ?? `${account.home}/apps`,
          user: account.user,
          group: account.group,
          create_missing: true,
        },
        transport,
        signal,
      );
    }
    if (stage === "prerequisites") {
      const account = await readRoleAccount(snapshot.user, transport, signal);
      if (account === null) {
        return { ok: false, error_code: "role.prerequisite_failed" };
      }
      return Outcome.parse(
        await installRolePrerequisites(
          { ...snapshot.prerequisites, ...account },
          integrations.program,
          signal,
        ),
      );
    }
    if (stage === "hibernation") {
      return snapshot.role !== "app-dev"
        ? { ok: true }
        : await prepareHibernationDirectories(
          {
            marker_directory: "/dev/shm/orbit/hibernation",
            log_directory: "/data/caddy/orbit/hibernation",
          },
          transport,
          signal,
        );
    }
    if (stage === "runtime" || stage.startsWith("manager-")) {
      throw new Error(
        "Runtime and managers require the root's private/context boundary",
      );
    }
    if (stage === "javascript") {
      if (snapshot.javascript == null) return { ok: true };
      if (integrations.javascript !== undefined) {
        return Outcome.parse(
          await integrations.javascript(snapshot.javascript, signal),
        );
      }
      const result = await installRecipeJavascript(snapshot.javascript, {
        signal,
        run: async (call, currentSignal) => {
          const output = await transport({
            command: "/usr/bin/sudo",
            args: ["-n", "--", "/usr/bin/python3", "-c", call.program],
            stdin: JSON.stringify(call.input),
            timeoutMs: call.timeoutMs,
          }, currentSignal);
          if (!output.success) {
            throw new Error("JavaScript installation has no confirmed outcome");
          }
          try {
            return JSON.parse(output.stdout);
          } catch {
            throw new Error("JavaScript installation returned no typed result");
          }
        },
      });
      return "error_code" in result
        ? { ok: false, error_code: result.error_code }
        : { ok: true };
    }
    if (stage === "ci-host") {
      if (snapshot.role !== "ci-runner") return { ok: true };
      if (integrations.ciHost !== undefined) {
        return Outcome.parse(await integrations.ciHost(signal));
      }
      const host = await convergeCiHost("converge", transport, signal);
      if ("error_code" in host) {
        return { ok: false, error_code: host.error_code };
      }
      if (host.outcome !== "converged") {
        throw new Error("The CI host outcome is uncertain");
      }
      return { ok: true };
    }
    if (stage === "router-lan") {
      if (snapshot.router_lan == null) return { ok: true };
      return systemOutcome(
        await convergeRouterLan(
          snapshot.router_lan,
          integrations.program,
          signal,
        ),
      );
    }
    if (snapshot.firewall === null) return { ok: true };
    return systemOutcome(
      await convergeFirewall(snapshot.firewall, integrations.program, signal),
    );
  };
}
export const convergeRoleBaseline = roleBaseline();

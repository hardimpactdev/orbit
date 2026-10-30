import { z } from "npm:zod@4.4.3";
import { Intent, Result, ToolIntentInput } from "./direct-contract.ts";
import { executeToolPolicy } from "./policy.ts";
import { localTool } from "./local.ts";
import {
  allows,
  refusalCode,
  type Result as ToolResult,
  safeVersion,
  ToolRefusal,
} from "./contract.ts";

type NodeInput = z.infer<typeof ToolIntentInput>;
type Context = {
  signal?: AbortSignal;
  writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
  readResource(name: string): Promise<unknown>;
};
const Before = z.object({
  operation_id: z.number(),
  run_id: z.string(),
  installed_version: z.string().nullable(),
});

function command(
  step: string,
  input: NodeInput,
  kind: string,
): { name: string; input: Record<string, unknown> } {
  const manager = input.manager, verb = kind.slice(5);
  const prefix = manager === "brew"
    ? "tool.homebrew-formula-"
    : manager === "brew-cask"
    ? "tool.homebrew-cask-"
    : `tool.${manager}-`;
  const version = manager.startsWith("brew")
    ? "tool.homebrew-version"
    : `${prefix}version`;
  const pkg = { package: input.package };
  const bottle = {
    ...pkg,
    ...(input.platform === "macos" && manager.startsWith("brew")
      ? { architecture: input.architecture }
      : {}),
  };
  switch (step) {
    case "manager-materialize": {
      const name = {
        vp: "tool.materialize-vp",
        composer: "tool.materialize-composer",
        brew: "tool.materialize-homebrew",
      }[manager as "vp" | "composer" | "brew"];
      if (!name || !input.materialize || input.platform !== "linux") {
        throw new Error("Invalid Tool manager materialization");
      }
      return { name, input: input.materialize };
    }
    case "manager-ready":
      return { name: version, input: {} };
    case "package-before":
      return { name: `${prefix}inventory`, input: {} };
    case "package-candidate":
      return {
        name: `${prefix}candidate`,
        input: manager === "composer" ? { ...pkg, operation: verb } : bottle,
      };
    case "package-removal-plan":
      return { name: `${prefix}removal-plan`, input: pkg };
    case "package-mutate":
      return {
        name: `${prefix}${verb}`,
        input: manager === "brew" && verb === "remove" ? pkg : bottle,
      };
    case "adoption-scope":
      return { name: "tool.inspect-composer-scope", input: {} };
    case "tool-verified":
      if (verb === "manager_converge" || input.intent_only) {
        return { name: version, input: {} };
      }
      if (verb === "adopt" && manager.startsWith("brew")) {
        return { name: `${prefix}adoption`, input: bottle };
      }
      return { name: `${prefix}inventory`, input: {} };
  }
  throw new Error("Unknown Tool policy step");
}

/** Select one version; incomplete inventory can never prove package absence. */
export function inventoryVersion(
  input: NodeInput,
  raw: ToolResult,
): string | null {
  const brew = input.manager.startsWith("brew");
  const packages = brew
    ? z.array(
      z.object({
        name: z.string(),
        versions: z.array(z.string()),
        unverifiable: z.boolean(),
      }),
    ).parse(raw.packages)
    : z.array(z.object({ name: z.string(), version: z.string().nullable() }))
      .max(4096).parse(raw.packages);
  if (input.manager === "vp" && typeof raw.scan_complete !== "boolean") {
    throw new ToolRefusal("tool.version_probe_failed");
  }
  const selected = packages.filter((p) => p.name === input.package);
  const versions: string[] = [];
  for (const p of selected) {
    if ("unverifiable" in p) {
      if (p.unverifiable) throw new ToolRefusal("tool.version_probe_failed");
      versions.push(...p.versions);
    } else {
      if (p.version === null) {
        throw new ToolRefusal("tool.version_probe_failed");
      }
      versions.push(p.version);
    }
  }
  if (
    versions.length > 1 ||
    (versions.length === 0 && input.manager === "vp" &&
      raw.scan_complete !== true)
  ) throw new ToolRefusal("tool.version_probe_failed");
  const version = versions[0] ?? null;
  if (version !== null && !safeVersion(version)) {
    throw new ToolRefusal("tool.version_probe_failed");
  }
  return version;
}

export function toolNode(invoke = localTool) {
  return {
    type: "@nckrtl/tool-node",
    version: "2026.10.30.1",
    resources: {
      before: {
        description: "Before-mutation inventory for native reconciliation",
        schema: Before,
        lifetime: "workflow",
        garbageCollection: 10,
      },
      outcome: {
        description: "Bounded native Tool result",
        schema: z.object({ outcome: Result }),
        lifetime: "workflow",
        garbageCollection: 10,
      },
    },
    methods: {
      apply: {
        description: "Execute Tool policy on the admitted Node and account",
        arguments: z.object({ intent: Intent }),
        execute: async (
          { intent }: { intent: z.infer<typeof Intent> },
          ctx: Context,
        ) => {
          const persist = async (outcome: z.infer<typeof Result>) => ({
            outcome,
            dataHandles: [
              await ctx.writeResource("outcome", `outcome-${intent.run_id}`, {
                outcome,
              }),
            ],
          });
          const input = intent.input;
          if (input.node_id !== intent.node_id) {
            throw new Error("Tool input names another Node");
          }
          const beforeName = `before-${intent.run_id}`;
          const saved = await ctx.readResource(beforeName);
          let baseline = saved ? Before.parse(saved) : null;
          if (
            baseline &&
            (baseline.operation_id !== intent.operation_id ||
              baseline.run_id !== intent.run_id)
          ) throw new Error("Another native run owns the Tool observation");
          let managerVersion: string | null = null;
          let blocked = false;
          const probeInventory = async (): Promise<string | null> => {
            const spec = command("package-before", input, intent.kind);
            const raw = await invoke(spec.name, {}, input, ctx.signal);
            if (typeof raw.error_code === "string") {
              throw new ToolRefusal(
                refusalCode("package-before", raw.error_code, intent.kind),
                raw,
              );
            }
            try {
              return inventoryVersion(input, raw);
            } catch (error) {
              if (error instanceof ToolRefusal) throw error;
              throw new ToolRefusal("tool.version_probe_failed");
            }
          };
          const run = async (step: string): Promise<ToolResult> => {
            if (ctx.signal?.aborted) {
              throw new Error("Native Tool action cancelled");
            }
            const spec = command(step, input, intent.kind);
            if (step === "package-before" && baseline) {
              if (
                intent.kind === "tool.remove" && await probeInventory() === null
              ) return { installed_version: null, block: null };
              return {
                installed_version: baseline.installed_version,
                block: null,
              };
            }
            if (step === "package-mutate" && baseline) {
              const observed = await probeInventory();
              // Native resume observes desired state before repeating an uncertain mutation.
              if (intent.kind === "tool.remove" && observed === null) return {};
              if (
                intent.kind === "tool.install" &&
                baseline.installed_version === null && observed !== null &&
                allows(input, observed)
              ) return {};
              if (
                intent.kind === "tool.update" && observed !== null &&
                observed !== baseline.installed_version &&
                allows(input, observed)
              ) return {};
            }
            const raw = await invoke(spec.name, spec.input, input, ctx.signal);
            if (typeof raw.error_code === "string") {
              throw new ToolRefusal(
                refusalCode(step, raw.error_code, intent.kind),
                raw,
              );
            }
            if (step === "manager-ready") {
              if (!safeVersion(raw.version)) {
                throw new ToolRefusal("tool.version_probe_failed");
              }
              managerVersion = raw.version;
            }
            if (
              step === "package-candidate" && safeVersion(raw.version) &&
              !allows(input, raw.version)
            ) blocked = true;
            if (spec.name.endsWith("-inventory")) {
              let version: string | null;
              try {
                version = inventoryVersion(input, raw);
              } catch (error) {
                if (error instanceof ToolRefusal) throw error;
                throw new ToolRefusal("tool.version_probe_failed");
              }
              if (step === "package-before") {
                baseline = {
                  operation_id: intent.operation_id,
                  run_id: intent.run_id,
                  installed_version: version,
                };
                await ctx.writeResource("before", beforeName, baseline);
              }
              const outcome = intent.kind === "tool.adopt"
                ? (input.tool_status === "installed" ? "unchanged" : "applied")
                : intent.kind === "tool.remove"
                ? "applied"
                : intent.kind === "tool.install"
                ? (baseline?.installed_version !== null
                  ? "unchanged"
                  : "applied")
                : blocked
                ? "blocked_by_constraint"
                : baseline?.installed_version === version
                ? "unchanged"
                : "applied";
              return { installed_version: version, block: null, outcome };
            }
            if (spec.name.endsWith("-adoption")) {
              return {
                ...raw,
                outcome: input.tool_status === "installed"
                  ? "unchanged"
                  : "applied",
              };
            }
            return raw;
          };
          try {
            const final = await executeToolPolicy(
              input,
              intent.kind,
              run,
              input.materialize !== null,
            );
            return await persist({
              status: "succeeded",
              result: Result.shape.result.parse({
                ...final,
                manager_version: managerVersion,
              }),
              error_code: null,
            });
          } catch (error) {
            if (!(error instanceof ToolRefusal)) throw error;
            return await persist({
              status: error.code === "operation.cancelled"
                ? "cancelled"
                : "failed",
              result: Result.shape.result.parse({
                ...error.result,
                manager_version: managerVersion,
                native_error_code: error.result.error_code ?? error.code,
                error_code: error.code,
              }),
              error_code: error.code,
            });
          }
        },
      },
    },
  };
}

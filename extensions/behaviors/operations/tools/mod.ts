import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import {
  allows,
  commands,
  Completion,
  Input,
  normalizeVersion,
  packageInput,
  refusalCode,
  type Result,
  safeVersion,
  ToolRefusal,
  verifyInstalled,
} from "./contract.ts";

export function tools(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-tools",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      receipt: {
        description: "Permanent completion intent for one Tool Operation",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      effect: {
        description: "Exact Tool step intent and outcome",
        schema: z.record(z.string(), z.unknown()),
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description:
          "Execute an admitted Tool package or manager operation using fixed named commands",
        arguments: z.object({
          operationId: z.number().int().positive(),
          worker: z.string(),
        }),
        execute: async (
          args: { operationId: number; worker: string },
          ctx: Context,
        ) => {
          const executor = new Executor(makeHttp(ctx));
          const path = String(args.operationId), resource = `operation-${path}`;
          const finish = async (completion: z.infer<typeof Completion>) => {
            const handle = await ctx.writeResource(
              "receipt",
              resource,
              completion,
            );
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return { dataHandles: [handle], status: completion.status };
          };
          const saved = await ctx.readResource(resource);
          if (saved) {
            const completion = Completion.parse(saved);
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return { status: completion.status };
          }
          const operation = Operation.parse(
            await executor.post(`${path}/heartbeat`, { worker: args.worker }),
          );
          if (
            ![
              "tool.install",
              "tool.update",
              "tool.remove",
              "tool.adopt",
              "tool.manager_converge",
            ].includes(operation.kind)
          ) throw new Error("Wrong tool kind");
          const input = Input.parse(operation.input),
            command = commands[input.manager];
          if (input.node_id !== operation.node_id) {
            throw new Error("Wrong Tool Node");
          }
          const verb = operation.kind.slice(5);
          let heartbeatError: unknown, activeStep: string | null = null;
          const timer = setInterval(() => {
            executor.post(`${path}/heartbeat`, { worker: args.worker }).catch(
              (error) => {
                heartbeatError = error;
              },
            );
          }, 30_000);
          const checkLease = () => {
            if (heartbeatError) throw heartbeatError;
          };
          const run = async (
            step: string,
            name: string,
            body: Result,
          ): Promise<Result> => {
            checkLease();
            const recorded = operation.step_results[step];
            if (recorded) {
              return z.record(z.string(), z.unknown()).parse(recorded);
            }
            const intent = {
              worker: args.worker,
              key: step,
              transport: "agent",
              name,
              input: body,
            };
            // Keep the exact input before invoking the Gateway. Its run key is the effect boundary.
            const intentResource = `${resource}-${step}`;
            const stored = await ctx.readResource(intentResource);
            if (stored && typeof stored.error_code === "string") {
              activeStep = step;
              throw new ToolRefusal(
                stored.error_code,
                stored.result as Result ?? {},
              );
            }
            await ctx.writeResource("effect", intentResource, { intent });
            activeStep = step;
            await executor.post(`${path}/step`, {
              worker: args.worker,
              key: `${step}:start`,
              name: step,
              status: "running",
              output: `${name}\n`,
            });
            const result = z.record(z.string(), z.unknown()).parse(
              await executor.post(`${path}/run`, intent),
            );
            checkLease();
            const error = typeof result.error_code === "string"
              ? refusalCode(step, result.error_code, operation.kind)
              : null;
            if (error && !/^[a-z0-9_.-]+$/.test(error)) {
              throw new Error("Invalid Tool refusal code");
            }
            // Save refusals before finishing a failed step, so a restart can complete it.
            if (error) {
              await ctx.writeResource("effect", intentResource, {
                intent,
                error_code: error,
                result,
              });
            }
            if (error) throw new ToolRefusal(error, result);
            await executor.post(`${path}/step`, {
              worker: args.worker,
              key: `${step}:finish`,
              name: step,
              status: error ? "failed" : "succeeded",
              result,
              error_code: error,
              output: error ? `${error}\n` : "Verified named command receipt\n",
            });
            activeStep = null;
            operation.step_results[step] = result;
            return result;
          };
          try {
            if (operation.cancel_requested && operation.steps.length === 0) {
              return await finish({
                status: "cancelled",
                result: {},
                error_code: null,
              });
            }
            let final: Result;
            if (verb === "install" || verb === "manager_converge") {
              const materialize = operation.input.materialize_command;
              if (typeof materialize === "string") {
                const fields = z.array(z.string()).parse(
                  operation.input.materialize_fields,
                );
                const body = Object.fromEntries(
                  fields.map((field) => [field, operation.input[field]]),
                );
                await run("manager-materialize", materialize, body);
              }
            }
            await run("manager-ready", command.version, {});
            if (verb === "manager_converge") {
              final = await run("tool-verified", command.version, {});
            } else if (input.intent_only) {
              final = await run("tool-verified", command.version, {});
            } else if (verb === "adopt") {
              if (input.manager === "composer") {
                const scope = await run(
                  "adoption-scope",
                  "tool.inspect-composer-scope",
                  {},
                );
                if (
                  !Array.isArray(scope.roots) ||
                  !scope.roots.includes(input.package)
                ) {
                  throw new ToolRefusal("tool.adoption_unsupported", {
                    block: "dependency",
                  });
                }
              }
              final = await run(
                "tool-verified",
                command.adoption ?? command.inventory,
                command.adoption ? packageInput(input) : {},
              );
              if (final.installed_version === null && final.block === null) {
                throw new ToolRefusal("tool.package_absent");
              }
              if (final.block !== null) {
                throw new ToolRefusal("tool.adoption_unsupported", {
                  block: final.block,
                });
              }
              if (!safeVersion(final.installed_version)) {
                throw new ToolRefusal("tool.version_probe_failed");
              }
              verifyInstalled(input, final.installed_version);
            } else {
              const beforeReceipt = await run(
                "package-before",
                command.inventory,
                {},
              );
              const before = beforeReceipt.installed_version;
              if (before !== null && !safeVersion(before)) {
                throw new ToolRefusal("tool.version_probe_failed");
              }
              if (
                verb === "install" && input.tool_id === null && before !== null
              ) throw new ToolRefusal("tool.package_already_installed");
              if (verb === "update" && before === null) {
                throw new ToolRefusal("tool.version_probe_failed");
              }
              if (before !== null && verb !== "remove") {
                verifyInstalled(input, before);
              }
              let mutate = !(verb === "install" && before !== null) &&
                !(verb === "remove" && before === null);
              if (
                mutate && (verb === "install" || verb === "update") &&
                input.constraint_intervals !== null
              ) {
                const candidate = await run(
                  "package-candidate",
                  command.candidate,
                  {
                    ...packageInput(input),
                    ...(input.manager === "composer"
                      ? { operation: verb }
                      : {}),
                  },
                );
                if (!safeVersion(candidate.version)) {
                  throw new ToolRefusal("tool.candidate_version_unavailable");
                }
                if (
                  normalizeVersion(candidate.version, input.manager) === null
                ) throw new ToolRefusal("tool.candidate_version_unparseable");
                if (!allows(input, candidate.version)) {
                  if (verb === "install") {
                    throw new ToolRefusal("tool.version_constraint_blocked", {
                      outcome: "blocked_by_constraint",
                    });
                  }
                  mutate = false;
                }
              }
              if (mutate && verb === "remove" && command.plan) {
                const plan = await run("package-removal-plan", command.plan, {
                  package: input.package,
                });
                if (
                  !Array.isArray(plan.packages) || plan.packages.length !== 1 ||
                  plan.packages[0] !== input.package
                ) throw new ToolRefusal("tool.removal_plan_unsafe");
              }
              if (mutate) {
                await run(
                  "package-mutate",
                  `${command.mutate}${verb}`,
                  packageInput(
                    input,
                    verb !== "remove" || input.manager === "brew-cask",
                  ),
                );
              }
              final = await run("tool-verified", command.inventory, {});
              const after = final.installed_version;
              if (after !== null && !safeVersion(after)) {
                throw new ToolRefusal("tool.version_probe_failed");
              }
              if (verb === "remove") {
                if (after !== null) throw new ToolRefusal("tool.remove_failed");
              } else {
                if (after === null) {
                  throw new ToolRefusal("tool.version_probe_failed");
                }
                verifyInstalled(input, after);
              }
            }
            checkLease();
            return await finish({
              status: "succeeded",
              result: final,
              error_code: null,
            });
          } catch (error) {
            // Unknown transport outcomes and lost leases remain resumable. They are never effect failures.
            if (!(error instanceof ToolRefusal)) throw error;
            const step = activeStep ?? "tool-policy";
            if (!activeStep) {
              await executor.post(`${path}/step`, {
                worker: args.worker,
                key: `${step}:start`,
                name: step,
                status: "running",
              });
            }
            await executor.post(`${path}/step`, {
              worker: args.worker,
              key: `${step}:refused`,
              name: step,
              status: "failed",
              result: error.result,
              error_code: error.code,
              output: `${error.code}\n`,
            });
            return await finish({
              status: error.code === "operation.cancelled"
                ? "cancelled"
                : "failed",
              result: error.result,
              error_code: error.code,
            });
          } finally {
            clearInterval(timer);
          }
        },
      },
    },
  };
}
export const behavior = { ...tools(), type: "@nckrtl/operation-tools" };

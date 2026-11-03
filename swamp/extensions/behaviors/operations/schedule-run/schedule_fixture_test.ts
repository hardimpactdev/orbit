/** Typed resource boundaries for Schedule compliance and refusal regressions. */
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { placedWorkflow } from "./native_fixture.ts";

/** Copied explicitly: copyBehaviors excludes test fixtures from native repositories. */
export const behaviorSource = `import { z } from "npm:zod@4.4.3";
export const behavior = {
  type: "@fixture/schedule-resource",
  version: "2026.11.03.1",
  globalArguments: z.record(z.string(), z.unknown()),
  resources: { state: {
    schema: z.object({ status: z.string(), current: z.object({ loaded: z.boolean() }), stdout: z.string() }),
    lifetime: "1d", garbageCollection: 10,
  } },
  methods: Object.fromEntries(["apply", "check", "exec"].map(method => [method, {
    description: "Fixture resource boundary",
    arguments: z.record(z.string(), z.unknown()),
    execute: async (_, ctx) => {
      const drift = Deno.env.get("ORBIT_SCHEDULE_DRIFT");
      const bad = method === "check" &&
        ((drift === "file" && ctx.globalArgs.path) || (drift === "timer" && ctx.globalArgs.service));
      return { dataHandles: [await ctx.writeResource("state", "state", {
        status: bad ? "non_compliant" : method === "apply" ? "applied" : "compliant",
        current: { loaded: drift === "loaded" },
        stdout: drift === "busy" ? "active\\n" : drift === "transition" ? "activating\\n" : "inactive\\n",
      })] };
    },
  }])),
};`;

export async function resourceFixture(kind: "create" | "destroy" | "enable") {
    const repo = await nativeRepo();
    await repo.copyBehaviors(
        "operations/schedule-run",
        "operations/processes-schedules",
    );
    await Deno.writeTextFile(
        `${repo.dir}/extensions/behaviors/operations/resource_fixture.ts`,
        behaviorSource,
    );
    const workflow = `operation-schedule-${
        kind === "enable" ? "control" : kind
    }`;
    await placedWorkflow(repo, workflow, (task) => {
        if (
            task.behaviorType?.startsWith("@swamp/cfgmgmt/") ||
            (kind === "destroy" && task.behaviorType === "@swamp/ssh")
        ) {
            task.behaviorType = "@fixture/schedule-resource";
        }
    });
    const schedule = { id: "54df47ef-1847-4798-8c56-47fa09df8b57" };
    const inputs = {
        operation_id: 1,
        node_id: 211,
        kind: `schedule.${kind}`,
        execution_secrets: {},
        intent: {
            ...(kind === "enable" ? {} : { cascade: false }),
            schedule: kind === "create"
                ? {
                    ...schedule,
                    user: "orbit",
                    group: "orbit",
                    home: "/home/orbit",
                    working_directory: "/home/orbit",
                    shell: "/bin/bash",
                    login_shell: false,
                    command_hash: "a".repeat(64),
                    calendar: "daily",
                    timeout_seconds: 60,
                    desired: "disabled",
                }
                : schedule,
            ssh_execution: {
                identity_file: "/public/key",
                target: {
                    host: "192.0.2.3",
                    user: "orbit",
                    port: 22,
                    host_keys: ["ssh-ed25519 public-fixture-key"],
                },
            },
        },
    };
    return {
        repo,
        async run(drift = "", cascade = false) {
            repo.env.ORBIT_SCHEDULE_DRIFT = drift;
            if (kind === "destroy") inputs.intent.cascade = cascade;
            await repo.cli(["workflow", "validate", workflow]);
            return await repo.runWorkflow(workflow, inputs, {
                success: !drift ||
                    (["busy", "transition"].includes(drift) && cascade),
            });
        },
        async [Symbol.asyncDispose]() {
            await repo[Symbol.asyncDispose]();
        },
    };
}

import { z } from "npm:zod@4.4.3";
import {
    Action,
    observation,
    ProviderInput,
    ProviderReceipt,
    receipt,
} from "./input.ts";
const Args = z.object({ intent: ProviderInput, action: Action }).strict();
const ObservedArgs = Args.extend({ stdout: z.string() });
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/task-vm-lifecycle-input",
    version: "2026.11.02.2",
    resources: {
        input: {
            schema: z.object({
                observe_argv: z.array(z.string()),
                action_argv: z.array(z.string()),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        observation: {
            schema: z.object({
                state: z.string(),
                disk: z.string().nullable(),
                change: z.boolean(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: {
            schema: ProviderReceipt,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description: "Validate full public provider intent",
            arguments: Args,
            async execute(raw: z.infer<typeof Args>, ctx: Context) {
                const { intent, action } = Args.parse(raw);
                const prefix = [
                    "/usr/bin/sudo",
                    "-n",
                    "--",
                    "/usr/bin/incus",
                    "--project",
                    intent.vm.project,
                ];
                const args = action === "delete"
                    ? ["delete", "--force", "--", intent.name]
                    : [
                        action,
                        ...(action === "stop" ? ["--timeout", "120"] : []),
                        "--",
                        intent.name,
                    ];
                return {
                    dataHandles: [
                        await ctx.writeResource("input", "input", {
                            observe_argv: [
                                ...prefix,
                                "list",
                                "--format",
                                "json",
                                "--",
                                intent.name,
                            ],
                            action_argv: [...prefix, ...args],
                        }),
                    ],
                };
            },
        },
        observe: {
            description: "Decode one exact provider observation",
            arguments: ObservedArgs,
            async execute(raw: z.infer<typeof ObservedArgs>, ctx: Context) {
                const { intent, action, stdout } = ObservedArgs.parse(raw);
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "observation",
                            "observation",
                            observation(intent, action, stdout),
                        ),
                    ],
                };
            },
        },
        result: {
            description: "Expose the confirmed typed provider receipt",
            arguments: ObservedArgs,
            async execute(raw: z.infer<typeof ObservedArgs>, ctx: Context) {
                const { intent, action, stdout } = ObservedArgs.parse(raw);
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "result",
                            "result",
                            receipt(intent, action, stdout),
                        ),
                    ],
                };
            },
        },
    },
};

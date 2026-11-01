import { z } from "npm:zod@4.4.3";
import {
    Action,
    discoverProvider,
    observation,
    ProviderInput,
    ProviderReceipt,
    receipt,
} from "./input.ts";
const Args = z.object({ intent: ProviderInput, action: Action }).strict();
const ObservedArgs = Args.extend({ stdout: z.string() });
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/task-vm-lifecycle-input",
    version: "2026.11.01.2",
    resources: {
        provider_input: {
            schema: z.object({ intent: ProviderInput, input_json: z.string() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        ssh_assets: {
            schema: z.object({ script_body: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
        input: {
            schema: z.object({
                input_json: z.string(),
                script: z.string(),
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
        discover: {
            description:
                "Resolve the exact labelled UpCloud VM after an uncertain create",
            arguments: z.object({
                intent: ProviderInput,
                stdout: z.string(),
                action: Action,
            }),
            async execute(
                args: {
                    intent: unknown;
                    stdout: string;
                    action: z.infer<typeof Action>;
                },
                ctx: Context,
            ) {
                const intent = discoverProvider(args.intent, args.stdout);
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "provider_input",
                            "provider_input",
                            {
                                intent,
                                input_json: JSON.stringify({
                                    ...intent,
                                    action: args.action,
                                }),
                            },
                        ),
                    ],
                };
            },
        },
        ssh_firewall: {
            description: "Resolve the fixed TaskVM SSH firewall script",
            arguments: z.object({}).strict(),
            async execute(_args: Record<string, never>, ctx: Context) {
                return {
                    dataHandles: [
                        await ctx.writeResource("ssh_assets", "ssh_assets", {
                            script_body: await Deno.readTextFile(
                                ctx.extensionFile("scripts/ssh_firewall.py"),
                            ),
                        }),
                    ],
                };
            },
        },
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
                    intent.vm?.project ?? "default",
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
                            input_json: JSON.stringify({ ...intent, action }),
                            script: ctx.extensionFile("scripts/upcloud.py"),
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

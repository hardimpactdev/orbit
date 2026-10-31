import { z } from "npm:zod@4.4.3";
import { RoleTools } from "./role_tool_assets.ts";
const Public = z.record(z.string(), z.unknown());
const Intent = z.object({
    schema_version: z.literal(1),
    node_id: z.number().int().positive(),
    mode: z.enum(["linux", "macos", "tld_only"]),
    candidate: Public,
    bootstrap: Public,
    ssh_identity: z.object({
        control_node_id: z.literal(209),
        identity_file: z.string().startsWith("/"),
        public_key: z.string().min(1),
    }).strict(),
    tools: RoleTools.nullable(),
    network: Public.nullable(),
    hub: Public.nullable(),
}).passthrough();
const Args = z.object({ intent: Intent }).strict();
/** Retain the admitted public value as a native resource before optional branch expansion. */
export const behavior = {
    type: "@nckrtl/node-enrollment-intent",
    version: "2026.10.31.1",
    resources: {
        intent: { schema: Args, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Retain the admitted public enrollment intent unchanged",
            arguments: Args,
            execute: async (
                args: z.infer<typeof Args>,
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = Args.parse(args);
                await ctx.writeResource("intent", "intent", result);
                return result;
            },
        },
    },
};

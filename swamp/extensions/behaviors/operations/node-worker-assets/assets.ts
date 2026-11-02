import { z } from "npm:zod@4.4.3";

const KnownHostsArgs = z.object({ node_id: z.literal(209) }).strict();
const KnownHostsAssets = z.object({ script_path: z.string() }).strict();

export const behavior = {
    type: "@nckrtl/node-worker-assets",
    version: "2026.11.02.1",
    resources: {
        known_hosts_script: {
            schema: KnownHostsAssets,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        credential_check_script: {
            schema: KnownHostsAssets,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        known_hosts: {
            description: "Locate the public local worker known-hosts writer",
            arguments: KnownHostsArgs,
            execute: async (raw: unknown, ctx: {
                extensionFile: (path: string) => Promise<string> | string;
                writeResource: (
                    spec: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) => {
                KnownHostsArgs.parse(raw);
                const result = KnownHostsAssets.parse({
                    script_path: await ctx.extensionFile(
                        "scripts/write_known_hosts.py",
                    ),
                });
                await ctx.writeResource("known_hosts_script", "script", result);
                return result;
            },
        },
        credential_check: {
            description: "Locate the public read-only credential expiry check",
            arguments: KnownHostsArgs,
            execute: async (raw: unknown, ctx: {
                extensionFile: (path: string) => Promise<string> | string;
                writeResource: (
                    spec: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) => {
                KnownHostsArgs.parse(raw);
                const result = KnownHostsAssets.parse({
                    script_path: await ctx.extensionFile(
                        "scripts/credential_check.py",
                    ),
                });
                await ctx.writeResource(
                    "credential_check_script",
                    "script",
                    result,
                );
                return result;
            },
        },
    },
};

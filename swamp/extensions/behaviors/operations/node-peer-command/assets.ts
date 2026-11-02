import { z } from "npm:zod@4.4.3";
const Key = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
/** The guest's public WireGuard key from the exec step that prepared it; the private key stays in its key file. */
export function publicKey(stdout: string) {
    return { public_key: Key.parse(stdout.trim()) };
}
export const behavior = {
    type: "@nckrtl/node-peer-command",
    version: "2026.11.02.1",
    resources: {
        state: {
            schema: z.object({ public_key: Key }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        decode: {
            description: "Read the public key the preceding official SSH step printed",
            arguments: z.object({ stdout: z.string() }),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const state = publicKey(args.stdout);
                await ctx.writeResource("state", "state", state);
                return state;
            },
        },
    },
};

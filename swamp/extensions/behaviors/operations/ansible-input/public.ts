import { z } from "npm:zod@4.4.3";

const Path = z.string().regex(
    /^\/tmp\/orbit-ansible-[0-9a-f-]{36}\/public\.json$/,
);
const Input = z.object({ path: Path }).strict();
/** What a slot's identity phase prints for the Gateway and the hub: public keys and a digest, never a secret. */
const Output = z.object({
    wireguard_public_key: z.string().regex(/^[A-Za-z0-9+\/]{43}=$/),
    ssh_host_key: z.string().regex(/^ssh-ed25519 [A-Za-z0-9+\/]+={0,2}$/),
    observer_digest: z.string().regex(/^[a-f0-9]{64}$/),
    revision: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** An Ansible phase prints nothing a later step can use, so it leaves its public result in a file for this step. */
export const behavior = {
    type: "@nckrtl/ansible-public",
    version: "2026.11.05.1",
    resources: {
        public: { schema: Output, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description: "Read the public result of an Ansible phase",
            arguments: Input,
            async execute(raw: z.infer<typeof Input>, ctx: Context) {
                const args = Input.parse(raw);
                const value = Output.parse(
                    JSON.parse(await Deno.readTextFile(args.path)),
                );
                await ctx.writeResource("public", "public", value);
                return value;
            },
        },
    },
};

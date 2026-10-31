import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Uuid = z.uuid();
const PublicKey = z.string().max(1024).regex(
    /^ssh-ed25519 [A-Za-z0-9+/]+={0,2}(?: [!-~]+)?$/,
);
export const UpCloudIntent = z.object({
    task_vm_id: Id,
    node_id: Id,
    gateway_node_id: Id,
    provider: z.literal("upcloud"),
    provider_id: Uuid.nullable(),
    name: z.string().regex(/^tvm-[1-9][0-9]*$/),
    image_fingerprint: Uuid,
    recipe_identity: z.string().min(1).max(255),
    provider_host_public_key: PublicKey,
    upcloud: z.object({
        zone: z.string().regex(/^[a-z]{2}-[a-z]{3}[1-9]$/),
        plan: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/),
        disk_gb: z.number().int().min(10).max(200),
        max_vms: z.number().int().min(1).max(16),
        source_template: Uuid,
        gateway_public_key: z.string().max(4096).regex(
            /^(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+={0,3}(?: [!-~][ -~]*)?$/,
        ),
    }).strict(),
}).refine((value) =>
    value.node_id === value.gateway_node_id &&
    value.name === `tvm-${value.task_vm_id}` &&
    value.image_fingerprint === value.upcloud.source_template
);
const Args = z.object({ intent: UpCloudIntent, root_node_id: Id });
const Inventory = z.object({
    count: z.number().int().min(0).max(4096),
    existing_id: z.union([Uuid, z.literal("")]),
}).strict();
const Plan = z.object({ tier: z.string().regex(/^[a-z]{1,16}$/).nullable() })
    .strict();
const Server = z.object({ provider_id: Uuid }).strict();
const Provider = Server.extend({
    address: z.ipv4(),
    ssh_host_key: PublicKey,
    image_fingerprint: Uuid,
    running: z.literal(true),
    settled: z.literal(true),
}).strict();
const Assets = z.object({
    input_json: z.string(),
    inventory_script: z.string(),
    plan_script: z.string(),
    create_script: z.string(),
    start_script: z.string(),
    resolve_script: z.string(),
    observe_script: z.string(),
});
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
async function write(ctx: Context, spec: string, value: unknown) {
    return { dataHandles: [await ctx.writeResource(spec, spec, value)] };
}
function decode<T>(schema: z.ZodType<T>, stdout: string): T {
    try {
        return schema.parse(JSON.parse(stdout));
    } catch {
        throw new Error("task_vm.provider_create_refused");
    }
}
/** Public metadata and typed results only; each effect lives in its native placed script. */
export const behavior = {
    type: "@nckrtl/task-vm-upcloud-inputs",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
        inventory: {
            schema: Inventory,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        plan: {
            schema: z.object({ plan_json: z.string() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        server: { schema: Server, lifetime: "infinite", garbageCollection: 10 },
        result: {
            schema: z.object({ provider: Provider }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Validate the unchanged root executor and resolve native script assets",
            arguments: Args,
            async execute(args: z.infer<typeof Args>, ctx: Context) {
                const value = UpCloudIntent.parse(args.intent);
                if (value.node_id !== args.root_node_id) {
                    throw new Error("task_vm.provider_create_refused");
                }
                return await write(ctx, "assets", {
                    input_json: JSON.stringify(value),
                    ...Object.fromEntries(
                        [
                            "inventory",
                            "plan",
                            "create",
                            "resolve",
                            "start",
                            "observe",
                        ].map(
                            (
                                name,
                            ) => [
                                name + "_script",
                                ctx.extensionFile("scripts/" + name + ".py"),
                            ],
                        ),
                    ),
                });
            },
        },
        inventory: {
            description: "Shape public provider inventory without an API call",
            arguments: z.object({ stdout: z.string().max(65536) }),
            execute(args: { stdout: string }, ctx: Context) {
                return write(ctx, "inventory", decode(Inventory, args.stdout));
            },
        },
        plan: {
            description: "Shape the admitted plan's public storage tier",
            arguments: z.object({ stdout: z.string().max(1024) }),
            execute(args: { stdout: string }, ctx: Context) {
                return write(ctx, "plan", {
                    plan_json: JSON.stringify(decode(Plan, args.stdout)),
                });
            },
        },
        server: {
            description:
                "Shape the created or rediscovered public server identifier",
            arguments: z.object({ stdout: z.string().max(1024) }),
            execute(args: { stdout: string }, ctx: Context) {
                return write(ctx, "server", decode(Server, args.stdout));
            },
        },
        result: {
            description:
                "Return the observed public provider result for the original parent",
            arguments: z.object({
                intent: UpCloudIntent,
                stdout: z.string().max(8192),
            }),
            execute(
                args: { intent: z.infer<typeof UpCloudIntent>; stdout: string },
                ctx: Context,
            ) {
                const value = UpCloudIntent.parse(args.intent),
                    provider = decode(Provider, args.stdout);
                if (
                    provider.image_fingerprint !== value.image_fingerprint ||
                    provider.ssh_host_key !== value.provider_host_public_key ||
                    value.provider_id !== null &&
                        provider.provider_id !== value.provider_id
                ) throw new Error("task_vm.provider_create_refused");
                return write(ctx, "result", { provider });
            },
        },
    },
};

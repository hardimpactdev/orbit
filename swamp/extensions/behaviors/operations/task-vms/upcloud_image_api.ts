import { z } from "npm:zod@4.4.3";

/**
 * Pure shaping for the UpCloud template build: UpCloud request bodies in,
 * typed provider answers out. No request, guest command,
 * Gateway call, wait or retry runs here; every effect is one inline curl on
 * 209 or one official SSH step in the workflow.
 */
const uuid = z.uuid();
const object = z.record(z.string(), z.unknown());
export const Slot = z.enum(["builder", "smoke"]);
export type Slot = z.infer<typeof Slot>;

/** The fields of the admitted public intent that shaping reads; the workflow schema owns the rest. */
export const Intent = z.object({
    build_id: z.number().int().positive(),
    stamp: z.string().regex(/^[0-9]{14}$/),
    name: z.string(),
    identity: z.string().regex(/^sha256:[a-f0-9]{64}$/),
    recipe: object,
    upcloud: z.object({
        zone: z.string().regex(/^[a-z]{2}-[a-z]{3}[1-9]$/),
        plan: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/),
        disk_gb: z.number().int().min(10).max(200),
    }).strict(),
    host_key: z.string().regex(
        /^ssh-ed25519 [A-Za-z0-9+/]+={0,2}(?: [!-~]+)?$/,
    ),
    ssh: z.object({ public_key: z.string().startsWith("ssh-ed25519 ") })
        .passthrough(),
    pinned_templates: z.array(uuid),
}).passthrough().superRefine((input, ctx) => {
    if (input.name !== `tvm-image-${input.stamp}`) {
        ctx.addIssue({
            code: "custom",
            message: "The server name differs from its admitted stamp",
        });
    }
});
export type Intent = z.infer<typeof Intent>;

const invalid = () => new Error("task_vm.upcloud_invalid");
const json = (stdout: string): unknown => {
    try {
        return JSON.parse(stdout);
    } catch {
        throw invalid();
    }
};
export const serverName = (input: Intent, slot: Slot) =>
    input.name + (slot === "smoke" ? "-smoke" : "");
const unverified = (input: Intent) =>
    `orbit-task-base-${input.stamp}-unverified`;

/** The curl variable that carries the vault host private key into the body. */
export const HostKeyVariable = "ORBIT_HOST_PRIVATE_KEY";
const Placeholder = "@@ORBIT_HOST_PRIVATE_KEY@@";

/**
 * One create body as a curl --expand-data template. The private host key is
 * the only expansion: user data is JSON inside a JSON string, so curl
 * escapes it twice (`:json:json`). Everything else is public intent.
 */
export function createRequest(
    raw: unknown,
    slot: Slot,
    source: string,
    plansStdout: string,
) {
    const input = Intent.parse(raw);
    const plans = z.object({
        plans: z.object({
            plan: z.array(
                z.object({
                    name: z.string(),
                    storage_tier: z.string().nullish(),
                })
                    .passthrough(),
            ),
        }),
    }).parse(json(plansStdout)).plans.plan.filter((plan) =>
        plan.name === input.upcloud.plan
    );
    if (plans.length !== 1) throw invalid();
    const tier = plans[0].storage_tier ?? null;
    if (tier !== null && !/^[a-z]{1,16}$/.test(tier)) throw invalid();
    const name = serverName(input, slot);
    const config = {
        users: [{
            name: "orbit",
            lock_passwd: true,
            shell: "/bin/bash",
            sudo: "ALL=(ALL) NOPASSWD:ALL",
            ssh_authorized_keys: [input.ssh.public_key],
            // The builder fixes the account the recipe expects; the template keeps it.
            ...(slot === "builder" ? { uid: 1000, no_user_group: false } : {}),
        }],
        disable_root: true,
        ssh_deletekeys: true,
        ssh_genkeytypes: [],
        ssh_keys: {
            ed25519_private: Placeholder,
            ed25519_public: input.host_key,
        },
    };
    const body = JSON.stringify({
        server: {
            zone: input.upcloud.zone,
            title: name,
            hostname: name,
            plan: input.upcloud.plan,
            metadata: "yes",
            firewall: "off",
            remote_access_enabled: "no",
            labels: {
                label: [{ key: "orbit-task-image", value: input.stamp }],
            },
            networking: {
                interfaces: {
                    interface: [{
                        type: "public",
                        ip_addresses: { ip_address: [{ family: "IPv4" }] },
                    }],
                },
            },
            storage_devices: {
                storage_device: [{
                    action: "clone",
                    storage: uuid.parse(source),
                    title: name + "-disk",
                    size: input.upcloud.disk_gb,
                    ...(tier === null ? {} : { tier }),
                }],
            },
            login_user: {
                username: "root",
                ssh_keys: { ssh_key: [input.ssh.public_key] },
            },
            user_data: "#cloud-config\n" + JSON.stringify(config) + "\n",
        },
    });
    // Public text must not open a curl expansion of its own.
    if (body.includes("{{") || body.split(Placeholder).length !== 2) {
        throw invalid();
    }
    return {
        create_data: body.replace(
            Placeholder,
            `{{${HostKeyVariable}:json:json}}`,
        ),
    };
}

/** The create answer names the server and its one public IPv4 address. */
export function createdServer(raw: unknown, slot: Slot, stdout: string) {
    const input = Intent.parse(raw);
    const server = z.object({
        server: z.object({
            uuid,
            title: z.string(),
            hostname: z.string(),
            labels: z.object({
                label: z.array(
                    z.object({ key: z.string(), value: z.string() }),
                ),
            }),
            ip_addresses: z.object({
                ip_address: z.array(
                    z.object({
                        access: z.string(),
                        family: z.string(),
                        address: z.string(),
                    }),
                ),
            }),
            storage_devices: z.object({
                storage_device: z.array(
                    z.object({ storage: uuid }).passthrough(),
                )
                    .length(1),
            }),
        }).passthrough(),
    }).parse(json(stdout)).server;
    const stamps = server.labels.label.filter((label) =>
        label.key === "orbit-task-image"
    );
    const addresses = server.ip_addresses.ip_address.filter((ip) =>
        ip.access === "public" && ip.family === "IPv4"
    );
    if (
        server.title !== serverName(input, slot) ||
        server.hostname !== server.title ||
        stamps.length !== 1 || stamps[0].value !== input.stamp ||
        addresses.length !== 1
    ) throw invalid();
    return {
        uuid: server.uuid,
        storage: server.storage_devices.storage_device[0].storage,
        address: z.ipv4().parse(addresses[0].address),
    };
}

/** The templatize answer names the unverified template of this attempt. */
export function madeTemplate(raw: unknown, stdout: string) {
    const input = Intent.parse(raw);
    const storage = z.object({
        storage: z.object({ uuid, title: z.string() }).passthrough(),
    }).parse(json(stdout)).storage;
    if (storage.title !== unverified(input)) throw invalid();
    return { template_uuid: storage.uuid };
}

const Labels = z.array(z.object({ key: z.string(), value: z.string() }));
const Listing = z.object({
    servers: z.object({
        servers: z.object({
            server: z.array(
                z.object({
                    uuid,
                    title: z.string(),
                    state: z.string(),
                    labels: z.object({ label: Labels }),
                }).passthrough(),
            ).max(4096),
        }),
    }),
    templates: z.object({
        storages: z.object({
            storage: z.array(
                z.object({
                    uuid,
                    title: z.string(),
                    access: z.string(),
                    labels: Labels.optional(),
                }).passthrough(),
            ).max(4096),
        }),
    }),
});
type Template = z.infer<
    typeof Listing
>["templates"]["storages"]["storage"][number];
const stampLabel = (labels: z.infer<typeof Labels>) =>
    labels.filter((label) => label.key === "orbit-task-image");

/**
 * The settle plan after the build, from one listing: this attempt's build
 * servers always go. A promoted template keeps the two newest verified
 * templates and those live VMs pin; otherwise this attempt's unverified
 * template goes. Leftovers of other attempts belong to the host sweep.
 */
export function cleanupPlan(
    raw: unknown,
    stdout: string,
    templateUuid: string,
) {
    const input = Intent.parse(raw);
    const rows = Listing.parse(json(stdout));
    const verifiedStamp = (row: Template) => {
        const stamp = /^orbit-task-base-([0-9]{14})$/.exec(row.title)?.[1];
        const labels = stampLabel(row.labels ?? []);
        return row.access === "private" && stamp !== undefined &&
                labels.length === 1 && labels[0].value === stamp
            ? stamp
            : null;
    };
    const templates = rows.templates.storages.storage;
    const current = templateUuid === ""
        ? []
        : templates.filter((row) => row.uuid === uuid.parse(templateUuid));
    const promoted = current.length === 1 &&
        verifiedStamp(current[0]) === input.stamp &&
        (current[0].labels ?? []).some((label) =>
            label.key === "orbit.recipe" && label.value === input.identity
        );
    const names = [serverName(input, "builder"), serverName(input, "smoke")];
    const servers = rows.servers.servers.server.filter((row) => {
        const labels = stampLabel(row.labels.label);
        return names.includes(row.title) && labels.length === 1 &&
            labels[0].value === input.stamp;
    }).map((row) => ({
        uuid: row.uuid,
        path: `server/${row.uuid}?storages=1&backups=delete`,
        stop: row.state !== "stopped",
    }));
    const storage = (row: Template) => ({
        uuid: row.uuid,
        path: `storage/${row.uuid}`,
        stop: false,
    });
    const verified = templates.filter((row) => verifiedStamp(row) !== null)
        .sort((a, b) => b.title.localeCompare(a.title));
    const templateDeletes = promoted
        ? verified.slice(2).filter((row) =>
            row.uuid !== current[0].uuid &&
            !input.pinned_templates.includes(row.uuid)
        )
        : templates.filter((row) =>
            row.access === "private" && row.title === unverified(input)
        );
    return {
        promoted,
        template_uuid: promoted ? current[0].uuid : "",
        deletes: [...servers, ...templateDeletes.map(storage)],
    };
}

type Handle = { name: string };
type Context = {
    writeResource(
        spec: string,
        name: string,
        data: Record<string, unknown>,
    ): Promise<Handle>;
};
const resource = (schema: z.ZodType) => ({
    description: "Public UpCloud template build shaping",
    schema,
    lifetime: "1d",
    garbageCollection: 1,
});
const write = async (
    ctx: Context,
    spec: string,
    data: Record<string, unknown>,
) => ({ dataHandles: [await ctx.writeResource(spec, spec, data)] });
export const behavior = {
    type: "@nckrtl/task-vm-upcloud-image-build",
    version: "2026.11.06.1",
    resources: {
        request: resource(z.object({ create_data: z.string() })),
        server: resource(z.object({ uuid, storage: uuid, address: z.ipv4() })),
        template: resource(z.object({ template_uuid: uuid })),
        cleanup: resource(z.object({
            promoted: z.boolean(),
            template_uuid: z.string(),
            deletes: z.array(
                z.object({ uuid, path: z.string(), stop: z.boolean() }),
            ),
        })),
    },
    methods: {
        request: {
            description: "Shape one create body from the plan's storage tier",
            arguments: z.object({
                intent: z.unknown(),
                slot: Slot,
                source: z.string(),
                plans_stdout: z.string(),
            }),
            execute: (
                args: {
                    intent: unknown;
                    slot: Slot;
                    source: string;
                    plans_stdout: string;
                },
                ctx: Context,
            ) => write(
                ctx,
                "request",
                createRequest(
                    args.intent,
                    args.slot,
                    args.source,
                    args.plans_stdout,
                ),
            ),
        },
        server: {
            description: "Read the created server's UUID and public address",
            arguments: z.object({
                intent: z.unknown(),
                slot: Slot,
                stdout: z.string(),
            }),
            execute: (
                args: { intent: unknown; slot: Slot; stdout: string },
                ctx: Context,
            ) => write(
                ctx,
                "server",
                createdServer(args.intent, args.slot, args.stdout),
            ),
        },
        template: {
            description: "Read the template UUID from the templatize answer",
            arguments: z.object({ intent: z.unknown(), stdout: z.string() }),
            execute: (
                args: { intent: unknown; stdout: string },
                ctx: Context,
            ) => write(ctx, "template", madeTemplate(args.intent, args.stdout)),
        },
        cleanup: {
            description:
                "Confirm the promotion and pick this attempt's servers and templates to delete",
            arguments: z.object({
                intent: z.unknown(),
                stdout: z.string(),
                template_uuid: z.string(),
            }),
            execute: (
                args: {
                    intent: unknown;
                    stdout: string;
                    template_uuid: string;
                },
                ctx: Context,
            ) => write(
                ctx,
                "cleanup",
                cleanupPlan(args.intent, args.stdout, args.template_uuid),
            ),
        },
    },
};

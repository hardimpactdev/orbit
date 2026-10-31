import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Key = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
const Process = z.object({
    process_id: Id,
    name: z.string().regex(/^orbit-process-[1-9][0-9]*-pi-server\.service$/),
    runtime: z.literal("systemd"),
    content_hash: Digest,
    desired: z.literal("running"),
    on_demand: z.literal(false),
    activation_pending: z.boolean(),
    agent_sha256: Digest.optional(),
    retired: z.null().optional(),
}).strict();

/** Public intent only. Vault references belong to the separate native envelope. */
export const EnrollmentCommandIntent = z.object({
    schema_version: z.literal(1),
    task_vm_id: Id,
    guest_node_id: Id,
    execution_node_id: Id,
    gateway_node_id: Id,
    issuer_node_id: Id,
    model_router_node_id: Id,
    participant_node_ids: z.array(Id).min(1).max(4096),
    provider: z.enum(["incus", "upcloud"]),
    name: z.string().regex(/^tvm-[1-9][0-9]*$/),
    user: z.literal("orbit"),
    pi_url: z.url(),
    model_origin: z.url(),
    agent_sha256: Digest,
    agent_artifact_url: z.url().nullable(),
    bootstrap: z.object({
        user: z.literal("orbit"),
        public_key: z.string().min(1).max(4096),
        releases: z.array(z.string()).max(32),
        packages: z.array(z.string().regex(/^[a-z0-9][a-z0-9+.-]*$/)).max(256),
        dns_policy: z.string().max(64),
    }).strict(),
    agent: z.object({
        configuration: z.string().max(65536),
        certificate: z.string().max(65536),
        unit: z.string().max(65536),
        checkouts: z.array(z.string()).max(1024),
    }).strict(),
    vm: z.object({
        project: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/),
        name: z.string().regex(/^tvm-[1-9][0-9]*$/),
    }).strict().nullable(),
    node: z.object({
        node_id: Id,
        name: z.string(),
        user: z.literal("orbit"),
        platform: z.literal("linux").optional(),
        architecture: z.literal("x86_64").optional(),
        wireguard_ip: z.ipv4(),
        cluster_id: Id.nullable().optional(),
        ssh_jump_node_id: Id.nullable().optional(),
    }).strict(),
    network: z.object({
        server_public_key: Key,
        endpoint: z.string().max(255),
        address: z.cidrv4(),
        subnet: z.cidrv4(),
        dns_server: z.ipv4(),
        domains: z.array(z.string().max(253)).max(256),
    }).strict(),
    _wireguard: z.object({
        schema_version: z.literal(1),
        executor_node_id: Id,
        hub_id: Id,
        context_node_id: Id,
        excluded_node_id: z.null(),
        agent_sha256: Digest,
    }).strict(),
    runtime: z.object({
        guest_node_id: Id,
        user: z.literal("orbit"),
        agent_sha256: Digest,
        pi_sha256: Digest,
        origin: z.url(),
        models: z.array(z.string().min(1).max(255)).min(1).max(256),
        process: Process,
    }).strict(),
    connection: z.object({
        address: z.ipv4().nullable(),
        public_key: z.string().max(4096).nullable(),
    }).strict(),
}).strict().superRefine((value, context) => {
    const participants = new Set(value.participant_node_ids);
    const owned = [
        value.guest_node_id,
        value.execution_node_id,
        value.gateway_node_id,
        value.issuer_node_id,
        value.model_router_node_id,
        value._wireguard.hub_id,
    ];
    const pi = new URL(value.pi_url);
    const piPort = Number(pi.port);
    if (
        value.name !== "tvm-" + value.task_vm_id ||
        value.node.name !== value.name ||
        value.node.node_id !== value.guest_node_id ||
        value.runtime.guest_node_id !== value.guest_node_id ||
        value.runtime.agent_sha256 !== value.agent_sha256 ||
        value.runtime.process.name !==
            `orbit-process-${value.runtime.process.process_id}-pi-server.service` ||
        value.runtime.origin !== value.model_origin ||
        pi.protocol !== "http:" || pi.port === "" ||
        pi.hostname !== value.node.wireguard_ip ||
        piPort < 1 || piPort > 65535 || pi.username !== "" ||
        pi.password !== "" ||
        pi.pathname !== "/" || pi.search !== "" || pi.hash !== "" ||
        value._wireguard.context_node_id !== value.guest_node_id ||
        value._wireguard.executor_node_id !== value.gateway_node_id ||
        value.guest_node_id === value.execution_node_id ||
        owned.some((id) => !participants.has(id)) ||
        (value.provider === "incus"
            ? value.vm?.name !== value.name
            : value.vm !== null ||
                value.execution_node_id !== value.gateway_node_id)
    ) {
        context.addIssue({
            code: "custom",
            message: "Enrollment identity differs from admission",
        });
    }
});
export type EnrollmentCommandIntent = z.infer<typeof EnrollmentCommandIntent>;

/** This behavior only serializes validated public metadata; it executes no action. */
export function enrollmentCommandInputs() {
    return {
        type: "@nckrtl/task-vm-enrollment-inputs",
        version: "2026.10.31.1",
        resources: {
            intent: {
                description: "Validated public intent for placed command steps",
                schema: z.object({
                    intent_json: z.string(),
                    project: z.string(),
                    name: z.string(),
                    agent_sha256: Digest,
                }),
                lifetime: "infinite" as const,
                garbageCollection: 10,
            },
            guest: {
                description:
                    "Public identity and pinned runtime facts for guest command steps",
                schema: z.object({
                    name: z.string(),
                    guest_node_id: Id,
                    pi_sha256: Digest,
                    pi_url: z.url(),
                    guest_address: z.ipv4(),
                    process_id: Id,
                    process_hash: Digest,
                    process_unit: z.string(),
                }),
                lifetime: "infinite" as const,
                garbageCollection: 10,
            },
        },
        methods: {
            guest: {
                description:
                    "Validate guest runtime identity before any capability credential resolves",
                arguments: z.object({ intent: EnrollmentCommandIntent })
                    .strict(),
                async execute(
                    args: { intent: EnrollmentCommandIntent },
                    context: {
                        writeResource(
                            spec: string,
                            name: string,
                            value: unknown,
                        ): Promise<unknown>;
                    },
                ) {
                    const parsed = EnrollmentCommandIntent.safeParse(
                        args.intent,
                    );
                    if (!parsed.success) {
                        throw new Error("task_vm.enrollment_invalid");
                    }
                    const value = parsed.data;
                    return {
                        dataHandles: [
                            await context.writeResource("guest", "guest", {
                                name: value.name,
                                guest_node_id: value.guest_node_id,
                                pi_sha256: value.runtime.pi_sha256,
                                pi_url: value.pi_url,
                                guest_address: value.node.wireguard_ip,
                                process_id: value.runtime.process.process_id,
                                process_hash:
                                    value.runtime.process.content_hash,
                                process_unit: value.runtime.process.name,
                            }),
                        ],
                    };
                },
            },
            read: {
                description:
                    "Serialize complete public enrollment intent without a Gateway request",
                arguments: z.object({ intent: EnrollmentCommandIntent })
                    .strict(),
                async execute(
                    args: { intent: EnrollmentCommandIntent },
                    context: {
                        writeResource(
                            spec: string,
                            name: string,
                            value: unknown,
                        ): Promise<unknown>;
                    },
                ) {
                    const parsed = EnrollmentCommandIntent.safeParse(
                        args.intent,
                    );
                    if (
                        !parsed.success || parsed.data.provider !== "incus" ||
                        !parsed.data.vm
                    ) {
                        throw new Error("task_vm.enrollment_invalid");
                    }
                    const value = {
                        intent_json: JSON.stringify(parsed.data),
                        project: parsed.data.vm.project,
                        name: parsed.data.name,
                        agent_sha256: parsed.data.agent_sha256,
                    };
                    return {
                        dataHandles: [
                            await context.writeResource(
                                "intent",
                                "intent",
                                value,
                            ),
                        ],
                    };
                },
            },
        },
    };
}
export const behavior = enrollmentCommandInputs();

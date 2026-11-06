import { z } from "npm:zod@4.4.3";
import {
    classifyProvisioningProfile,
    ProvisioningRefusal,
} from "../instance-lifecycle/source_profile.ts";
import { WorkspaceInput } from "../task-vm-workspace/input.ts";
import {
    capturedArgv,
    type PublicTail,
    readCapture,
    readPublicTail,
} from "../../_lib/public_output.ts";
import { lifecycleArgv } from "./lifecycle.ts";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
const Path = z.string().startsWith("/").min(2).refine((v) => !v.includes("\0"));
const Workspace = z.object({
    checkout_path: Path,
    repository: z.string().min(1),
    repository_identity: z.string().min(1),
    branch: z.string().min(1),
    default_branch: z.string().min(1),
    start_commit: Sha,
    user: z.string().min(1),
    project_type: z.enum([
        "monorepo",
        "laravel-app",
        "symfony-app",
        "laravel-package",
        "node-package",
    ]),
    entry_point: z.enum(["artisan", "bin/console"]),
    skip_profile: z.boolean(),
    php_versions: z.array(z.string()).min(1),
    setup: z.array(
        z.object({
            name: z.string(),
            command: z.string(),
            timeout_seconds: Id,
            input_json: z.string(),
        }),
    ).max(32),
});
export const Intent = z.object({
    task_vm_id: Id,
    guest_node_id: Id,
    group_id: Id,
    instance_id: Id,
    project_id: Id,
    proof_club_required: z.boolean().default(false),
    wireguard_ip: z.ipv4(),
    ssh_identity: WorkspaceInput.shape.ssh_execution.omit({ target: true }),
    workspace: Workspace,
});
const Vm = z.object({
    task_vm_id: Id,
    guest_node_id: Id,
    ready: z.literal(true),
    provider: z.record(z.string(), z.unknown()),
    enrollment: z.object({
        ready: z.literal(true),
        node: z.object({
            ssh_host_key_type: z.literal("ssh-ed25519"),
            ssh_host_key: z.string().min(1),
        }).passthrough(),
        process: z.record(z.string(), z.unknown()),
    }).passthrough(),
});
const data = async (ctx: Context, value: Record<string, unknown>) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
/**
 * Fills each `host_keys: null` in the workspace's Route and Process intents with the key the VM step pinned. The
 * Gateway leaves only the guest's targets unpinned (PendingSshHostKey), so no other Node's key is touched.
 */
export function bindHostKeys<T>(
    value: T,
    receipt: unknown,
    guestNodeId: number,
): T {
    const vm = Vm.parse(receipt);
    if (vm.guest_node_id !== guestNodeId) {
        throw new Error("tasks.provision_refused");
    }
    const pinned = [
        vm.enrollment.node.ssh_host_key_type + " " +
        vm.enrollment.node.ssh_host_key,
    ];
    const walk = (item: unknown): unknown => {
        if (Array.isArray(item)) return item.map(walk);
        if (item === null || typeof item !== "object") return item;
        return Object.fromEntries(
            Object.entries(item).map((
                [key, field],
            ) => [
                key,
                key === "host_keys" && field === null ? pinned : walk(field),
            ]),
        );
    };
    return walk(value) as T;
}
/**
 * Pins the guest targets of the intents a prep run captured at admission to the host key the VM step observed. The
 * receipt is the provision receipt as the run printed it.
 */
export function pinIntents<T>(
    receipt: string | Record<string, unknown>,
    guestNodeId: number,
    intents: T,
): T {
    return bindHostKeys(
        intents,
        typeof receipt === "string" ? JSON.parse(receipt) : receipt,
        guestNodeId,
    );
}
export function connection(raw: unknown, receipt: unknown) {
    const intent = Intent.parse(raw), vm = Vm.parse(receipt);
    if (
        vm.task_vm_id !== intent.task_vm_id ||
        vm.guest_node_id !== intent.guest_node_id
    ) throw new Error("tasks.provision_refused");
    const ssh_execution = {
        ...intent.ssh_identity,
        target: {
            name: "target",
            node_id: intent.guest_node_id,
            host: intent.wireguard_ip,
            user: intent.workspace.user,
            port: 22,
            host_keys: [
                vm.enrollment.node.ssh_host_key_type + " " +
                vm.enrollment.node.ssh_host_key,
            ],
        },
    };
    return WorkspaceInput.parse({
        task_vm_id: intent.task_vm_id,
        group_id: intent.group_id,
        instance_id: intent.instance_id,
        project_id: intent.project_id,
        checkout_path: intent.workspace.checkout_path,
        ssh_execution,
    });
}
const Source = z.object({
    branch: z.string(),
    commit: Sha,
    php_version: z.string().nullable(),
    laravel: z.boolean(),
});
const Observation = z.object({ source: Source });
const COMPOSER_BYTES = 1048576;

/**
 * The SSH exec argv of each admitted setup command, in order. Each writes its
 * output to `<root>/output/setup-<index>` on the VM instead of the SSH session.
 */
export function setupArgv(raw: unknown, root: string): string[][] {
    const workspace = Intent.parse(raw).workspace;
    return workspace.setup.map((item, index) =>
        capturedArgv(
            `${root}/output`,
            `setup-${index}`,
            lifecycleArgv(
                workspace.checkout_path,
                workspace.user,
                item.command,
                // Composer reads the private-dependency configuration of the setup steps from here, whatever XDG says.
                [`COMPOSER_HOME=/home/${workspace.user}/.config/composer`],
            ),
        )
    );
}

const SETUP_OUTPUT_BYTES = 4096;

/**
 * The redacted tail of each setup command's downloaded output file in
 * `directory`, by step name; each file is deleted after it is read. A setup
 * that did not run has no entry.
 */
export async function setupOutput(
    raw: unknown,
    directory: string,
): Promise<Record<string, PublicTail>> {
    const outputs: Record<string, PublicTail> = {};
    for (const index of Intent.parse(raw).workspace.setup.keys()) {
        const name = `setup-${index}`;
        const tail = await readPublicTail(
            `${directory}/${name}`,
            SETUP_OUTPUT_BYTES,
        );
        if (tail !== null) outputs[name] = tail;
    }
    return outputs;
}

/**
 * Shapes the public checkout reads into the identity and source profile.
 * `identity` is `git rev-parse HEAD --abbrev-ref HEAD`, `files` the `stat -c %n|%F|%s` lines of
 * composer.json and the entry point (absent files have no line), and
 * `composer` the downloaded composer.json bytes, or null when no copy ran.
 */
export function observe(
    workspace: z.infer<typeof Workspace>,
    read: {
        identity: string;
        files: string;
        composer: Uint8Array | null;
    },
) {
    const [commit = "", branch = ""] = read.identity.trim().split("\n");
    const files = new Map(
        read.files.split("\n").filter((line) => line !== "").map((line) => {
            const [name, type, size] = line.split("|");
            return [name, {
                regular: type === "regular file" ||
                    type === "regular empty file",
                size: Number(size),
            }];
        }),
    );
    const composer = files.get("composer.json");
    const entry = files.get(workspace.entry_point);
    let profile: Record<string, unknown> = {
        kind: "none",
        composer: null,
        entry_point: null,
    };
    if (workspace.skip_profile) {
        // A monorepo's root metadata is irrelevant to its workspace.
    } else if (composer === undefined) {
        if (entry !== undefined) profile = { ...profile, kind: "partial" };
    } else if (!composer.regular || composer.size > COMPOSER_BYTES) {
        profile = { ...profile, kind: "unsafe" };
    } else {
        // inspect saw a regular composer.json, so its copy must have run.
        if (read.composer === null) throw new Error("tasks.provision_failed");
        const bytes = read.composer;
        profile = {
            kind: "composer",
            composer: btoa(
                Array.from(bytes, (b) => String.fromCharCode(b)).join(""),
            ),
            entry_point: entry === undefined
                ? "absent"
                : entry.regular
                ? "regular"
                : "unsafe",
        };
    }
    return {
        branch,
        commit: Sha.parse(commit),
        profile,
    };
}
function nativeOutputs(steps: Record<string, unknown>, name: string) {
    const step =
        z.record(z.string(), z.unknown()).safeParse(steps[name]).data ??
            {};
    const outputs =
        z.record(z.string(), z.unknown()).safeParse(step.outputs).data ?? {};
    return { step, outputs };
}
/** The steps of the private-dependency configuration, in order; `deps_revoke` reports in the receipt and does not fail the provision. */
const DEPENDENCY_STEPS = ["deps_mint", "deps_clear", "deps_write_composer", "deps_write_npm", "deps_remove_files"];
const COULD_NOT_RUN = "The revoke step could not run.";
const DependencyToken = z.object({
    state: z.enum(["revoked", "none", "unrevoked"]),
    reason: z.string().max(200).optional(),
});
/**
 * What became of the setup token, from the revoke step (`deps_revoke`, or `Revoke` in the remove child of `tasks.package`): curl prints the HTTP status of the
 * revoke. 204 is a revoked token; 401 means GitHub holds no such token (none was minted, or it had expired or been
 * revoked), so there is nothing to revoke; anything else, or no answer, leaves a token that may still be valid.
 */
export function revokeOutcome(steps: Record<string, unknown>, name = "Revoke") {
    const { step, outputs } = nativeOutputs(steps, name);
    if (step.status === undefined || step.status === "skipped") {
        return { state: "unrevoked" as const, reason: COULD_NOT_RUN };
    }
    if (step.status !== "succeeded") {
        // Vault key unreadable: the step failed before its command ran.
        const error = typeof step.error === "string" ? step.error : "";
        return {
            state: "unrevoked" as const,
            reason: /vault expression|not found in vault/i.test(error)
                ? COULD_NOT_RUN
                : "The revoke request did not complete.",
        };
    }
    const code = String(outputs.stdout ?? "").trim();
    if (code === "204") return { state: "revoked" as const };
    if (code === "401") {
        return {
            state: "none" as const,
            reason:
                "GitHub holds no valid setup token: none was minted, or it had expired or been revoked.",
        };
    }
    return {
        state: "unrevoked" as const,
        reason: `GitHub answered HTTP ${
            /^[0-9]{3}$/.test(code) ? code : "an unknown status"
        } to the revoke.`,
    };
}
/** The token outcome for the receipt, from the `deps_revoke` step; no token was asked for when there is no `deps_mint`. */
export function dependencyToken(steps: Record<string, unknown>) {
    if (!("deps_mint" in steps)) {
        return { state: "none" as const, reason: "No setup step ran." };
    }
    return DependencyToken.parse(revokeOutcome(steps, "deps_revoke"));
}
export function sourceResult(raw: unknown, steps: Record<string, unknown>) {
    const intent = Intent.parse(raw);
    const profile = nativeOutputs(steps, "profile").outputs;
    if (typeof profile.error_code === "string") {
        return { error_code: profile.error_code, failed_step: "profile" };
    }
    // A guard skips `composer` when inspect found no regular composer.json; a
    // skipped step has no entry in `steps`.
    // The dependency steps are skipped with the setup steps when the profile failed or the Project has none.
    const optional = new Set(["composer", ...DEPENDENCY_STEPS]);
    for (
        const name of [
            "prepare",
            "directory",
            "control",
            "mint_clone",
            "clone",
            "bundle",
            "prepare_remote",
            "copy_bundle",
            "import",
            "origin",
            "branch",
            "identity",
            "inspect",
            "composer",
            "profile",
            // The private-dependency configuration is written before the setup steps and removed after them.
            ...DEPENDENCY_STEPS,
            ...intent.workspace.setup.map((_, i) => `setup-${i}`),
        ]
    ) {
        if (
            nativeOutputs(steps, name).step.status !== "succeeded" &&
            !(optional.has(name) && !(name in steps))
        ) {
            return { error_code: "tasks.provision_failed", failed_step: name };
        }
    }
    return Observation.parse(nativeOutputs(steps, "profile").outputs);
}
/** The provision receipt: the VM child's receipt, then the first failure, else the source. */
export function result(
    raw: unknown,
    receipt: unknown,
    steps: Record<string, unknown>,
): Record<string, unknown> {
    const intent = Intent.parse(raw);
    const vm = Vm.safeParse(receipt);
    const ready = vm.success && vm.data.task_vm_id === intent.task_vm_id &&
            vm.data.guest_node_id === intent.guest_node_id
        ? vm.data
        : {};
    if (nativeOutputs(steps, "guest").step.status !== "succeeded") {
        return {
            ...ready,
            error_code: "tasks.provision_failed",
            failed_step: "guest",
        };
    }
    const source = sourceResult(intent, steps);
    if (typeof (source as { error_code?: unknown }).error_code === "string") {
        return { ...ready, ...source };
    }
    if (
        intent.proof_club_required &&
        nativeOutputs(steps, "proof_club").step.status !== "succeeded"
    ) {
        return {
            ...ready,
            error_code: "tasks.provision_failed",
            failed_step: "proof_club",
        };
    }
    const { source: observed } = Observation.parse(source);
    return {
        ...ready,
        source: observed,
        dependency_token: dependencyToken(steps),
        instance_id: intent.instance_id,
        node_id: intent.guest_node_id,
        starting_commit: observed.commit,
    };
}
export const behavior = {
    type: "@nckrtl/task-provision-data",
    version: "2026.11.06.1",
    resources: {
        public: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        prepare: {
            description:
                "Validate public workspace intent and build each setup command's SSH exec argv",
            arguments: z.object({ intent: Intent, root: Path }),
            execute: (
                args: { intent: z.infer<typeof Intent>; root: string },
                ctx: Context,
            ) => data(ctx, { setup: setupArgv(args.intent, args.root) }),
        },
        revoke: {
            description:
                "Name what became of the setup token after the revoke step: revoked, none to revoke, or unrevoked with the reason",
            arguments: z.object({ steps: z.record(z.string(), z.unknown()) }),
            execute: (
                args: { steps: Record<string, unknown> },
                ctx: Context,
            ) => data(ctx, revokeOutcome(args.steps)),
        },
        setup_output: {
            description:
                "Read, redact and delete each downloaded setup output file; return its bounded tail",
            arguments: z.object({ intent: Intent, directory: Path }),
            execute: async (
                args: { intent: z.infer<typeof Intent>; directory: string },
                ctx: Context,
            ) => data(ctx, {
                setup: await setupOutput(args.intent, args.directory),
            }),
        },
        guest: {
            description:
                "Use the provision child's trusted observed pin for the admitted workspace",
            arguments: z.object({ intent: Intent, vm: z.unknown() }),
            execute: (
                args: { intent: unknown; vm: unknown },
                ctx: Context,
            ) => data(ctx, { workspace: connection(args.intent, args.vm) }),
        },
        pin: {
            description:
                "Pin the guest targets of the named intents of a run (prep, image refresh) to the host key the VM step observed",
            arguments: z.object({
                receipt: z.union([z.string(), z.record(z.string(), z.unknown())]),
                guest_node_id: z.number().int().positive(),
                intents: z.record(z.string(), z.unknown()),
            }),
            execute: (
                args: {
                    receipt: string | Record<string, unknown>;
                    guest_node_id: number;
                    intents: Record<string, unknown>;
                },
                ctx: Context,
            ) => data(
                ctx,
                pinIntents(args.receipt, args.guest_node_id, args.intents),
            ),
        },
        bind: {
            description:
                "Pin the workspace Route and Process intents to the guest host key the VM step observed",
            arguments: z.object({
                vm: z.unknown(),
                guest_node_id: z.number().int().positive(),
                route: z.unknown(),
                processes: z.array(z.unknown()),
            }),
            execute: (
                args: {
                    vm: unknown;
                    guest_node_id: number;
                    route: unknown;
                    processes: unknown[];
                },
                ctx: Context,
            ) => data(
                ctx,
                bindHostKeys(
                    { route: args.route, processes: args.processes },
                    args.vm,
                    args.guest_node_id,
                ),
            ),
        },
        profile: {
            description:
                "Classify source with canonical Instance profile policy read and delete the downloaded composer.json, keeping only its facts",
            arguments: z.object({
                intent: Intent,
                identity: z.string(),
                files: z.string(),
                composer: Path,
            }),
            execute: async (
                args: {
                    intent: z.infer<typeof Intent>;
                    identity: string;
                    files: string;
                    composer: string;
                },
                ctx: Context,
            ) => {
                const workspace = Intent.parse(args.intent).workspace;
                // The file never reaches history; only the parsed facts below do.
                const composer = await readCapture(
                    args.composer,
                    COMPOSER_BYTES,
                );
                const observation = observe(workspace, {
                    identity: args.identity,
                    files: args.files,
                    composer: composer?.bytes ?? null,
                });
                if (
                    observation.branch !== workspace.branch ||
                    observation.commit !== workspace.start_commit
                ) {
                    throw new Error("instance.source_identity_changed");
                }
                let profile;
                try {
                    profile = workspace.skip_profile
                        ? { php_version: null, laravel: false }
                        : classifyProvisioningProfile(
                            observation.profile,
                            workspace,
                        );
                } catch (error) {
                    if (error instanceof ProvisioningRefusal) {
                        return data(ctx, {
                            error_code: error.code,
                            failed_step: "profile",
                        });
                    }
                    throw error;
                }
                return data(ctx, {
                    source: {
                        branch: observation.branch,
                        commit: observation.commit,
                        ...profile,
                    },
                });
            },
        },
        result: {
            description:
                "Fold the VM receipt and the native source and setup outputs into the Tasks terminal receipt",
            arguments: z.object({
                intent: Intent,
                vm: z.unknown(),
                steps: z.record(z.string(), z.unknown()),
            }),
            execute: (
                args: {
                    intent: unknown;
                    vm: unknown;
                    steps: Record<string, unknown>;
                },
                ctx: Context,
            ) => data(ctx, {
                result_json: JSON.stringify(
                    result(args.intent, args.vm, args.steps),
                ),
            }),
        },
    },
};

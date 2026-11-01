import { z } from "npm:zod@4.4.3";
import { repositoryIdentity } from "../instance-lifecycle/source_identity.ts";
import { classifyProvisioningProfile } from "../instance-lifecycle/source_profile.ts";

const ObjectInput = z.record(z.string(), z.unknown());
const Output = z.record(z.string(), z.unknown());
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const observation = z.object({ stdout: z.string() }).passthrough();
const text = (value: unknown) => observation.parse(value).stdout.trimEnd();
/** Compare public facts only. Native SSH owns every read and effect. */
export function sourceResult(
    kind: string,
    intent: Record<string, unknown>,
    facts: Record<string, unknown>,
) {
    const checkout = z.string().parse(intent.checkout);
    if (
        text(facts.top) !== checkout ||
        text(facts.common) !== `${checkout}/.git`
    ) {
        throw new Error("instance.registration_conflict");
    }
    const trees = text(facts.worktrees).split("\n").filter((s) =>
        s.startsWith("worktree ")
    );
    if (trees.length !== 1 || trees[0] !== `worktree ${checkout}`) {
        throw new Error("instance.registration_conflict");
    }
    if (repositoryIdentity(text(facts.origin)) !== intent.repository_identity) {
        throw new Error("instance.source_identity_invalid");
    }
    const branch = text(facts.branch) || null;
    if (kind === "instance.rename") {
        if (intent.branch !== null && intent.branch !== branch) {
            throw new Error("instance.branch_not_checked_out");
        }
        return { branch };
    }
    const commit = z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/).parse(
        text(facts.commit),
    );
    const inspected = z.object({
        digest: z.string().regex(/^[a-f0-9]{64}$/),
        profile: z.unknown(),
    }).parse(JSON.parse(text(facts.filesystem)));
    const profile = classifyProvisioningProfile(inspected.profile, {
        php_versions: z.array(z.string()).min(1).parse(intent.php_versions),
        project_type: z.string().parse(intent.project_type),
    });
    const origin = text(facts.origin);
    return {
        registered: true,
        branch,
        commit,
        detached: branch === null,
        digest: inspected.digest,
        common: `${checkout}/.git`,
        default_branch:
            text(facts.default).replace(/^refs\/remotes\/origin\//, "") || null,
        inferred_slug: origin.replace(/\/+$/, "").split(/[/:]/).at(-1)!.replace(
            /\.git$/,
            "",
        ).toLowerCase().replace(/[^a-z0-9-]+/g, "-").replace(/^-|-$/g, ""),
        ...profile,
    };
}
export const behavior = {
    type: "@nckrtl/instance-source-command",
    version: "2026.11.01.1",
    resources: {
        assets: {
            schema: z.object({ script_body: z.string() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: { schema: Output, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Resolve one public source script for the official SSH method",
            arguments: z.object({
                action: z.enum([
                    "inspect",
                    "origin",
                    "protect_environment",
                    "prepare_directory",
                    "mark_source",
                    "transfer_probe",
                    "transfer_archive",
                    "transfer_install",
                    "transfer_remove",
                    "profile",
                ]),
                input: ObjectInput,
            }),
            async execute(
                args: { action: string; input: Record<string, unknown> },
                ctx: Context,
            ) {
                const source = await Deno.readTextFile(
                    ctx.extensionFile(`scripts/${args.action}.py`),
                );
                const result = {
                    script_body: source.replace(
                        "SOURCE_INPUT = None",
                        "SOURCE_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(args.input)) + ")",
                    ),
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("assets", "assets", result),
                    ],
                };
            },
        },
        cloneResult: {
            description:
                "Compare candidate and target commits and expose one terminal clone result",
            arguments: z.object({
                intent: ObjectInput,
                source: ObjectInput,
                candidate: z.unknown(),
                environment: z.unknown(),
                vp: z.object({
                    ready: z.literal(true),
                    runtime: z.record(z.string(), z.unknown()).nullable(),
                }),
            }),
            async execute(
                args: {
                    intent: Record<string, unknown>;
                    source: Record<string, unknown>;
                    candidate: unknown;
                    environment: unknown;
                    vp: {
                        ready: true;
                        runtime: Record<string, unknown> | null;
                    };
                },
                ctx: Context,
            ) {
                const candidate = z.object({ commit: z.string() }).parse(
                    args.candidate,
                );
                const source = args.source;
                const admitted = z.object({ branch: z.string() }).parse(
                    args.intent.candidate,
                );
                if (
                    admitted.branch === source.branch &&
                    source.commit !== candidate.commit
                ) throw new Error("instance.clone_candidate_changed");
                const environment = z.object({ keys: z.array(z.string()) })
                    .parse(JSON.parse(text(args.environment)));
                const result = {
                    instance_id: args.intent.instance_id,
                    ready: true,
                    ...source,
                    candidate_commit: candidate.commit,
                    vp_environment: args.vp.runtime,
                    environment_keys: environment.keys,
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", {
                            stdout: JSON.stringify(result),
                            exitCode: 0,
                        }),
                    ],
                };
            },
        },
        cloneCandidate: {
            description:
                "Compare admitted candidate repository, branch and clean committed state",
            arguments: z.object({
                intent: ObjectInput,
                origin: z.unknown(),
                branch: z.unknown(),
                commit: z.unknown(),
                status: z.unknown(),
                submodules: z.unknown(),
            }),
            async execute(
                args: {
                    intent: Record<string, unknown>;
                    origin: unknown;
                    branch: unknown;
                    commit: unknown;
                    status: unknown;
                    submodules: unknown;
                },
                ctx: Context,
            ) {
                if (
                    repositoryIdentity(text(args.origin)) !==
                        args.intent.repository_identity ||
                    text(args.branch) !== args.intent.branch ||
                    text(args.status) !== "" || text(args.submodules) !== ""
                ) throw new Error("instance.clone_candidate_source_invalid");
                const result = {
                    commit: z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/)
                        .parse(text(args.commit)),
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", result),
                    ],
                };
            },
        },
        complete: {
            description:
                "Fail incomplete transfer after its placement result and cleanup are recorded",
            arguments: z.object({
                cutover: z.boolean(),
                outcomes: z.record(
                    z.string(),
                    z.object({ status: z.string() }).passthrough(),
                ),
            }),
            async execute(
                args: {
                    cutover: boolean;
                    outcomes: Record<string, { status: string }>;
                },
                ctx: Context,
            ) {
                if (
                    !args.cutover ||
                    Object.values(args.outcomes).some((s) =>
                        s.status === "failed"
                    )
                ) {
                    throw new Error("instance.transfer_failed");
                }
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", {
                            ready: true,
                        }),
                    ],
                };
            },
        },
        transferResult: {
            description:
                "Expose physical transfer placement before source retirement",
            arguments: z.object({
                cutover: z.boolean(),
                restored: z.boolean(),
                transfer_id: z.string(),
                outcomes: z.record(
                    z.string(),
                    z.object({ status: z.string() }).passthrough(),
                ),
            }),
            async execute(
                args: {
                    cutover: boolean;
                    restored: boolean;
                    transfer_id: string;
                    outcomes: Record<string, { status: string }>;
                },
                ctx: Context,
            ) {
                const failed = Object.values(args.outcomes).some((step) =>
                    step.status === "failed"
                );
                const result = {
                    cutover: args.cutover,
                    restored: args.restored,
                    transfer_id: args.transfer_id,
                    ...((failed || !args.cutover)
                        ? { error_code: "instance.transfer_failed" }
                        : {}),
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", {
                            ...result,
                            stdout: JSON.stringify(result),
                            exitCode: failed ? 1 : 0,
                        }),
                    ],
                };
            },
        },
        transferSource: {
            description:
                "Compare the admitted source repository and Git placement before capture",
            arguments: z.object({
                identity: z.string(),
                origin: z.unknown(),
                top: z.unknown(),
                path: z.string(),
                git_directory: z.unknown(),
                common: z.unknown(),
                filesystem: z.unknown(),
            }),
            async execute(
                args: {
                    identity: string;
                    origin: unknown;
                    top: unknown;
                    path: string;
                    git_directory: unknown;
                    common: unknown;
                    filesystem: unknown;
                },
                ctx: Context,
            ) {
                if (
                    repositoryIdentity(text(args.origin)) !== args.identity ||
                    text(args.top) !== args.path
                ) throw new Error("instance.source_identity_invalid");
                const proof = Output.parse(JSON.parse(text(args.filesystem)));
                const result = {
                    ...proof,
                    git_directory: text(args.git_directory),
                    common: text(args.common),
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", result),
                    ],
                };
            },
        },
        decode: {
            description: "Decode one bounded native JSON observation",
            arguments: z.object({ observation: z.unknown() }),
            async execute(args: { observation: unknown }, ctx: Context) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "result",
                            "result",
                            Output.parse(JSON.parse(text(args.observation))),
                        ),
                    ],
                };
            },
        },
        origin: {
            description:
                "Compare the admitted repository with native Git origin",
            arguments: z.object({
                expected: z.string(),
                observation: z.unknown(),
                allow_empty: z.boolean().default(false),
            }),
            async execute(
                args: {
                    expected: string;
                    observation: unknown;
                    allow_empty?: boolean;
                },
                ctx: Context,
            ) {
                if (
                    !(args.allow_empty && text(args.observation) === "") &&
                    repositoryIdentity(args.expected) !==
                        repositoryIdentity(text(args.observation))
                ) throw new Error("instance.source_identity_invalid");
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", {
                            ready: true,
                        }),
                    ],
                };
            },
        },
        provisioningResult: {
            description:
                "Classify public source profile and combine the selected native branch and commit",
            arguments: z.object({
                intent: ObjectInput,
                profile: z.unknown(),
                branch: z.string(),
                commit: z.unknown(),
            }),
            async execute(
                args: {
                    intent: Record<string, unknown>;
                    profile: unknown;
                    branch: string;
                    commit: unknown;
                },
                ctx: Context,
            ) {
                const result = {
                    branch: args.branch,
                    commit: z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/)
                        .parse(text(args.commit)),
                    ...classifyProvisioningProfile(
                        JSON.parse(text(args.profile)),
                        {
                            php_versions: z.array(z.string()).min(1).parse(
                                args.intent.php_versions,
                            ),
                            project_type: z.string().parse(
                                args.intent.project_type,
                            ),
                        },
                    ),
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", result),
                    ],
                };
            },
        },
        select: {
            description:
                "Choose the admitted branch from native Git refs without running commands",
            arguments: z.object({ resolve: ObjectInput, refs: z.unknown() }),
            async execute(
                args: { resolve: Record<string, unknown>; refs: unknown },
                ctx: Context,
            ) {
                const resolve = args.resolve;
                const refs = text(args.refs).split("\n");
                const branch = z.string().min(1).parse(
                    resolve.branch_override ??
                        (resolve.instance_name === "default"
                            ? resolve.default_branch
                            : resolve.instance_name),
                );
                const remote = `refs/remotes/origin/${branch}`;
                const local = `refs/heads/${branch}`;
                if (
                    resolve.require_branch === true && !refs.includes(remote) &&
                    !refs.includes(local)
                ) {
                    throw new Error("instance.branch_not_found");
                }
                const reference = resolve.seed_commit ??
                    (refs.includes(remote)
                        ? remote
                        : refs.includes(local)
                        ? local
                        : `refs/remotes/origin/${resolve.default_branch}`);
                if (!resolve.seed_commit && !refs.includes(String(reference))) {
                    throw new Error("instance.source_git_failed");
                }
                const result = { branch, reference };
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", result),
                    ],
                };
            },
        },
        paths: {
            description:
                "Resolve shipped Git action assets for official SSH copy",
            arguments: z.object({ intent: ObjectInput }),
            async execute(
                _args: { intent: Record<string, unknown> },
                ctx: Context,
            ) {
                const result = {
                    directory: ctx.extensionFile("scripts/fetch.py").replace(
                        /\/[^/]+$/,
                        "",
                    ),
                };
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", result),
                    ],
                };
            },
        },
        result: {
            description:
                "Compare native public source observations and expose the terminal result",
            arguments: z.object({
                kind: z.enum(["instance.register", "instance.rename"]),
                intent: ObjectInput,
                facts: z.record(z.string(), z.unknown()),
            }),
            async execute(
                args: {
                    kind: string;
                    intent: Record<string, unknown>;
                    facts: Record<string, unknown>;
                },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "result",
                            "result",
                            (() => {
                                const result = sourceResult(
                                    args.kind,
                                    args.intent,
                                    args.facts,
                                );
                                return {
                                    ...result,
                                    stdout: JSON.stringify(result),
                                    exitCode: 0,
                                };
                            })(),
                        ),
                    ],
                };
            },
        },
    },
};

import { z } from "npm:zod@4.4.3";
import { repositoryIdentity } from "../instance-lifecycle/source_identity.ts";
import { classifyProvisioningProfile } from "../instance-lifecycle/source_profile.ts";

const ObjectInput = z.record(z.string(), z.unknown());
const Output = z.record(z.string(), z.unknown());
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const observation = z.object({ stdout: z.string() }).passthrough();
const text = (value: unknown) => observation.parse(value).stdout.trimEnd();
function requestedBranch(resolve: Record<string, unknown>): string {
    return z.string().min(1).parse(
        resolve.branch_override ??
            (resolve.instance_name === "default"
                ? resolve.default_branch
                : resolve.instance_name),
    );
}
export function branchAvailable(
    resolve: Record<string, unknown>,
    checkout: string,
    worktrees: unknown,
) {
    const branch = requestedBranch(resolve);
    const occupied = text(worktrees).split("\n\n").some((entry) => {
        const lines = entry.split("\n");
        return lines.includes("branch refs/heads/" + branch) &&
            !lines.includes("worktree " + checkout);
    });
    return occupied
        ? { available: false, error_code: "instance.branch_in_use" }
        : { available: true };
}

/** The clone profile read: the entry point, the composer.json state, then its content when it is a regular file. */
export function profileRead(stdout: string, intent: Record<string, unknown>) {
    if (intent.skip_profile || intent.project_type === "monorepo") {
        return { kind: "none" };
    }
    const [entry, state, ...rest] = stdout.split("\n");
    if (state === "missing") {
        return { kind: entry === "absent" ? "none" : "partial" };
    }
    const json = rest.join("\n");
    if (state !== "file" || new TextEncoder().encode(json).length > 1048576) {
        return { kind: "unsafe" };
    }
    const source = z.record(z.string(), z.unknown()).parse(JSON.parse(json));
    const kept: Record<string, Record<string, unknown>> = {};
    for (const section of ["require", "require-dev"]) {
        const row = source[section] ?? {};
        if (typeof row !== "object" || row === null || Array.isArray(row)) {
            throw new Error("app-dev.php_version_unsupported");
        }
        kept[section] = Object.fromEntries(
            Object.entries(row).filter(([key]) =>
                ["php", "laravel/framework", "symfony/framework-bundle"]
                    .includes(key)
            ),
        );
    }
    return {
        kind: "composer",
        composer: btoa(JSON.stringify(kept)),
        entry_point: entry === "unsafe"
            ? "unsafe"
            : entry === "regular"
            ? "regular"
            : "absent",
    };
}

/** Rename compares public facts only. Native SSH owns every read and effect. */
export function sourceResult(
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
    // The read step masks embedded credentials; a masked origin is refused, as before.
    if (
        text(facts.origin).includes("REDACTED@") ||
        repositoryIdentity(text(facts.origin)) !== intent.repository_identity
    ) {
        throw new Error("instance.source_identity_invalid");
    }
    const branch = text(facts.branch) || null;
    if (intent.branch !== null && intent.branch !== branch) {
        throw new Error("instance.branch_not_checked_out");
    }
    return { branch };
}
export const behavior = {
    type: "@nckrtl/instance-source-command",
    version: "2026.11.02.3",
    resources: {
        result: { schema: Output, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        cloneResult: {
            description:
                "Compare candidate and target commits and expose one terminal clone result",
            arguments: z.object({
                intent: ObjectInput,
                source: ObjectInput,
                candidate: z.unknown(),
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
                const environmentKeys = z.array(z.string()).parse(
                    args.intent.environment_keys,
                );
                const result = {
                    instance_id: args.intent.instance_id,
                    ready: true,
                    ...source,
                    candidate_commit: candidate.commit,
                    vp_environment: args.vp.runtime,
                    environment_keys: environmentKeys,
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
                        profileRead(text(args.profile), args.intent),
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
        branchAvailable: {
            description:
                "Refuse a requested branch already checked out in another linked worktree before preparing the target",
            arguments: z.object({
                resolve: ObjectInput,
                checkout: z.string(),
                worktrees: z.unknown(),
            }),
            async execute(
                args: {
                    resolve: Record<string, unknown>;
                    checkout: string;
                    worktrees: unknown;
                },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "result",
                            "result",
                            branchAvailable(
                                args.resolve,
                                args.checkout,
                                args.worktrees,
                            ),
                        ),
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
                const branch = requestedBranch(resolve);
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
        result: {
            description:
                "Compare native public source observations and expose the terminal result",
            arguments: z.object({
                kind: z.literal("instance.rename"),
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

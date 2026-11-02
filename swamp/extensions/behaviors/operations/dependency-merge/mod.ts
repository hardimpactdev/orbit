import { z } from "npm:zod@4.4.3";
import {
    packagistRecord,
    record,
    type RecordValue,
    strictJson,
} from "./contents.ts";
import { MergeEvidence } from "./policy.ts";
import { requirePolicy } from "./refusal.ts";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^[a-f0-9]{40}$/);
export const Input = z.object({
    node_id: Id,
    project_id: Id,
    group_id: Id,
    publish_operation_id: Id,
    repository: z.string().regex(/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/),
    default_branch: z.string().min(1),
    smoke_workflow: z.string().min(1),
    pr_number: Id,
    head_sha: Sha,
}).strict();
const Args = z.object({ input: Input, directory: z.string().startsWith("/") });
type Args = z.infer<typeof Args>;
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const save = async (ctx: Context, value: unknown) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});
async function read(
    directory: string,
    name: string,
    code = "dependency_merge.contents_unreadable",
): Promise<unknown> {
    try {
        const path = `${directory}/${name}.json`;
        if ((await Deno.stat(path)).size > 8 * 1024 * 1024) throw new Error();
        return JSON.parse(await Deno.readTextFile(path));
    } catch {
        throw new Error(code);
    }
}
const write = (directory: string, name: string, value: unknown) =>
    Deno.writeTextFile(`${directory}/${name}.json`, JSON.stringify(value), {
        mode: 0o600,
    });
const root = (args: Args) => `/repos/${args.input.repository}`;
async function initial(args: Args) {
    const observations = {
        [`${root(args)}/pulls/${args.input.pr_number}`]: await read(
            args.directory,
            "pr",
            "dependency_merge.pr_changed",
        ),
    };
    const evidence = new MergeEvidence(args.input, observations);
    return { observations, evidence, pr: await evidence.pr() };
}

export async function inspect(args: Args) {
    const { pr } = await initial(args);
    return { merged: pr.merged === true };
}
export async function plan(args: Args) {
    const { observations, pr } = await initial(args);
    if (pr.merged) {
        await write(args.directory, "evidence", observations);
        return { requests: [] };
    }
    const path = root(args), head = args.input.head_sha;
    Object.assign(observations, {
        [path]: await read(
            args.directory,
            "repository",
            "dependency_merge.default_branch_changed",
        ),
        [`${path}/actions/workflows?per_page=100`]: await read(
            args.directory,
            "workflows",
            "dependency_merge.checks_unreadable",
        ),
        [`${path}/actions/runs?head_sha=${head}&event=pull_request&per_page=100`]:
            await read(
                args.directory,
                "runs",
                "dependency_merge.checks_unreadable",
            ),
    });
    const files = await read(
        args.directory,
        "files",
        "dependency_merge.files_unreadable",
    );
    requirePolicy(
        Array.isArray(files) && files.length <= 30,
        "dependency_merge.files_unreadable",
    );
    files.forEach((rows, index) =>
        Object.assign(observations, {
            [
                `${path}/pulls/${args.input.pr_number}/files?per_page=100&page=${
                    index + 1
                }`
            ]: rows,
        })
    );
    // gh follows Link headers. A complete short terminal page proves there is no next row.
    if (Array.isArray(files.at(-1)) && files.at(-1).length < 100) {
        Object.assign(observations, {
            [
                `${path}/pulls/${args.input.pr_number}/files?per_page=100&page=${
                    files.length + 1
                }`
            ]: [],
        });
    }
    const checks = await read(
        args.directory,
        "checks",
        "dependency_merge.checks_unreadable",
    );
    requirePolicy(
        Array.isArray(checks) && checks.length <= 30,
        "dependency_merge.checks_unreadable",
    );
    checks.forEach((rows, index) =>
        Object.assign(observations, {
            [
                `${path}/commits/${head}/check-runs?per_page=100&page=${
                    index + 1
                }`
            ]: rows,
        })
    );
    const evidence = new MergeEvidence(args.input, observations);
    await evidence.readiness(pr);
    await evidence.checks();
    const paths = await evidence.files(pr), requests = new Set<string>();
    for (const file of paths) {
        for (const commit of [head, record(pr.base).sha]) {
            requests.add(
                `${path}/contents/${encodeURIComponent(file)}?ref=${commit}`,
            );
        }
        if (!["composer.json", "package.json"].includes(file)) {
            requests.add(
                `${path}/contents/${
                    file === "composer.lock" ? "composer.json" : "package.json"
                }?ref=${head}`,
            );
        }
    }
    const result = [...requests].map((path, index) => ({
        path: path.slice(1),
        index,
    }));
    await write(args.directory, "evidence", observations);
    await write(args.directory, "content-plan", result);
    return { requests: result };
}
export async function registry(args: Args) {
    const observations = record(await read(args.directory, "evidence"));
    const evidence = new MergeEvidence(args.input, observations),
        pr = await evidence.pr();
    if (pr.merged) {
        await write(args.directory, "registry-plan", []);
        return { requests: [] };
    }
    const requests = z.array(
        z.object({ path: z.string(), index: z.number().int().min(0) }),
    ).parse(await read(args.directory, "content-plan"));
    for (const request of requests) {
        observations[`/${request.path}`] = await read(
            args.directory,
            `content-${request.index}`,
        );
    }
    await write(args.directory, "evidence", observations);
    const names = new Set<string>();
    if ((await evidence.files(pr)).includes("composer.lock")) {
        const lock = record(
            strictJson(
                await evidence.content("composer.lock", args.input.head_sha),
            ),
        );
        for (const key of ["packages", "packages-dev"]) {
            requirePolicy(Array.isArray(lock[key]));
            for (const row of lock[key]) {
                const name = record(row).name;
                requirePolicy(
                    typeof name === "string" &&
                        /^[a-z0-9_.-]+\/[a-z0-9_.-]+$/.test(name),
                );
                names.add(name);
            }
        }
    }
    const result = [...names].map((name, index) => ({
        url: `https://repo.packagist.org/p2/${name}.json`,
        name,
        index,
    }));
    await write(args.directory, "registry-plan", result);
    return { requests: result };
}
export async function validate(args: Args) {
    const observations = record(await read(args.directory, "evidence"));
    const registry = new Map<string, unknown>();
    const requests = z.array(
        z.object({ name: z.string(), index: z.number().int().min(0) }),
    ).parse(await read(args.directory, "registry-plan"));
    for (const request of requests) {
        registry.set(
            request.name,
            await read(
                args.directory,
                `registry-${request.index}`,
                "dependency_merge.packagist_unavailable",
            ),
        );
    }
    const evidence = new MergeEvidence(
        args.input,
        observations,
        async (name, version) =>
            packagistRecord(name, version, registry.get(name)),
    );
    const pr = await evidence.pr();
    if (!pr.merged) await evidence.policy(pr);
    await write(args.directory, "merge-body", {
        sha: args.input.head_sha,
        merge_method: "squash",
    });
    return { approved: true };
}
export async function merged(args: Args & { exit_code: number }) {
    const { pr } = await initial(args);
    let receipt: RecordValue;
    if (pr.merged) {
        receipt = {
            merged: true,
            head_sha: args.input.head_sha,
            merge_sha: pr.merge_commit_sha,
        };
    } else {
        requirePolicy(args.exit_code === 0, "dependency_merge.merge_refused");
        const reply = record(
            await read(
                args.directory,
                "merge",
                "dependency_merge.merge_refused",
            ),
        );
        requirePolicy(
            reply.merged === true && Sha.safeParse(reply.sha).success,
            "dependency_merge.merge_refused",
        );
        receipt = {
            merged: true,
            head_sha: args.input.head_sha,
            merge_sha: reply.sha,
        };
    }
    return { stdout: JSON.stringify(receipt) };
}

/** Typed private observations and policy only. Native steps own all HTTP and cleanup. */
export const behavior = {
    type: "@nckrtl/dependency-merge-data",
    version: "2026.11.02.2",
    resources: {
        public: {
            description: "Public merge plans and receipt",
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        inspect: {
            description: "Read exact PR identity and existing merge",
            arguments: Args,
            execute: async (args: Args, ctx: Context) =>
                save(ctx, await inspect(args)),
        },
        plan: {
            description: "Check public PR metadata and plan exact contents",
            arguments: Args,
            execute: async (args: Args, ctx: Context) =>
                save(ctx, await plan(args)),
        },
        registry: {
            description: "Decode contents and plan trusted registry records",
            arguments: Args,
            execute: async (args: Args, ctx: Context) =>
                save(ctx, await registry(args)),
        },
        validate: {
            description: "Apply dependency-only policy to native observations",
            arguments: Args,
            execute: async (args: Args, ctx: Context) =>
                save(ctx, await validate(args)),
        },
        merged: {
            description: "Keep the exact public merge receipt",
            arguments: Args.extend({ exit_code: z.number().int() }),
            execute: async (args: Args & { exit_code: number }, ctx: Context) =>
                save(ctx, await merged(args)),
        },
    },
};

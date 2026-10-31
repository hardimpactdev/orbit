import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
export const PublishInput = z.object({
    group_id: Id,
    instance_id: Id,
    node_id: Id,
    check: Id,
    checkout_path: z.string().startsWith("/"),
    commit: Sha,
    tree: Sha,
    repository: z.string().regex(/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/),
    base: z.string().min(1),
    branch: z.string().regex(/^task-[1-9][0-9]*$/),
    pull_request: z.object({
        title: z.string().min(1).max(256),
        body: z.string().max(65536),
    }).nullable(),
});
export function prepare(raw: unknown) {
    const args = z.object({ intent: PublishInput, node_id: Id }).parse(raw);
    if (
        args.intent.node_id !== args.node_id ||
        args.intent.branch !== `task-${args.intent.group_id}`
    ) {
        throw new Error("Wrong task publication target");
    }
    return { input_json: JSON.stringify(args.intent) };
}
export function verify(raw: unknown) {
    const { input, value } = z.object({
        input: PublishInput,
        value: z.string(),
    }).parse(raw);
    const observed = z.object({ head: Sha, tree: Sha }).safeParse(
        JSON.parse(value),
    );
    return {
        admitted: observed.success && observed.data.head === input.commit &&
            observed.data.tree === input.tree,
    };
}
export function finish(raw: unknown) {
    const args = z.object({
        input: PublishInput,
        before: z.string(),
        after: z.string(),
        exported: z.string(),
        imported: z.string(),
        lookup: z.string(),
        tip: z.string(),
        push: z.string(),
        pull: z.string(),
        failure: z.boolean(),
    }).parse(raw);
    const read = (value: string): Record<string, unknown> =>
        value ? JSON.parse(value) : {};
    const stages = [args.before, args.tip, args.push, args.after, args.pull]
        .map(read);
    const refused = [
        args.before,
        args.exported,
        args.imported,
        args.tip,
        args.push,
        args.after,
        args.lookup,
        args.pull,
    ].map(read).find((row) => typeof row.error_code === "string");
    const matches = (row: Record<string, unknown>) =>
        row.head === args.input.commit && row.tree === args.input.tree;
    let error_code = refused?.error_code;
    if (
        !error_code &&
        (("head" in stages[0] && !matches(stages[0])) ||
            ("head" in stages[3] && !matches(stages[3])))
    ) {
        error_code = "tasks.head_moved";
    }
    if (
        !error_code &&
        (args.failure ||
            !(stages[1].commit === args.input.commit ||
                stages[2].pushed === true))
    ) error_code = "tasks.publish_failed";
    const number = stages[4].number;
    if (
        !error_code && args.input.pull_request !== null &&
        !(typeof number === "number" && Number.isInteger(number) && number > 0)
    ) error_code = "tasks.publish_failed";
    return {
        result_json: JSON.stringify(
            error_code ? { error_code } : {
                branch: args.input.branch,
                commit: args.input.commit,
                pull_request_number: number ?? null,
                pull_request_url: number
                    ? `https://github.com/${args.input.repository}/pull/${number}`
                    : null,
            },
        ),
    };
}

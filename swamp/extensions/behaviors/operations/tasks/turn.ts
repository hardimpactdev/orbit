import { z } from "npm:zod@4.4.3";

const Sha = z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/);

/** The candidate commit that the local check of a fix made: it passed, and it names the commit and the tree to push. */
export function checked(raw: { result_json: string }) {
    const result = raw.result_json === "" ? {} : z.object({
        outcome: z.string().optional(),
        error_code: z.string().optional(),
        commit: z.string().nullable().optional(),
        tree: z.string().optional(),
    }).passthrough().parse(JSON.parse(raw.result_json));
    const commit = Sha.safeParse(result.commit).success
        ? String(result.commit)
        : "";
    const passed = result.outcome === "passed" &&
        result.error_code === undefined &&
        commit !== "" && Sha.safeParse(result.tree).success;
    return {
        passed,
        outcome: result.outcome ?? "",
        commit: passed ? commit : "",
        tree: passed ? String(result.tree) : "",
        result_json: raw.result_json,
    };
}

type PublicContext = {
    writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
    ): Promise<unknown>;
};
export const turnDataResources = {
    checkedFix: {
        lifetime: "infinite",
        garbageCollection: 10,
        description: "Whether the local check of a fix made a candidate commit",
        schema: z.object({
            passed: z.boolean(),
            outcome: z.string(),
            commit: z.union([Sha, z.literal("")]),
            tree: z.union([Sha, z.literal("")]),
            result_json: z.string(),
        }),
    },
};
export const turnDataMethods = {
    checked: {
        description:
            "Read the candidate commit that the local check of a fix made",
        arguments: z.object({ result_json: z.string() }),
        execute: async (args: { result_json: string }, ctx: PublicContext) => ({
            dataHandles: [
                await ctx.writeResource("checkedFix", "fix", checked(args)),
            ],
        }),
    },
};

import { z } from "npm:zod@4.4.3";

const Classification = z.object({
    required: z.boolean(),
    matched_paths: z.array(z.string()),
});
const Site = z.object({
    instance_id: z.number().int().positive(),
    state: z.enum(["passed", "failed", "unknown"]),
    commit: z.string().optional(),
    base_url: z.string().optional(),
    tests: z.number().int().nonnegative().optional(),
    failures: z.number().int().nonnegative().optional(),
    errors: z.number().int().nonnegative().optional(),
    skipped: z.number().int().nonnegative().optional(),
});
const Summary = z.object({
    state: z.enum(["passed", "failed", "unknown", "skipped"]),
    sites: z.array(Site),
    recovery: z.literal("not_activated"),
});
const RawSite = z.object({
    instance_id: z.number().int().positive(),
    stdout: z.string(),
});
const Paths = z.object({ paths: z.array(z.string()) });
const Results = z.object({ required: z.boolean(), sites: z.array(RawSite) });
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

// Match either side of a rename (hooks supply --no-renames), including deletions.
// Shared execution inputs are deliberately conservative: no leaf-only allowlist.
const risky =
    /^(?:swamp\/)?(?:extensions\/behaviors\/|workflows\/|models\/|bin\/|config\/|vaults\/)|^apps\/gateway\/(?:app\/|config\/|database\/migrations\/|routes\/)|^(?:\.github\/workflows\/|recipes\/|resources\/|packages\/recipes\/|swamp\/\.swamp\.yaml$)/;
export function classify(paths: string[]) {
    const matched_paths = [...new Set(paths.filter((path) => risky.test(path)))]
        .sort();
    return { required: matched_paths.length > 0, matched_paths };
}

export function summarize(args: z.infer<typeof Results>) {
    const sites = args.sites.map(({ instance_id, stdout }) => {
        try {
            const site = Site.parse(JSON.parse(stdout));
            if (site.instance_id !== instance_id) {
                throw new Error("Different Instance");
            }
            return site;
        } catch {
            return { instance_id, state: "unknown" as const };
        }
    });
    const state = !args.required
        ? "skipped"
        : sites.some((site) => site.state === "failed")
        ? "failed"
        : sites.length > 0 && sites.every((site) => site.state === "passed")
        ? "passed"
        : "unknown";
    return Summary.parse({ state, sites, recovery: "not_activated" });
}

/** Pure public data shaping. All operational actions remain inline shell steps. */
export const behavior = {
    type: "@nckrtl/production-smoke-data",
    version: "2026.10.31.1",
    resources: {
        classification: {
            schema: Classification,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        summary: {
            schema: Summary.extend({ json: z.string() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        classify: {
            description: "Classify the admitted changed paths",
            arguments: Paths,
            execute: async (args: z.infer<typeof Paths>, ctx: Context) => {
                const result = classify(args.paths);
                await ctx.writeResource(
                    "classification",
                    "classification",
                    result,
                );
                return result;
            },
        },
        summarize: {
            description:
                "Combine each admitted Instance's public smoke evidence",
            arguments: Results,
            execute: async (args: z.infer<typeof Results>, ctx: Context) => {
                const result = summarize(args);
                const output = { ...result, json: JSON.stringify(result) };
                await ctx.writeResource("summary", "summary", output);
                return output;
            },
        },
    },
};

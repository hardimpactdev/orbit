import { z } from "npm:zod@4.4.3";
import * as ci from "../node/ci_host_spec.ts";
import { clickhouseFiles } from "../analytics/clickhouse_files.ts";
import { convergeResult, ReportInput } from "./converge_result.ts";
import { convergeInput } from "./converge_input.ts";

const Path = z.string().regex(
    /^\/tmp\/orbit-ansible-[0-9a-f-]{36}\/intent\.json$/,
);
const Input = z.object({
    intent: z.record(z.string(), z.unknown()),
    path: Path,
}).strict();
const Output = z.object({
    path: Path,
    assets: z.string().startsWith("/"),
    requirements: z.string(),
    collections: z.string(),
});
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** Swamp expressions cannot encode an object as JSON; large intents exceed env limits. */
export const behavior = {
    type: "@nckrtl/ansible-input",
    version: "2026.11.08.3",
    resources: {
        input: { schema: Output, lifetime: "1d", garbageCollection: 10 },
        output: { schema: z.object({ stdout: z.string() }), lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        convergeInput: {
            description: "Build a private, literal Node converge inventory with recorded SSH pins",
            arguments: ReportInput,
            async execute(raw: z.infer<typeof ReportInput>, ctx: Context) {
                const { folder } = ReportInput.parse(raw);
                await convergeInput(folder, ctx.extensionFile('ansible/build_vars.py'), ctx.extensionFile('extensions/behaviors/operations/ansible-input/converge_tool.ts'));
                return { ready: true };
            },
        },
        convergeResult: {
            description: "Collect bounded Node converge outcomes and remove private inputs on every outcome",
            arguments: ReportInput,
            async execute(raw: z.infer<typeof ReportInput>, ctx: Context) {
                const { folder } = ReportInput.parse(raw);
                let result;
                let cleanupFailed = false;
                try {
                    result = await convergeResult(folder);
                } finally {
                    try { await Deno.remove(folder, { recursive: true }); }
                    catch (error) { if (!(error instanceof Deno.errors.NotFound)) cleanupFailed = true; }
                }
                if (cleanupFailed && !result.failed_phase) {
                    result = { ...result, phases: result.phases.map((phase) => phase.name === 'check' ? { ...phase, status: 'failed' as const } : phase),
                        failed_phase: "check" as const, error_code: "node.converge_cleanup_failed" };
                }
                result = { ...result, cleanup_complete: !cleanupFailed };
                const value = { stdout: JSON.stringify({ outcome: result.failed_phase ? "failed" : "succeeded", ...result }) };
                await ctx.writeResource("output", "output", value);
                return value;
            },
        },
        write: {
            description:
                "Write the admitted intent and locate shipped playbooks",
            arguments: Input,
            async execute(raw: z.infer<typeof Input>, ctx: Context) {
                const args = Input.parse(raw);
                const folder = args.path.slice(0, args.path.lastIndexOf("/"));
                await Deno.mkdir(folder, { recursive: true, mode: 0o700 });
                await Deno.writeTextFile(
                    args.path,
                    JSON.stringify(args.intent),
                    { mode: 0o600 },
                );
                await Deno.writeTextFile(
                    folder + "/plans.json",
                    JSON.stringify({ ci, clickhouse: clickhouseFiles }),
                    { mode: 0o600 },
                );
                const requirementsPath = ctx.extensionFile(
                    "ansible/requirements.txt",
                );
                const assets = requirementsPath.slice(
                    0,
                    requirementsPath.lastIndexOf("/"),
                );
                const requirements = await Deno.readTextFile(
                    ctx.extensionFile("ansible/requirements.txt"),
                );
                const collections = await Deno.readTextFile(
                    ctx.extensionFile("ansible/requirements.yml"),
                );
                const value = Output.parse({
                    path: args.path,
                    assets,
                    requirements,
                    collections,
                });
                await ctx.writeResource("input", "input", value);
                return value;
            },
        },
    },
};

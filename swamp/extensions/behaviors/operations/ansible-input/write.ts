import { z } from "npm:zod@4.4.3";
import * as ci from "../node/ci_host_spec.ts";
import { clickhouseFiles } from "../analytics/clickhouse_files.ts";

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
});
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** Swamp expressions cannot encode an object as JSON; large intents exceed env limits. */
export const behavior = {
    type: "@nckrtl/ansible-input",
    version: "2026.11.08.1",
    resources: {
        input: { schema: Output, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
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
                const value = Output.parse({
                    path: args.path,
                    assets,
                    requirements,
                });
                await ctx.writeResource("input", "input", value);
                return value;
            },
        },
    },
};

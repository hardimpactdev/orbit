import { z } from "npm:zod@4.4.3";
import * as ci from "../node/ci_host_spec.ts";
import { clickhouseFiles } from "../analytics/clickhouse_files.ts";
import { roleAddPlans } from "./role_add_plans.ts";
import { convergeResult, ReportInput } from "./converge_result.ts";
import { fleetRebootResult, FleetRebootResultInput } from "./fleet_reboot_result.ts";
import { convergeInput, phaseCode, roleDigests, rolePhaseCode, roleSkippable } from "./converge_input.ts";

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
// The phases whose digest the Node records, which the workflow skips (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change).
const Phases = z.object({ ready: z.literal(true), unchanged: z.array(z.enum(["agent", "software", "roles", "tools", "network"])) });
// A role Operation's phases: `stdout` carries the digests to the Gateway, which reads only a step's stdout.
const RolePhases = z.object({ ready: z.literal(true), unchanged: z.array(z.enum(roleSkippable)), stdout: z.string() });
const ConvergeInputArgs = ReportInput.extend({ operation_id: z.number().int().positive() }).strict();
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** Swamp expressions cannot encode an object as JSON; large intents exceed env limits. */
export const behavior = {
    type: "@nckrtl/ansible-input",
    version: "2026.11.16.3",
    resources: {
        input: { schema: Output, lifetime: "1d", garbageCollection: 10 },
        phases: { schema: Phases, lifetime: "1d", garbageCollection: 10 },
        rolePhases: { schema: RolePhases, lifetime: "1d", garbageCollection: 10 },
        output: { schema: z.object({ stdout: z.string() }), lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        fleetRebootResult: {
            description: "Record unreachable or cut fleet reboot phases from private Ansible callbacks on the controller",
            arguments: FleetRebootResultInput,
            async execute(raw: z.infer<typeof FleetRebootResultInput>, ctx: Context) {
                try {
                    const value = { stdout: JSON.stringify(await fleetRebootResult(raw)) };
                    await ctx.writeResource("output", "output", value);
                    return value;
                } catch {
                    // Parser and filesystem errors can quote private callback bytes or paths.
                    throw new Error("fleet.reboot_record_failed");
                }
            },
        },
        composeRole: {
            description:
                "Compose role resources from Ansible observations in the private run folder",
            arguments: z.object({
                path: Path,
                phase: z.enum(["tools", "publication"]),
            }).strict(),
            async execute(
                raw: { path: string; phase: "tools" | "publication" },
            ) {
                const path = Path.parse(raw.path);
                const folder = path.slice(0, path.lastIndexOf("/"));
                const intent = z.record(z.string(), z.unknown()).parse(
                    JSON.parse(await Deno.readTextFile(path)),
                );
                const previous = JSON.parse(
                    await Deno.readTextFile(folder + "/composed.json"),
                );
                const plans = JSON.parse(
                    await Deno.readTextFile(folder + "/plans.json"),
                );
                const composed = await roleAddPlans(intent, folder, raw.phase);
                await Deno.writeTextFile(
                    folder + "/composed.json",
                    JSON.stringify({
                        plans: { ...plans, ...previous.plans, ...composed },
                    }),
                    { mode: 0o600 },
                );
                return {};
            },
        },
        convergeInput: {
            description: "Build a private, literal Node converge inventory with recorded SSH pins",
            arguments: ConvergeInputArgs,
            async execute(raw: z.infer<typeof ConvergeInputArgs>, ctx: Context) {
                const { folder, operation_id } = ConvergeInputArgs.parse(raw);
                const builder = ctx.extensionFile('ansible/build_vars.py');
                const helper = ctx.extensionFile('extensions/behaviors/operations/ansible-input/converge_tool.ts');
                const phases = await convergeInput(folder, operation_id, builder, helper, phaseCode(builder, helper));
                const value = Phases.parse({ ready: true, unchanged: phases.unchanged });
                await ctx.writeResource("phases", "phases", value);
                return value;
            },
        },
        roleDigests: {
            description: "Compare a role Operation's phase digests with the Node's record (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change)",
            arguments: ConvergeInputArgs,
            async execute(raw: z.infer<typeof ConvergeInputArgs>, ctx: Context) {
                const { folder, operation_id } = ConvergeInputArgs.parse(raw);
                const builder = ctx.extensionFile('ansible/build_vars.py');
                const helper = ctx.extensionFile('extensions/behaviors/operations/ansible-input/converge_tool.ts');
                const phases = await roleDigests(folder, operation_id, rolePhaseCode(builder, helper));
                const value = RolePhases.parse({ ready: true, unchanged: phases.unchanged, stdout: JSON.stringify({ digests: phases.digests }) });
                await ctx.writeResource("rolePhases", "rolePhases", value);
                return value;
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
                        failed_phase: "check" as const, error_code: "operation.native_failed" };
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
                await Deno.writeTextFile(folder + "/composed.json", "{}", {
                    mode: 0o600,
                });
                await Deno.writeTextFile(folder + "/composed.yml", "{}", {
                    mode: 0o600,
                });
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
                // A clone's create and completion stage their release scripts from the Gateway's one-line copy (CloneStage), not from here.
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

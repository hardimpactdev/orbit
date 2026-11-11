import { z } from "npm:zod@4.4.3";
import { publicTail, readPublicTail } from "../../_lib/public_output.ts";
import { ReportInput } from "./converge_result.ts";

export const FleetRebootResultInput = ReportInput.extend({
    node_id: z.number().int().positive(), position: z.number().int().nonnegative(),
}).strict();
const Report = z.object({
    plays: z.array(z.object({ tasks: z.array(z.object({ hosts: z.record(z.string(), z.object({
        unreachable: z.boolean().optional(), msg: z.string().optional(), _ansible_no_log: z.boolean().optional(),
    })) })) })),
    stats: z.record(z.string(), z.object({ unreachable: z.number().int().nonnegative() })),
});

/** Ansible UNREACHABLE bypasses rescue. Shape its private callback on the controller, before the next Node's halt check. */
export async function fleetRebootResult(input: z.infer<typeof FleetRebootResultInput>) {
    const { folder, node_id: id, position } = FleetRebootResultInput.parse(input);
    const path = `${folder}/result-${String(position).padStart(3, "0")}-${id}.json`;
    const intent = JSON.parse(await Deno.readTextFile(`${folder}/intent.json`));
    const target = z.object({ name: z.string(), defer: z.string().nullable() }).parse(intent.nodes[id]);
    let record: Record<string, unknown>;
    try { record = JSON.parse(await Deno.readTextFile(path)); }
    catch (error) { if (!(error instanceof Deno.errors.NotFound)) throw error; record = {}; }
    let attempted: string | undefined;
    let failure: { step: string; error_code: string; error_detail?: string } | undefined;
    for (const phase of ["drain", "reboot", "check"]) {
        const capture = `${folder}/${phase}-${id}.json`;
        try {
            const text = await Deno.readTextFile(capture);
            attempted = phase;
            // A killed phase can leave a partial callback. It is a cut, never an unvisited Node.
            let report: z.infer<typeof Report>;
            try { report = Report.parse(JSON.parse(text)); }
            catch { failure ??= { step: phase, error_code: "fleet.reboot_cut" }; continue; }
            if (Object.values(report.stats).some((stat) => stat.unreachable > 0)) {
                const messages = report.plays.flatMap((play) => play.tasks.flatMap((task) => Object.values(task.hosts)))
                    .filter((host) => host.unreachable && !host._ansible_no_log).map((host) => host.msg ?? "");
                const detail = publicTail(messages.join("\n"), 4096).output;
                failure ??= { step: phase, error_code: "fleet.node_unreachable", error_detail: detail };
            }
        } catch (error) {
            if (!(error instanceof Deno.errors.NotFound)) throw error;
        } finally {
            // Callback reports and stderr never enter native history. Only the redacted diagnostic survives.
            await Deno.remove(capture).catch((error) => { if (!(error instanceof Deno.errors.NotFound)) throw error; });
            await readPublicTail(`${folder}/${phase}-${id}.stderr`, 4096);
        }
    }
    if (failure?.error_code === "fleet.node_unreachable" || (record.outcome === undefined && attempted)) {
        record = { ...record, node_id: id, name: target.name, reboot_hold: target.defer === "operator_hold", outcome: "failed",
            ...(failure ?? { step: attempted, error_code: "fleet.reboot_cut" }) };
        await Deno.writeTextFile(path, JSON.stringify(record), { mode: 0o600 });
    }
    // Return only bounded public evidence, never a callback object or arbitrary exception text.
    return { node_id: id, outcome: record.outcome, error_code: record.error_code,
        ...(typeof record.error_detail === "string" ? { error_detail: record.error_detail } : {}) };
}

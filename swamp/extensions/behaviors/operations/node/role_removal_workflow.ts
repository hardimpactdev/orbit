import { rolePublicationJobs } from "./role_publication_workflow.ts";
import { roleMetricsJobs } from "./role_metrics_workflow.ts";
import { RemovalStage } from "./role_removal_local.ts";
type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
const args = Object.fromEntries([
    ...["operation_id", "node_id", "kind"].map(
        (key) => [key, expr(`inputs.${key}`)],
    ),
    ["run_id", expr("run.id")],
]);
const dep = (job: string, type = "succeeded") => ({ job, condition: { type } });
const task = (methodName: string, extra: Fields = {}) => ({
    type: "behavior",
    behaviorType: "@nckrtl/node-role-removal",
    behaviorName: expr('"node-role-removal-" + string(inputs.node_id)'),
    methodName,
    inputs: { ...args, ...extra },
});
/** One source chain shared by standalone removal and post-target relocation cleanup. */
export function roleRemovalJobs(afterJob: string) {
    const jobs: Fields[] = [];
    const add = (
        name: string,
        previous: string,
        method: string,
        extra: Fields = {},
        placement: Fields = {},
    ) => {
        jobs.push({
            name: name + "_job",
            dependsOn: [dep(previous)],
            steps: [{
                name,
                writes: true,
                ...placement,
                task: {
                    ...task(method, extra),
                    ...(placement.forEach
                        ? {
                            behaviorName: expr(
                                '"cert-remove-" + string(self.participant)',
                            ),
                        }
                        : {}),
                },
            }],
        });
        return name + "_job";
    };
    let last = add("source_inventory", afterJob, "inventory");
    last = add("source_withdrawal", last, "withdraw");
    const dns = rolePublicationJobs(last, {
        prefix: "source_dns",
        stages: ["dns"],
    });
    jobs.push(...dns.jobs);
    last = dns.lastJob;
    last = add("source_grace", last, "grace");
    const publication = rolePublicationJobs(last, {
        prefix: "source_publication",
        stages: ["php", "caddy", "firewall"],
    });
    jobs.push(...publication.jobs);
    last = publication.lastJob;
    for (const stage of RemovalStage.options) {
        const certificates = stage === "certificates";
        const node = certificates
            ? "self.participant"
            : "steps.source_inventory.outputs.node_id";
        last = add("source_" + stage.replaceAll("-", "_"), last, "stage", {
            stage,
            target_node_id: expr(node),
        }, {
            labels: { node: expr("string(" + node + ")") },
            ...(certificates
                ? {
                    forEach: {
                        item: "participant",
                        in: expr(
                            "steps.source_inventory.outputs.certificate_nodes",
                        ),
                    },
                }
                : {}),
        });
    }
    last = add("source_complete", last, "complete");
    return {
        jobs,
        lastJob: last,
        result:
            "has(steps.source_complete) && has(steps.source_complete.outputs) ? steps.source_complete.outputs : null",
    };
}
/** Standalone removal uses the same rule-22 resource graph as Node removal. */
export { standaloneRoleRemovalWorkflow as roleRemovalWorkflow } from "../node-removal-command/workflows.ts";

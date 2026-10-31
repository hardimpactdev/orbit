type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
/** One same-parent child; the supplied globals retain the admitted identity and host pins. */
export function roleCertificateSteps(
    name: string,
    leafExpr: string,
    fullGlobals: Fields,
    dependency?: string,
    guard?: string,
): Fields[] {
    return [{
        name,
        target: "orbit-node-209",
        labels: { node: "209" },
        ...(dependency
            ? {
                dependsOn: [{
                    step: dependency,
                    condition: { type: "succeeded" },
                }],
            }
            : {}),
        ...(guard
            ? { guard: guard.startsWith("${{") ? guard : expr(guard) }
            : {}),
        task: {
            type: "workflow",
            workflowIdOrName: "node-role-certificate-publish",
            inputs: {
                operation_id: expr("inputs.operation_id"),
                node_id: expr("inputs.node_id"),
                kind: expr("inputs.kind"),
                parent_run_id: expr("run.id"),
                leaf: expr(leafExpr),
                ssh: fullGlobals,
            },
        },
    }];
}

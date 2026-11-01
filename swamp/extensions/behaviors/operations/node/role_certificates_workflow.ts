/** Shared same-root CSR -> control signer -> fixed-pair publication jobs. */
type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
const args = Object.fromEntries([
    ...["operation_id", "node_id", "kind"].map((
        key,
    ) => [key, expr(`inputs.${key}`)]),
    ["run_id", expr("run.id")],
]);
const dependency = (job: string) => ({
    job,
    condition: { type: "succeeded" },
});

/** The canonical visible HTTP script requests public signatures; CA custody stays in Gateway. */
export function roleCertificateJobs(
    afterJob: string,
    options: { prefix?: string; blocked?: string } = {},
) {
    const prefix = options.prefix ?? "role_certificates";
    if (!/^[a-z][a-z0-9_]*$/.test(prefix)) {
        throw new Error("Invalid role certificate job prefix");
    }
    const guard = options.blocked ? { guard: expr(options.blocked) } : {};
    const inventory = `${prefix}_inventory`;
    const publish = `${prefix}_publish_`;
    const record = `${prefix}_record_`;
    const complete = `${prefix}_complete`;
    const plan = `steps.${inventory}.outputs`;
    const leaves =
        `has(steps.${inventory}) && has(${plan}) ? ${plan}.leaves : []`;
    const nodes =
        `has(steps.${inventory}) && has(${plan}) ? ${plan}.node_ids : []`;
    const key = (leaf: string) =>
        `string(${leaf}.node_id) + "_" + ${leaf}.scope`;
    const rootTask = (methodName: string, extra: Fields = {}) => ({
        type: "behavior",
        behaviorType: "@nckrtl/node-role-certificates",
        behaviorName: expr(
            methodName === "record"
                ? '"cert-record-" + run.id + "-" + string(self._index)'
                : '"role-cert-" + run.id',
        ),
        methodName,
        inputs: { ...args, ...extra },
    });
    const connection =
        `${plan}.connections.filter(c, c.node_id == self.leaf.node_id)[0].ssh`;
    const ssh = {
        name: expr('"role-certificate-" + run.id + "-" + string(self._index)'),
        transport: {
            kind: "ssh",
            user: expr(connection + ".target.user"),
            port: expr(connection + ".target.port"),
            identityFile: expr(connection + ".identity_file"),
        },
        hosts: [{
            name: "target",
            address: expr(connection + ".target.host"),
            transport: { hostKeys: expr(connection + ".target.host_keys") },
        }],
    };
    const jobs: Fields[] = [{
        name: `${inventory}_job`,
        dependsOn: [dependency(afterJob)],
        steps: [{
            name: inventory,
            writes: true,
            ...guard,
            task: rootTask("inventory"),
        }],
    }, {
        name: `${prefix}_publish_job`,
        dependsOn: [dependency(`${inventory}_job`)],
        steps: [{
            name: publish + expr(key("self.leaf")),
            target: "orbit-node-209",
            forEach: { item: "leaf", in: expr(leaves) },
            ...guard,
            task: {
                type: "workflow",
                workflowIdOrName: "node-role-certificate-publish",
                inputs: {
                    operation_id: expr("inputs.operation_id"),
                    node_id: expr("inputs.node_id"),
                    kind: expr("inputs.kind"),
                    parent_run_id: expr("run.id"),
                    leaf: expr("self.leaf"),
                    ssh,
                },
            },
        }],
    }, {
        name: `${prefix}_record_job`,
        dependsOn: [dependency(`${prefix}_publish_job`)],
        steps: [{
            name: record + expr("string(self.participant)"),
            writes: true,
            target: expr('"orbit-node-" + string(inputs.node_id)'),

            forEach: { item: "participant", in: expr(nodes) },
            ...guard,
            task: rootTask("record", {
                target_node_id: expr("self.participant"),
            }),
        }],
    }, {
        name: `${complete}_job`,
        dependsOn: [dependency(`${prefix}_record_job`)],
        steps: [{
            name: complete,
            writes: true,
            ...guard,
            task: rootTask("complete"),
        }],
    }];
    return {
        jobs,
        lastJob: `${complete}_job`,
        result:
            `has(steps.${complete}) && has(steps.${complete}.outputs) ? steps.${complete}.outputs : null`,
    };
}

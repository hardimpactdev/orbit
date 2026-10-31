type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
const coordinator = { target: "orbit-node-209", labels: { node: "209" } };
const completed = {
    type: "or",
    conditions: [{ type: "succeeded" }, { type: "skipped" }],
};
/** Compose native children under the real parent Operation, without callbacks or effects in TS. */
export function routeMutationSteps(
    name: string,
    intent: string,
    mode: "forward" | "restore",
    guard?: string,
): Fields[] {
    const side = `${intent}.${mode}`;
    const predicate = guard?.replace(/^\$\{\{\s*|\s*\}\}$/g, "");
    const selected = (list: string) =>
        expr(predicate ? `(${predicate}) ? ${list} : []` : list);
    const common = {
        operation_id: expr("inputs.operation_id"),
        node_id: expr("inputs.node_id"),
        kind: expr("inputs.kind"),
    };
    const steps: Fields[] = [], required: Fields = {};
    function child(
        suffix: string,
        workflow: string,
        list: string,
        childIntent: Fields | string,
        secrets: Fields | string = {},
        extra: Fields = {},
    ) {
        const childName = `${name}_${suffix}`;
        steps.push({
            name: childName,
            ...coordinator,
            forEach: { item: "part", in: selected(list) },
            dependsOn: steps.map((step) => ({
                step: step.name,
                condition: completed,
            })),
            task: {
                type: "workflow",
                workflowIdOrName: workflow,
                inputs: {
                    ...common,
                    intent: childIntent,
                    execution_secrets: secrets,
                    ...extra,
                },
            },
        });
        required[childName] = expr(`size(${list})`);
    }
    const pubs = `${side}.publications.filter(p,p.serves_routes)`;
    function certificates(suffix: string, list: string, remove = false) {
        child(
            suffix,
            remove ? "route-certificate-remove" : "route-certificate-publish",
            list,
            expr("self.part"),
            {},
            { parent_run_id: expr("run.id"), restoring: mode === "restore" },
        );
    }
    function caddy(
        suffix: string,
        from: string,
        to: string,
        retainRemoved = false,
    ) {
        child(suffix, "operation-route-caddy-delta", pubs, {
            node_id: expr("self.part.node_id"),
            user: expr("self.part.user"),
            control_node_id: expr("self.part.control_node_id"),
            connection: expr("self.part.connection"),
            before: expr(`self.part.${from}.caddy`),
            after: expr(`self.part.${to}.caddy`),
            direction: "forward",
            retain_removed: retainRemoved,
            alternatives: expr(
                "[self.part.before.caddy,self.part.prepared.caddy,self.part.withdrawn.caddy,self.part.after.caddy]",
            ),
        });
    }
    function dns(suffix: string, stage: string) {
        child(
            suffix,
            "route-dns-publish",
            `${side}.publications.filter(p,p.${stage}.dns != null)`,
            {
                node_id: expr("self.part.node_id"),
                control_node_id: expr("self.part.control_node_id"),
                connection: expr("self.part.connection"),
                host_keys: expr("self.part.host_keys"),
                before: expr("self.part.before.dns"),
                after: expr(`self.part.${stage}.dns`),
                alternatives: expr(
                    "[self.part.before.dns,self.part.prepared.dns,self.part.withdrawn.dns,self.part.after.dns]",
                ),
            },
        );
    }
    function grace() {
        child(
            "dns_grace",
            "route-dns-grace",
            `${side}.publications.exists(p,p.before.dns != null && p.before.dns.digest != p.after.dns.digest) ? [{}] : []`,
            {},
        );
    }
    certificates(
        "prepare_certificates",
        `${side}.${
            mode === "forward" ? "prepare_certificates" : "certificates"
        }`,
    );
    child("php", "operation-route-php-delta", pubs, {
        node_id: expr("self.part.node_id"),
        user: expr("self.part.user"),
        control_node_id: expr("self.part.control_node_id"),
        connection: expr("self.part.connection"),
        before: expr("self.part.before.php"),
        after: expr("self.part.after.php"),
        direction: mode,
    });
    child("lan", "route-lan-apply", `${side}.lan`, expr("self.part"));
    if (mode === "forward") caddy("prepare_caddy", "before", "prepared");
    child(
        "urls",
        "route-environment-write",
        `${side}.url_writes`,
        expr("self.part"),
        { material: expr("inputs.execution_secrets[self.part.secret_field]") },
    );
    if (mode === "forward") {
        dns("prepare_dns", "prepared");
        grace();
        caddy("withdraw_caddy", "prepared", "withdrawn");
        certificates("live_certificates", `${side}.certificates`);
        caddy("live_caddy", "withdrawn", "after");
        dns("dns", "after");
    } else {
        caddy("restore_prepare_caddy", "after", "before", true);
        dns("dns", "before");
        grace();
        caddy("restore_caddy", "after", "before");
    }
    certificates("cleanup", `${side}.cleanup`, true);
    child(
        "retire_lan",
        "route-lan-apply",
        `${side}.retire_lan`,
        expr("self.part"),
    );
    steps.push({
        name,
        ...coordinator,
        forEach: { item: "stage", in: selected(`[${side}]`) },
        dependsOn: steps.map((step) => ({
            step: step.name,
            condition: completed,
        })),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/route-mutation-command",
            behaviorName: `${name}-result`,
            methodName: "result",
            inputs: {
                stage: expr("self.stage"),
                mode,
                required,
                outcomes: expr("steps"),
            },
        },
    });
    return steps;
}

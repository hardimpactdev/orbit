import { nodeCertificateGroups } from "../../certificates/batch_steps.ts";
type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
const coordinator = { target: "orbit-node-209" };
const completed = { type: "always" };
/** Compose native children under the real parent Operation, without callbacks or effects in TS. */
export function routeMutationSteps(
    name: string,
    intent: string,
    mode: "forward" | "restore",
    guard?: string,
    parentRunId = "run.id",
    ingress = false,
    withdrawal = false,
): Fields[] {
    const side = `${intent}.${mode}`;
    const predicate = guard?.replace(/^\$\{\{\s*|\s*\}\}$/g, "");
    const selected = (list: string) => expr(list);
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
            guard: expr(
                `${
                    predicate
                        ? `!(${predicate}) || `
                        : ""
                }steps.exists(key, key.startsWith("${name}_") && steps[key].status == "failed")`,
            ),
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
    function targetIntent(payload: string) {
        const publication =
            `${side}.publications.filter(p,p.node_id == self.part.node_id)[0]`;
        return {
            [payload]: expr("self.part"),
            control_node_id: 209,
            connection: expr(`${publication}.connection`),
            host_keys: expr(`${publication}.host_keys`),
        };
    }
    function certificates(suffix: string, list: string, remove = false) {
        // Select the first immutable leaf per Node; retain missing-publication
        // failures instead of silently filtering foreign leaves out of the plan.
        const nodes = nodeCertificateGroups(list);
        child(
            suffix,
            remove ? "route-certificate-remove" : "route-certificate-publish",
            remove ? list : nodes,
            remove ? targetIntent("leaf") : {
                ...targetIntent("leaves"),
                node_id: expr("self.part.node_id"),
                leaves: expr(
                    `${list}.filter(leaf,leaf.node_id == self.part.node_id)`,
                ),
            },
            {},
            { parent_run_id: expr(parentRunId), restoring: mode === "restore" },
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
            host_keys: expr("self.part.host_keys"),
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
    function php() {
        child("php", "operation-route-php-delta", pubs, {
            node_id: expr("self.part.node_id"),
            user: expr("self.part.user"),
            host_keys: expr("self.part.host_keys"),
            control_node_id: expr("self.part.control_node_id"),
            connection: expr("self.part.connection"),
            before: expr("self.part.before.php"),
            after: expr("self.part.after.php"),
            direction: mode,
        });
    }
    if (!withdrawal) php();
    child("lan", "route-lan-apply", `${side}.lan`, targetIntent("rule"));
    if (ingress) {
        child(
            "ingress",
            "route-ingress-apply",
            `${side}.ingress`,
            targetIntent("rule"),
        );
    }
    if (mode === "forward" && !withdrawal) {
        caddy("prepare_caddy", "before", "prepared");
    }
    function urls() {
        child(
            "urls",
            "route-environment-write",
            `${side}.url_writes`,
            targetIntent("write"),
            {
                material: expr(
                    "inputs.execution_secrets[self.part.secret_field]",
                ),
            },
        );
    }
    if (!withdrawal) urls();
    if (withdrawal) {
        dns("dns", "after");
        grace();
        caddy("withdraw_caddy", "before", "after");
        php();
        urls();
    } else if (mode === "forward") {
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
        targetIntent("rule"),
    );
    if (ingress) {
        child(
            "retire_ingress",
            "route-ingress-apply",
            `${side}.retire_ingress`,
            targetIntent("rule"),
        );
    }
    steps.push({
        name,
        ...coordinator,
        forEach: { item: "stage", in: selected(`[${side}]`) },
        ...(predicate ? { guard: expr(`!(${predicate})`) } : {}),
        dependsOn: steps.map((step) => ({
            step: step.name,
            condition: completed,
        })),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/route-mutation-command",
            behaviorName: expr(
                `"${name}-result-" + run.id + "-" + string(self._index)`,
            ),
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

type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };

/** Publish only the duties captured by NativeNodeRolePublicationInput. */
export function rolePublicationSteps(
    name: string,
    intent: string,
    mode: "forward" | "restore",
    dependency?: string,
    active?: string,
): Fields[] {
    const publications = intent + ".publications";
    const steps: Fields[] = [];
    const required: Fields = {};
    const failed = () => {
        const names = [
            ...(dependency && mode === "forward" ? [dependency] : []),
            ...steps.map((step) => String(step.name)),
        ];
        return names.length
            ? names.map((name) =>
                `steps.exists(key, (key == ${
                    JSON.stringify(name)
                } || key.startsWith(${
                    JSON.stringify(name + "-")
                })) && steps[key].status in ["failed", "cancelled"])`
            ).join(" || ")
            : "false";
    };
    const common = {
        operation_id: expr("inputs.operation_id"),
        node_id: expr("inputs.node_id"),
        kind: expr("inputs.kind"),
        execution_secrets: {},
    };
    const connection = {
        node_id: expr("self.part.node_id"),
        control_node_id: expr("self.part.control_node_id"),
        connection: expr("self.part.connection"),
        host_keys: expr("self.part.host_keys"),
    };
    function child(
        suffix: string,
        workflow: string,
        list: string,
        childIntent: Fields,
    ) {
        const childName = name + "_" + suffix;
        const predecessors = [
            ...(dependency ? [dependency] : []),
            ...steps.map((step) => String(step.name)),
        ];
        steps.push({
            name: childName,
            ...placement,
            forEach: { item: "part", in: expr(list) },
            dependsOn: predecessors.map((step) => ({
                step,
                condition: { type: "always" },
            })),
            // Native guard is skip-on-true. Empty duties do not block later duties.
            guard: expr(
                (active ? `!(${active}) || ` : "") + "(" + failed() + ")",
            ),
            task: {
                type: "workflow",
                workflowIdOrName: workflow,
                inputs: { ...common, intent: childIntent },
            },
        });
        required[childName] = expr(`size(${list})`);
    }
    function caddy(suffix: string, restoring = false, retainRemoved = false) {
        child(
            suffix,
            "operation-route-caddy-delta",
            publications + ".filter(p, p.after.caddy != null)",
            {
                ...connection,
                user: expr("self.part.connection.nodeUser"),
                before: expr(
                    "self.part." + (restoring ? "after" : "before") + ".caddy",
                ),
                after: expr(
                    "self.part." + (restoring ? "before" : "after") + ".caddy",
                ),
                alternatives: expr(
                    "[self.part.before.caddy, self.part.after.caddy]",
                ),
                direction: "forward",
                retain_removed: retainRemoved,
            },
        );
    }
    child(
        "php",
        "operation-route-php-delta",
        publications + ".filter(p, p.after.php != null)",
        {
            ...connection,
            user: expr("self.part.connection.nodeUser"),
            before: expr("self.part.before.php"),
            after: expr("self.part.after.php"),
            direction: mode,
        },
    );
    caddy(
        mode === "restore" ? "prepare_caddy" : "caddy",
        mode === "restore",
        mode === "restore",
    );
    child(
        "dns",
        "route-dns-publish",
        publications + ".filter(p, p.after.dns != null)",
        {
            ...connection,
            before: expr(
                "self.part." + (mode === "restore" ? "after" : "before") +
                    ".dns",
            ),
            after: expr(
                "self.part." + (mode === "restore" ? "before" : "after") +
                    ".dns",
            ),
            alternatives: expr("[self.part.before.dns, self.part.after.dns]"),
        },
    );
    if (mode === "restore") {
        child(
            "dns_grace",
            "route-dns-grace",
            publications +
                ".exists(p, p.after.dns != null && p.before.dns.digest != p.after.dns.digest) ? [{}] : []",
            {},
        );
        caddy("caddy", true);
    }
    steps.push({
        name,
        ...placement,
        forEach: { item: "contribution", in: expr("[{}]") },
        ...(active ? { guard: expr(`!(${active})`) } : {}),
        dependsOn: [
            ...(dependency ? [dependency] : []),
            ...steps.map((step) => String(step.name)),
        ].map((step) => ({ step, condition: { type: "always" } })),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/route-mutation-command",
            behaviorName: name + "-contribution",
            methodName: "result",
            inputs: {
                stage: { routes: [] },
                mode,
                required,
                outcomes: expr("steps"),
            },
        },
    });
    return steps;
}

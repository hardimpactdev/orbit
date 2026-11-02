type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const placement = { labels: { role: "swamp" } };

/** Native per-rule actions use canonical arguments and UFW idempotence. */
export function roleFirewallSteps(
    name: string,
    intent: string,
    fullGlobals: Fields,
    dependency: string,
): Fields[] {
    const expand = name + "_expand", prune = name + "_prune";
    const selected = intent + '.role == "ingress"';
    const rules = (field: string) =>
        expr(selected + " ? " + intent + ".firewall." + field + " : []");
    const command = (step: string, field: string, prior: string): Fields => {
        const identity = expr(
            JSON.stringify(step + "-") + '+run.id+"-"+string(self._index)',
        );
        return {
            name: step,
            ...placement,
            writes: true,
            forEach: { item: "rule", in: rules(field) },
            dependsOn: [{ step: prior, condition: { type: "succeeded" } }],
            task: {
                type: "behavior",
                behaviorType: "@swamp/ssh",
                behaviorName: identity,
                methodName: "exec",
                globalArgs: { ...fullGlobals, name: identity },
                inputs: {
                    hosts: "name:target",
                    sudo: true,
                    argv: expr("self.rule"),
                },
            },
        };
    };
    return [
        command(expand, "desired", dependency),
        command(prune, "remove", expand),
        {
            name,
            ...placement,
            forEach: {
                item: "firewall",
                in: expr(selected + " ? [" + intent + ".firewall] : []"),
            },
            dependsOn: [expand, prune].map((step) => ({
                step,
                condition: { type: "always" },
            })),
            task: {
                type: "assert",
                expr: ["expand", "prune"].map((stage, i) => {
                    const prefix = JSON.stringify(name + "_" + stage + "-");
                    const count = "self.firewall." +
                        (i === 0 ? "desired" : "remove") + ".size()";
                    return `steps.filter(k,k.startsWith(${prefix})).size() == ${count} && steps.all(k,!k.startsWith(${prefix}) || steps[k].status == "succeeded")`;
                }).join(" && "),
                severity: "high",
                message: "Every admitted firewall action must succeed",
            },
        },
    ];
}

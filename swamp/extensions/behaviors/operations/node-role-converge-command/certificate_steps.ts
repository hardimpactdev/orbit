import { roleCertificateSteps } from "../certificates/role_steps.ts";

type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const placement = { target: "orbit-node-209", labels: { node: "209" } };

/** Compose the canonical certificate child directly in the original role root. */
export function roleCertificatesSteps(
    name: string,
    intent: string,
    dependency?: string,
): Fields[] {
    const target = intent + ".targets[string(self.leaf.node_id)]";
    const children = name + "_leaf";
    const steps = roleCertificateSteps(children, "self.leaf", {
        name: expr(
            JSON.stringify(name + "-ssh-") + " + string(self.leaf.node_id)",
        ),
        transport: {
            kind: "ssh",
            user: expr(target + ".target.user"),
            port: expr(target + ".target.port"),
            identityFile: expr(target + ".identity_file"),
            hostKeys: expr(target + ".target.host_keys"),
        },
        hosts: [{ name: "target", address: expr(target + ".target.host") }],
    }, dependency);
    steps[0].forEach = {
        item: "leaf",
        in: expr(intent + ".certificates.leaves"),
    };
    return [...steps, {
        name,
        ...placement,
        // A singleton expansion retains ordering when the leaf fan-out is empty.
        forEach: { item: "contribution", in: expr("[{}]") },
        dependsOn: [
            ...(dependency ? [dependency] : []),
            children,
        ].map((step) => ({ step, condition: { type: "always" } })),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-converge-result",
            behaviorName: name,
            methodName: "certificates",
            inputs: {
                leaves: expr(intent + ".certificates.leaves"),
                prefix: children,
                outcomes: expr("steps"),
            },
        },
    }];
}

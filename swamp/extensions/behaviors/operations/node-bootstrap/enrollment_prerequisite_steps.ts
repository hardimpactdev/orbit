import { bootstrapCommandSteps } from "./command_steps.ts";
import { selectEnrollmentSteps } from "./composition.ts";
import { roleToolSteps } from "./role_tool_steps.ts";
import { roleBaselineSteps } from "../node-role-converge-command/steps.ts";
import { roleFirewallSteps } from "../node-role-converge-command/firewall_steps.ts";

type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";
const output = (name: string) =>
    `steps[${JSON.stringify(name + "-0")}].outputs`;

/** The prior admission job supplies intent; first contact supplies the full pin. */
export function enrollmentSshGlobals(
    name: string,
    intent: string,
    bootstrapName: string,
    bootstrapLogin = false,
    privateAddress = false,
): Fields {
    return {
        name: expr(JSON.stringify(name) + ' + "-" + run.id'),
        transport: {
            kind: "ssh",
            identityFile: expr(intent + ".ssh_identity.identity_file"),
            user: expr(
                intent +
                    (bootstrapLogin
                        ? ".bootstrap.target.user"
                        : ".candidate.user"),
            ),
            port: privateAddress ? 22 : expr(intent + ".bootstrap.target.port"),
        },
        hosts: [{
            name: "target",
            address: expr(
                intent +
                    (privateAddress
                        ? ".candidate.wireguard_ip"
                        : ".bootstrap.target.host"),
            ),
            transport: {
                hostKeys: expr(
                    intent + '.mode == "tld_only" ? ' + intent +
                        ".prior_ssh.target.host_keys : " +
                        output(bootstrapName + "_pin") + ".host_keys",
                ),
            },
        }],
    };
}

/** Bootstrap account/key, recipe tools, sudo/observer, optional storage and WG trust. */
export function enrollmentPrerequisiteSteps(
    name: string,
    intent: string,
    observerHashExpr: string,
): Fields[] {
    const bootstrap = name + "_bootstrap", tools = name + "_tools";
    const active = intent + '.mode != "tld_only"';
    const linux = intent + '.mode == "linux"';
    const toolGlobals = enrollmentSshGlobals(tools, intent, bootstrap, true);
    const globals = enrollmentSshGlobals(name + "_managed", intent, bootstrap);
    const steps = selectEnrollmentSteps(
        bootstrapCommandSteps(
            bootstrap,
            intent + ".bootstrap",
            intent + ".ssh_identity.identity_file",
            undefined,
            undefined,
            tools,
            observerHashExpr,
        ),
        active,
    );
    steps.push(...selectEnrollmentSteps(
        roleToolSteps(
            tools,
            intent + ".tools",
            toolGlobals,
            bootstrap + "_authorized_key",
            undefined,
            {
                sudoExpr: intent + '.bootstrap.target.user != "root"',
            },
        ),
        linux,
    ));
    const storage = name + "_storage", prepared = storage + "_input";
    const storageSelection = linux + " && " + intent +
        ".settings.storage != null";
    steps.push(...selectEnrollmentSteps([
        {
            name: prepared,
            target: "orbit-node-209",
            dependsOn: [{
                step: bootstrap + "_ready",
                condition: { type: "succeeded" },
            }],
            task: {
                type: "behavior",
                behaviorType: "@nckrtl/node-enrollment-settings",
                behaviorName: prepared,
                methodName: "read",
                inputs: {
                    storage: expr(intent + ".settings.storage"),
                    account: expr(output(bootstrap + "_ready") + ".account"),
                },
            },
        },
        ...roleBaselineSteps(
            storage,
            "storage",
            {
                root: expr(`steps[${JSON.stringify(prepared)}].outputs.root`),
                user: expr(`steps[${JSON.stringify(prepared)}].outputs.user`),
                group: expr(`steps[${JSON.stringify(prepared)}].outputs.group`),
                create_missing: expr(
                    `steps[${JSON.stringify(prepared)}].outputs.create_missing`,
                ),
            },
            globals,
            prepared,
        ),
    ], storageSelection));
    const publicSsh = name + "_public_ssh", trust = name + "_trust";
    steps.push(...selectEnrollmentSteps(
        roleFirewallSteps(
            publicSsh,
            intent + ".bootstrap_firewall",
            globals,
            bootstrap + "_ready",
        ),
        linux,
    ));
    steps.push(...selectEnrollmentSteps(
        roleFirewallSteps(trust, intent + ".network.trust", globals, publicSsh),
        linux,
    ));
    const required = [
        bootstrap + "_ready",
        tools,
        prepared,
        storage,
        publicSsh,
        trust,
    ];
    const succeeded = (step: string) =>
        `steps[${JSON.stringify(step + "-0")}].status == "succeeded"`;
    steps.push({
        name,
        target: "orbit-node-209",
        dependsOn: required.map((step) => ({
            step,
            condition: { type: "always" },
        })),
        task: {
            type: "assert",
            expr:
                `(${intent}.mode == "tld_only" || ${
                    succeeded(bootstrap + "_ready")
                }) && ` +
                `(${intent}.mode != "linux" || (${succeeded(tools)} && ${
                    succeeded(publicSsh)
                } && ${succeeded(trust)} && ` +
                `${output(tools)}.receipt.ready && ${
                    output(tools)
                }.receipt.node_id == ${intent}.node_id && ` +
                `(${intent}.settings.storage == null || (${
                    succeeded(prepared)
                } && ${succeeded(storage)}))))`,
            message:
                "Enrollment requires its exact bootstrap, tools, storage and WireGuard trust contributions",
            severity: "high",
        },
    });
    // These lower factories select the same209 target. Keep one selector in this root.
    for (const step of steps) {
        if (step.target === "orbit-node-209") delete step.labels;
    }
    return steps;
}

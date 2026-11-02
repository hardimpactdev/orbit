import { authorizedKeySteps } from "./authorized_key_steps.ts";
const expr = (source: string) => "${{ " + source + " }}";
const placement = { labels: { role: "swamp" } };
const after = (step: string) => [{ step, condition: { type: "succeeded" } }];

function globals(bootstrap: boolean) {
    return {
        name: expr(
            '"node-ssh-access-" + string(inputs.node_id) + "' +
                (bootstrap ? "-bootstrap" : "-own") + '"',
        ),
        transport: {
            kind: "ssh",
            user: expr("inputs.intent.target.user"),
            port: expr("inputs.intent.target.port"),
            hostKeys: expr("inputs.intent.target.host_keys"),
            ...(bootstrap
                ? {
                    identityContent: expr(
                        "vault.get(inputs.execution_secrets.bootstrap_identity.vault, inputs.execution_secrets.bootstrap_identity.key)",
                    ),
                }
                : {
                    identityFile: expr("inputs.intent.identity_file"),
                    identitiesOnly: true,
                }),
        },
        hosts: [{ name: "target", address: expr("inputs.intent.target.host") }],
    };
}
export function sshAccessWorkflow() {
    return {
        id: "d672c246-33c1-4fc7-b8b4-ae37f4885b1e",
        name: "operation-node-ssh-access-converge",
        vaults: ["orbit-control-inputs"],
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer" },
                node_id: { type: "integer" },
                kind: { type: "string", enum: ["node.ssh_access_converge"] },
                intent: { type: "object" },
                execution_secrets: {
                    type: "object",
                    properties: {
                        bootstrap_identity: {
                            type: "object",
                            properties: {
                                vault: {
                                    type: "string",
                                    enum: ["orbit-control-inputs"],
                                },
                                key: {
                                    type: "string",
                                    pattern: "^bootstrap-identity-node-14$",
                                },
                            },
                            required: ["vault", "key"],
                            additionalProperties: false,
                        },
                    },
                    required: ["bootstrap_identity"],
                    additionalProperties: false,
                },
            },
            required: [
                "operation_id",
                "node_id",
                "kind",
                "intent",
                "execution_secrets",
            ],
            additionalProperties: false,
        },
        jobs: [{
            name: "ssh_access",
            ...placement,
            steps: [
                ...authorizedKeySteps(
                    "authorize",
                    "inputs.intent.target.user",
                    "inputs.intent.public_key",
                    globals(true),
                ),
                {
                    name: "verify-own-key",
                    ...placement,
                    dependsOn: after("authorize"),
                    task: {
                        type: "behavior",
                        behaviorType: "@swamp/ssh",
                        behaviorName: globals(false).name,
                        methodName: "exec",
                        globalArgs: globals(false),
                        inputs: {
                            hosts: "name:target",
                            argv: ["/usr/bin/true"],
                        },
                    },
                },
                {
                    name: "result",
                    ...placement,
                    dependsOn: after("verify-own-key"),
                    task: {
                        type: "behavior",
                        behaviorType: "@nckrtl/node-ssh-access",
                        behaviorName: "ssh-access-result",
                        methodName: "result",
                        inputs: {
                            verification: expr(
                                'steps["verify-own-key"].outputs',
                            ),
                        },
                    },
                },
            ],
        }],
    };
}
if (import.meta.main) console.log(JSON.stringify(sshAccessWorkflow(), null, 2));

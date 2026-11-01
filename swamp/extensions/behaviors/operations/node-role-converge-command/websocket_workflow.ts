import { stringify } from "jsr:@std/yaml@1.0.12";
import { roleActionSteps } from "../node-role-command/steps.ts";
import { roleWebSocketSteps } from "./websocket_steps.ts";
const ex = (s: string) => "${{ " + s + " }}";
export function websocketWorkflow() {
    const globals = {
        name: ex('"websocket-"+run.id'),
        transport: {
            kind: "ssh",
            user: ex("inputs.ssh.target.user"),
            port: ex("inputs.ssh.target.port"),
            identityFile: ex("inputs.ssh.identity_file"),
        },
        hosts: [{
            name: "target",
            address: ex("inputs.ssh.target.host"),
            transport: { hostKeys: ex("inputs.ssh.target.host_keys") },
        }],
    };
    const steps = [
        ...roleActionSteps(
            "account",
            "account",
            '{"user":inputs.runtime.user}',
            globals,
        ),
        ...roleWebSocketSteps(
            "websocket",
            "inputs.runtime",
            globals,
            "vault.get(inputs.material.vault,inputs.material.key)",
            "account",
            "account",
        ),
    ];
    for (const step of steps) step.allowFailure = true;
    steps.push({
        name: "result",
        target: "orbit-node-209",
        dependsOn: ["websocket", "websocket_cleanup"].map((step) => ({
            step,
            condition: { type: "succeeded" },
        })),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-runtime-assets",
            behaviorName: ex('"websocket-result-"+run.id'),
            methodName: "complete",
            inputs: {
                node_id: ex("inputs.runtime.node_id"),
                assignment_id: ex("inputs.runtime.assignment_id"),
            },
        },
    });
    steps.push({
        name: "proven",
        target: "orbit-node-209",
        dependsOn: [{ step: "result", condition: { type: "always" } }],
        task: {
            type: "assert",
            expr:
                '"result" in steps && steps["result"].status == "succeeded" && steps["result"].outputs.completed',
            severity: "high",
            message: "WebSocket runtime must complete after recovery settles",
        },
    });
    return {
        id: "b5f10569-2322-44e2-8c42-312374dc2b1d",
        name: "node-role-websocket",
        vaults: ["orbit-control-inputs"],
        inputs: {
            type: "object",
            properties: {
                runtime: { type: "object" },
                ssh: { type: "object" },
                material: {
                    type: "object",
                    properties: {
                        vault: {
                            type: "string",
                            enum: ["orbit-control-inputs"],
                        },
                        key: { type: "string" },
                    },
                    required: ["vault", "key"],
                    additionalProperties: false,
                },
            },
            required: ["runtime", "ssh", "material"],
            additionalProperties: false,
        },
        jobs: [{ name: "runtime", steps }],
    };
}
if (import.meta.main) {
    await Deno.writeTextFile(
        new URL(
            "../../../../workflows/workflow-node-role-websocket.yaml",
            import.meta.url,
        ),
        stringify(websocketWorkflow(), { flowLevel: 4, lineWidth: -1 }),
    );
}

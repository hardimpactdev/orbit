import { stringify } from "jsr:@std/yaml@1.0.12";
import { swampRoleConvergeSteps } from "./steps.ts";
export function swampRoleWorkflow() {
    const ex = (s: string) => "${{ " + s + " }}";
    return {
        id: "c68d404e-7c2e-41b4-8cf2-967b2314b356",
        name: "node-role-swamp",
        vaults: [],
        queueTimeout: 30,
        inputs: {
            type: "object",
            properties: { intent: { type: "object" }, ssh: { type: "object" } },
            required: ["intent", "ssh"],
            additionalProperties: false,
        },
        jobs: [{
            name: "role",
            steps: swampRoleConvergeSteps("", "inputs.intent", {
                name: ex('"swamp-role-"+run.id'),
                transport: {
                    kind: "ssh",
                    user: ex("inputs.ssh.transport.user"),
                    port: ex("inputs.ssh.transport.port"),
                    identityFile: ex("inputs.ssh.transport.identityFile"),
                },
                hosts: ex("inputs.ssh.hosts"),
            }),
        }],
    };
}
if (import.meta.main) {
    await Deno.writeTextFile(
        new URL(
            "../../../../workflows/workflow-node-role-swamp.yaml",
            import.meta.url,
        ),
        stringify(swampRoleWorkflow(), { flowLevel: 4, lineWidth: -1 }),
    );
}

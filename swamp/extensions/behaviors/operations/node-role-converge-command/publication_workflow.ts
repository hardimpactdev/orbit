import { stringify } from "jsr:@std/yaml@1.0.12";
import { rolePublicationSteps } from "./publication_steps.ts";
export function publicationWorkflow() {
    const steps = rolePublicationSteps(
        "publication",
        "inputs.intent",
        "forward",
    );
    steps.at(-1)!.name = "result";
    return {
        id: "6af0c187-03db-4162-8b53-e4f86ed120be",
        name: "node-role-publication",
        inputs: {
            type: "object",
            properties: {
                operation_id: { type: "integer", minimum: 1 },
                node_id: { type: "integer", minimum: 1 },
                kind: { type: "string", const: "node.role_converge" },
                intent: { type: "object" },
            },
            required: ["operation_id", "node_id", "kind", "intent"],
            additionalProperties: false,
        },
        jobs: [{ name: "publish", steps }],
    };
}
if (import.meta.main) {
    await Deno.writeTextFile(
        new URL(
            "../../../../workflows/workflow-node-role-publication.yaml",
            import.meta.url,
        ),
        stringify(publicationWorkflow(), { flowLevel: 4, lineWidth: -1 }),
    );
}

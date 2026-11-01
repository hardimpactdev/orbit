import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { analyzeYaml } from "../../../../bin/check-swamp-shape.ts";
import { nodeRemovalWorkflow } from "../node-removal-command/workflows.ts";

const ownedWorkflow = (name: string) =>
    /(?:^|[-_.])(?:node|workers?|ssh)(?:[-_.]|$)/.test(name);
const workflows = new URL("../../../../workflows/", import.meta.url);

Deno.test("Node, worker and SSH workflows have no shared forEach model identity allowance", async () => {
    const findings = [];
    for await (const entry of Deno.readDir(workflows)) {
        if (!entry.isFile || !ownedWorkflow(entry.name)) {
            continue;
        }
        const source = await Deno.readTextFile(new URL(entry.name, workflows));
        findings.push(
            ...analyzeYaml(`workflows/${entry.name}`, source).findings
                .filter((finding) =>
                    finding.rule === "foreach-shared-identity"
                ),
        );
    }
    assertEquals(findings, []);
    const baseline = JSON.parse(
        await Deno.readTextFile(
            new URL(
                "../../../../config/swamp-shape-baseline.json",
                import.meta.url,
            ),
        ),
    ) as { entries: { file: string; rule: string }[] };
    assertEquals(
        baseline.entries.filter((entry) =>
            ownedWorkflow(entry.file) &&
            entry.rule === "foreach-shared-identity"
        ),
        [],
    );
});

Deno.test("Node removal fan-outs isolate each run, phase and iteration and retain generated source", async () => {
    const generated = nodeRemovalWorkflow();
    const published = parse(
        await Deno.readTextFile(
            new URL("workflow-operation-node-remove.yaml", workflows),
        ),
    ) as typeof generated;
    assertEquals(published, generated);
    const names: string[] = [];
    for (const job of generated.jobs) {
        for (const step of job.steps) {
            if (
                !step.forEach ||
                !(step.task as Record<string, unknown>).behaviorName
            ) continue;
            const name = String(
                (step.task as Record<string, unknown>).behaviorName,
            );
            assertEquals(name.includes("run.id"), true, String(step.name));
            assertEquals(name.includes("self._index"), true, String(step.name));
            names.push(name);
        }
    }
    assertEquals(names.length > 5, true);
});

Deno.test("SSH key convergence declares its bootstrap vault", async () => {
    const source = await Deno.readTextFile(
        new URL("workflow-operation-node-ssh-access-converge.yaml", workflows),
    );
    assertEquals((parse(source) as Record<string, unknown>).vaults, [
        "orbit-control-inputs",
    ]);
    assertEquals(
        analyzeYaml(
            "workflows/workflow-operation-node-ssh-access-converge.yaml",
            source,
        ).findings
            .filter((finding) => finding.rule === "workflow-vault-declaration"),
        [],
    );
});

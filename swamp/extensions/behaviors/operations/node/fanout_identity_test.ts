import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { analyzeYaml } from "../../../../bin/check-swamp-shape.ts";
import { nodeRemovalWorkflow } from "./removal_workflow.ts";
import { sshAccessWorkflow } from "../node-bootstrap/ssh_access_workflow.ts";

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
    const phases = [
        "serving",
        "restore_certificate_requests",
        "restore_certificate_publish",
        "restore_serving",
    ];
    const selected = (jobs: typeof generated.jobs) =>
        jobs.filter((job) => phases.includes(String(job.name)));
    assertEquals(selected(published.jobs), selected(generated.jobs));
    const names = generated.jobs.filter((job) =>
        phases.includes(String(job.name))
    ).map((job) => {
        const step = (job.steps as { task: { behaviorName: string } }[])[0];
        const name = step.task.behaviorName;
        assertEquals(name.includes("run.id"), true, String(job.name));
        assertEquals(
            name.includes("string(self._index)"),
            true,
            String(job.name),
        );
        return name;
    });
    assertEquals(names.length, phases.length);
    assertEquals(new Set(names).size, phases.length);
});

Deno.test("SSH key convergence declares its bootstrap vault in generated and published source", async () => {
    const generated = sshAccessWorkflow();
    const source = await Deno.readTextFile(
        new URL("workflow-operation-node-ssh-access-converge.yaml", workflows),
    );
    assertEquals(parse(source), generated);
    assertEquals((generated as Record<string, unknown>).vaults, [
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

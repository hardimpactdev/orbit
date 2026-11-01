import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";
import { roleCertificateJobs } from "../node/role_certificates_workflow.ts";

Deno.test("certificate control steps use exactly one selector and distinct child signer identity", async () => {
    for (
        const name of [
            "route-certificate-publish",
            "route-certificate-remove",
            "node-role-certificate-publish",
        ]
    ) {
        const value = parse(
            await Deno.readTextFile(
                new URL(
                    "../../../../workflows/workflow-" + name + ".yaml",
                    import.meta.url,
                ),
            ),
        ) as { jobs: { steps: Record<string, unknown>[] }[] };
        for (const job of value.jobs) {
            for (const step of job.steps) {
                assertEquals(
                    "target" in step && "labels" in step,
                    false,
                    name + "/" + step.name,
                );
                const task = step.task as Record<string, unknown>;
                if (task.behaviorType === "command/shell") {
                    assert(
                        String(task.behaviorName).includes("run.id"),
                        name + "/" + step.name,
                    );
                }
            }
        }
    }
});
Deno.test("same-Node role scopes get distinct model identities across child and record fanouts", () => {
    const generated = roleCertificateJobs("baseline");
    for (const job of generated.jobs) {
        for (const raw of job.steps as Record<string, unknown>[]) {
            assertEquals("target" in raw && "labels" in raw, false);
            if (raw.forEach) {
                const task = raw.task as Record<string, unknown>;
                const identity = task.type === "workflow"
                    ? (task.inputs as { ssh: { name: string } }).ssh.name
                    : task.behaviorName;
                assert(String(identity).includes("run.id"));
                assert(String(identity).includes("self._index"));
            }
        }
    }
});

Deno.test("all certificate HTTP steps use the one Gateway-only signer script", async () => {
    const manifest = parse(
        await Deno.readTextFile(new URL("./manifest.yaml", import.meta.url)),
    ) as { additionalFiles: string[] };
    assert(manifest.additionalFiles.includes("scripts/sign.py"));
    await Deno.stat(new URL("./scripts/sign.py", import.meta.url));
    const directory = new URL("../../../../workflows/", import.meta.url);
    let count = 0;
    for await (const entry of Deno.readDir(directory)) {
        if (!entry.name.endsWith(".yaml")) continue;
        const value = parse(
            await Deno.readTextFile(new URL(entry.name, directory)),
        ) as { jobs: { steps: Record<string, unknown>[] }[] };
        for (const job of value.jobs) {
            for (const step of job.steps) {
                const task = step.task as {
                    behaviorType?: string;
                    behaviorName?: string;
                    inputs?: { env?: Record<string, unknown>; run?: string };
                };
                if (
                    task?.behaviorType !== "command/shell" ||
                    !task.inputs?.env?.ORBIT_CERTIFICATE_OPERATION_ID
                ) continue;
                count++;
                assertEquals(
                    task.inputs.run,
                    '/usr/bin/python3 -B "$ORBIT_CERTIFICATE_SIGN_SCRIPT"',
                    entry.name + "/" + step.name,
                );
                assert(task.behaviorName?.includes("run.id"));
            }
        }
    }
    assert(count > 0);
});

Deno.test("role publication keeps verification and key cleanup as separate recoverable actions", async () => {
    const child = parse(
        await Deno.readTextFile(
            new URL(
                "../../../../workflows/workflow-node-role-certificate-publish.yaml",
                import.meta.url,
            ),
        ),
    ) as { jobs: { steps: Record<string, unknown>[] }[] };
    const steps = child.jobs[0].steps;
    const names = steps.map((step) => step.name);
    assert(names.indexOf("verify-pair") >= 0);
    assert(names.indexOf("verify-pair") < names.indexOf("publish"));
    assert(names.indexOf("activate") < names.indexOf("cleanup-key"));
    assert(names.indexOf("cleanup-key") < names.indexOf("cleanup-assets"));
    for (
        const [name, predecessor] of [["publish", "verify-pair"], [
            "cleanup-key",
            "activate",
        ], ["cleanup-assets", "cleanup-key"]]
    ) {
        const step = steps.find((step) => step.name === name)!;
        assertEquals(step.dependsOn, [{
            step: predecessor,
            condition: { type: "succeeded" },
        }]);
    }
    const manifest = parse(
        await Deno.readTextFile(new URL("manifest.yaml", import.meta.url)),
    ) as { additionalFiles: string[] };
    for (
        const script of ["scripts/role_verify.py", "scripts/role_cleanup.py"]
    ) {
        assert(manifest.additionalFiles.includes(script));
        await Deno.stat(new URL(script, import.meta.url));
    }
    const publish = await Deno.readTextFile(
        new URL("scripts/role_publish.py", import.meta.url),
    );
    assert(!publish.includes("write_signature("));
    assert(!publish.includes("rmtree("));
});

Deno.test("retained role parents delegate each leaf to the visible native child", () => {
    const jobs = roleCertificateJobs("baseline").jobs;
    const steps = jobs.flatMap((job) => job.steps) as Record<string, unknown>[];
    const tasks = steps.map((step) => step.task as Record<string, unknown>);
    assert(!tasks.some((task) => task.behaviorType === "@nckrtl/certificates"));
    const child = tasks.find((task) => task.type === "workflow")!;
    assertEquals(child.workflowIdOrName, "node-role-certificate-publish");
    assertEquals(
        steps.find((step) => step.task === child)!.target,
        "orbit-node-209",
    );
});

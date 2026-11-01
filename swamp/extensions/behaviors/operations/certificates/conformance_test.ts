import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";
import { roleCertificateJobs } from "../node/role_certificates_workflow.ts";

Deno.test("certificate control steps use exactly one selector and distinct child signer identity", async () => {
    for (
        const name of [
            "operation-certificate-converge",
            "operation-certificate-remove",
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
Deno.test("same-Node role scopes get distinct model identities across request publish and record fanouts", () => {
    const generated = roleCertificateJobs("baseline");
    for (const job of generated.jobs) {
        for (const raw of job.steps as Record<string, unknown>[]) {
            assertEquals("target" in raw && "labels" in raw, false);
            if (raw.forEach) {
                const task = raw.task as Record<string, unknown>;
                assert(String(task.behaviorName).includes("run.id"));
                assert(String(task.behaviorName).includes("self._index"));
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

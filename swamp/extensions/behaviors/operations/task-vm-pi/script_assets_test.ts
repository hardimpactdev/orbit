import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./script_assets.ts";

const sha = "a".repeat(64);
const intent = {
    task_vm_id: 42,
    guest_node_id: 209,
    user: "orbit" as const,
    runtime: { pi_sha256: sha },
};

Deno.test("Pi metadata returns only public inputs and native cached script paths", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.read.execute({ intent }, {
        extensionFile: (name) => "/native-cache/" + name,
        writeResource: (_spec, _name, value) => {
            writes.push(value);
            return Promise.resolve();
        },
    });
    assertEquals(result, {
        task_vm_id: 42,
        sha256: sha,
        inspect_script: "/native-cache/scripts/pi_inspect.py",
        install_script: "/native-cache/scripts/pi_install.py",
        verify_script: "/native-cache/scripts/pi_verify.py",
    });
    assertEquals(writes, [result]);
});

Deno.test("Pi observation refuses foreign identity, digest and extra output before writing", async () => {
    let writes = 0;
    const ctx = {
        extensionFile: (name: string) => name,
        writeResource: () => {
            writes++;
            return Promise.resolve();
        },
    };
    for (
        const value of [
            { task_vm_id: 43, sha256: sha, current: true },
            { task_vm_id: 42, sha256: "b".repeat(64), current: true },
            {
                task_vm_id: 42,
                sha256: sha,
                current: true,
                token: "must-not-persist",
            },
        ]
    ) {
        await assertRejects(
            () =>
                behavior.methods.inspection.execute({
                    task_vm_id: 42,
                    sha256: sha,
                    stdout: JSON.stringify(value),
                }, ctx),
            Error,
            "task_vm.pi_artifact_failed",
        );
    }
    assertEquals(writes, 0);
    assertEquals(
        await behavior.methods.inspection.execute({
            task_vm_id: 42,
            sha256: sha,
            stdout: JSON.stringify({
                task_vm_id: 42,
                sha256: sha,
                current: false,
            }),
        }, ctx),
        { task_vm_id: 42, sha256: sha, current: false },
    );
});

import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { behavior } from "./model_key_result.ts";

Deno.test("model result shapes only public same-VM issue and revoke results", async () => {
    const writes: unknown[] = [];
    const ctx = {
        writeResource: (_spec: string, _name: string, value: unknown) => {
            writes.push(value);
            return Promise.resolve({ name: "public" });
        },
    };
    for (
        const result of [{ task_vm_id: 42, issued: true }, {
            task_vm_id: 42,
            revoked: true,
        }]
    ) {
        await behavior.methods.read.execute({
            task_vm_id: 42,
            stdout: JSON.stringify(result),
        }, ctx);
    }
    assertEquals(writes, [{ task_vm_id: 42, issued: true }, {
        task_vm_id: 42,
        revoked: true,
    }]);
});

Deno.test("model result refuses other VMs, false claims and secret-bearing output without persisting", async () => {
    let writes = 0;
    const secret = "private-value-that-must-not-appear";
    const ctx = {
        writeResource: () => {
            writes++;
            return Promise.resolve({});
        },
    };
    for (
        const stdout of [
            secret,
            JSON.stringify({ task_vm_id: 43, revoked: true }),
            JSON.stringify({ task_vm_id: 42, revoked: false }),
            JSON.stringify({ task_vm_id: 42, revoked: true, key: secret }),
        ]
    ) {
        await assertRejects(
            () =>
                behavior.methods.read.execute({ task_vm_id: 42, stdout }, ctx),
            Error,
            "task_vm.model_key_failed",
        );
    }
    assertEquals(writes, 0);
});

import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior } from "./destroy.ts";

Deno.test("revocation requires the same VM before provider deletion", async () => {
    const intent = {
        task_vm_id: 42,
        guest_node_id: null,
        provider: "upcloud",
        provider_input: {
            task_vm_id: 42,
            provider: "upcloud",
            name: "tvm-42",
            provider_id: null,
            vm: null,
            host_ssh_execution: null,
        },
        model_key_required: true,
        removal: null,
    };
    for (
        const receipt of [{ task_vm_id: 43, revoked: true }, {
            task_vm_id: 42,
            revoked: false,
        }]
    ) {
        const { context, getWrittenResources } = createModelTestContext();
        await assertRejects(() =>
            behavior.methods.revocation.execute({
                intent,
                stdout: JSON.stringify(receipt),
            }, context)
        );
        assertEquals(getWrittenResources(), []);
    }
    const { context, getWrittenResources } = createModelTestContext();
    await behavior.methods.revocation.execute({
        intent,
        stdout: '{"task_vm_id":42,"revoked":true}',
    }, context);
    assertEquals(
        getWrittenResources().map(({ specName, name, data }) => ({
            specName,
            name,
            data,
        })),
        [{
            specName: "revocation",
            name: "revocation",
            data: { task_vm_id: 42, revoked: true },
        }],
    );
});

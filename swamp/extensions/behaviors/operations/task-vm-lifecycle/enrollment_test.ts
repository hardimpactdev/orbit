import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior, enrollmentTerminal } from "./enrollment.ts";
import { guestConnection } from "./ssh_input.ts";

const key =
    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG";
async function receipts() {
    const intent = {
        task_vm_id: 42,
        guest_node_id: 142,
        user: "orbit" as const,
        provider: "incus" as "incus" | "upcloud",
        node: { node_id: 142, wireguard_ip: "10.44.0.142" },
        ssh_identity: {
            control_node_id: 209 as const,
            identity_file: "/identity",
            public_key: key,
        },
        runtime: { process: { process_id: 33 } },
    };
    const provider = {
        provider_id: null,
        address: "192.0.2.42",
        image_fingerprint: "a".repeat(64),
        running: true as const,
        settled: true as const,
    };
    return {
        intent,
        provider,
        guest: await guestConnection(intent, provider, key),
        peer: { public_key: "p".repeat(43) + "=" },
        runtime_stdout: JSON.stringify({
            task_vm_id: 42,
            guest_node_id: 142,
            process_id: 33,
            ready: true,
        }),
        observer_stdout: "e".repeat(64) + "  /etc/orbit/agent/secret\n",
    };
}

Deno.test("terminal refuses a runtime receipt for another VM, guest or process", async () => {
    const args = await receipts();
    for (const provider of ["incus", "upcloud"] as const) {
        args.intent.provider = provider;
        for (
            const patch of [{ task_vm_id: 43 }, { guest_node_id: 143 }, {
                process_id: 34,
            }, { ready: false }]
        ) {
            const runtime = { ...JSON.parse(args.runtime_stdout), ...patch };
            assertThrows(() =>
                enrollmentTerminal({
                    ...args,
                    runtime_stdout: JSON.stringify(runtime),
                })
            );
        }
    }
});

Deno.test("terminal writes the public same-VM receipt in memory", async () => {
    const args = await receipts();
    const { context, getWrittenResources } = createModelTestContext();
    await behavior.methods.terminal.execute(args, context);
    const [written] = getWrittenResources();
    assertEquals([written.specName, written.name], ["terminal", "terminal"]);
    const value = written.data as {
        task_vm_id: number;
        ready: boolean;
        result_json: string;
    };
    assertEquals([value.task_vm_id, value.ready], [42, true]);
    assertEquals(
        JSON.parse(value.result_json).enrollment.node.observer_digest,
        "e".repeat(64),
    );
});

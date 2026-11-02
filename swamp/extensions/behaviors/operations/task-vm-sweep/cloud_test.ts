import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { cloudPlan, cloudRemaining } from "./policy.ts";
const intent = {
    scope: { live_names: ["tvm-7"], max_task_vm_id: 10 },
    image_cutoff: Date.parse("2025-01-01T00:00:00Z") / 1000,
    running_stamps: ["20230101000000"],
};
const id = (n: number) =>
    `00000000-0000-4000-8000-${String(n).padStart(12, "0")}`;
const server = (
    n: number,
    name: string,
    key: string,
    value: string,
    state = "started",
) => ({ uuid: id(n), title: name, state, labels: { label: [{ key, value }] } });
const inventory = (
    vms: unknown[],
    images: unknown[] = [],
    templates: unknown[] = [],
) => JSON.stringify({
    vms: { servers: { server: vms } },
    images: { servers: { server: images } },
    templates: { storages: { storage: templates } },
});

Deno.test("cloud sweep only selects admitted orphan IDs and inactive old image stamps", () => {
    const plan = cloudPlan(
        intent,
        inventory(
            [
                server(40, "A foreign labelled server", "orbit-task-vm", "40"),
                server(6, "tvm-6", "orbit-task-vm", "6"),
                server(7, "tvm-7", "orbit-task-vm", "7"),
                server(11, "tvm-11", "orbit-task-vm", "11"),
            ],
            [
                server(
                    20,
                    "tvm-image-20240101000000",
                    "orbit-task-image",
                    "20240101000000",
                    "stopped",
                ),
                server(
                    21,
                    "tvm-image-20230101000000",
                    "orbit-task-image",
                    "20230101000000",
                ),
            ],
            [{
                uuid: id(32),
                title: "Ubuntu Server 24.04 LTS (Noble Numbat)",
                access: "public",
            }, {
                uuid: id(30),
                title: "orbit-task-base-20240101000000-unverified",
                access: "private",
            }, {
                uuid: id(31),
                title: "orbit-task-base-20250101000000",
                access: "private",
            }],
        ),
    );
    assertEquals(plan.servers.map((r) => r.name), [
        "tvm-6",
        "tvm-image-20240101000000",
    ]);
    assertEquals(plan.stops, [{
        name: "tvm-6",
        uuid: id(6),
        state: "started",
    }]);
    assertEquals(plan.templates, [{
        name: "orbit-task-base-20240101000000-unverified",
        uuid: id(30),
    }]);
    assertEquals(plan.observed_names, ["tvm-6", "tvm-7", "tvm-11"]);
});

Deno.test("cloud sweep rejects malformed labels and does not select invalid date stamps", () => {
    const row = server(6, "tvm-6", "orbit-task-vm", "6");
    row.labels.label.push({ key: "orbit-task-vm", value: "7" });
    assertThrows(() => cloudPlan(intent, inventory([row])));
    assertEquals(
        cloudPlan(
            intent,
            inventory([], [
                server(
                    20,
                    "tvm-image-20240231000000",
                    "orbit-task-image",
                    "20240231000000",
                ),
            ]),
        ).servers,
        [],
    );
    assertThrows(() => cloudPlan(intent, '{"servers":{"server":[]}}'));
});

Deno.test("the cloud end check lists deleted servers and templates that remain", () => {
    const after = inventory(
        [server(7, "tvm-7", "orbit-task-vm", "7")],
        [],
        [{
            uuid: id(30),
            title: "orbit-task-base-20240101000000-unverified",
            access: "private",
        }],
    );
    assertEquals(
        cloudRemaining([{ uuid: id(6) }, { uuid: id(30) }], after),
        { observed_names: ["tvm-7"], remaining: [{ uuid: id(30) }] },
    );
});

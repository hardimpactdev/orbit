import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { cloudPlan } from "./policy.ts";
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
    assertEquals(plan.deletes, [
        {
            name: "tvm-6",
            uuid: id(6),
            path: `server/${id(6)}?storages=1&backups=delete`,
        },
        {
            name: "tvm-image-20240101000000",
            uuid: id(20),
            path: `server/${id(20)}?storages=1&backups=delete`,
        },
        {
            name: "orbit-task-base-20240101000000-unverified",
            uuid: id(30),
            path: `storage/${id(30)}`,
        },
    ]);
    assertEquals(plan.stops, [{
        name: "tvm-6",
        uuid: id(6),
        state: "started",
    }]);
    assertEquals(plan.observed_names, ["tvm-7", "tvm-11"]);
    assertEquals(plan.deleted_names, [
        "tvm-6",
        "tvm-image-20240101000000",
        "orbit-task-base-20240101000000-unverified",
    ]);
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
        ).deletes,
        [],
    );
    assertThrows(() => cloudPlan(intent, '{"servers":{"server":[]}}'));
});

import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { cloudPlan } from "./metadata.ts";
const intent = {
    provider: "upcloud",
    scope: { live_names: ["tvm-7"], max_task_vm_id: 10 },
    image_cutoff: Date.parse("2025-01-01T00:00:00Z") / 1000,
    running_stamps: ["20230101000000"],
};
const id = (n: number) =>
    `00000000-0000-4000-8000-${String(n).padStart(12, "0")}`;
const server = (n: number, name: string, key: string, value: string) => ({
    uuid: id(n),
    title: name,
    labels: { label: [{ key, value }] },
});
Deno.test("cloud sweep only selects admitted orphan IDs and inactive old image stamps", () => {
    const rows = {
        servers: [
            server(6, "tvm-6", "orbit-task-vm", "6"),
            server(7, "tvm-7", "orbit-task-vm", "7"),
            server(11, "tvm-11", "orbit-task-vm", "11"),
            server(
                20,
                "tvm-image-20240101000000",
                "orbit-task-image",
                "20240101000000",
            ),
            server(
                21,
                "tvm-image-20230101000000",
                "orbit-task-image",
                "20230101000000",
            ),
        ],
        templates: [{
            uuid: id(30),
            title: "orbit-task-base-20240101000000-unverified",
            access: "private",
        }, {
            uuid: id(31),
            title: "orbit-task-base-20250101000000",
            access: "private",
        }],
    };
    const plan = cloudPlan(intent, JSON.stringify(rows));
    assertEquals(plan.servers.map((r) => r.name), [
        "tvm-6",
        "tvm-image-20240101000000",
    ]);
    assertEquals(plan.templates.map((r) => r.uuid), [id(30)]);
    assertEquals(plan.observed_names, ["tvm-6", "tvm-7", "tvm-11"]);
    assertEquals(JSON.parse(plan.servers[0].input_json).uuid, id(6));
});
Deno.test("cloud sweep rejects malformed labels and does not select invalid date stamps", () => {
    const row = server(6, "tvm-6", "orbit-task-vm", "6");
    row.labels.label.push({ key: "orbit-task-vm", value: "7" });
    assertThrows(() =>
        cloudPlan(intent, JSON.stringify({ servers: [row], templates: [] }))
    );
    assertEquals(
        cloudPlan(
            intent,
            JSON.stringify({
                servers: [
                    server(
                        20,
                        "tvm-image-20240231000000",
                        "orbit-task-image",
                        "20240231000000",
                    ),
                ],
                templates: [],
            }),
        ).servers,
        [],
    );
});

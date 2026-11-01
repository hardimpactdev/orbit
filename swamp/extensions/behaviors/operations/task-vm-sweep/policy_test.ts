import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { selectIncusOrphans } from "./policy.ts";

const scope = {
    project: "tasks",
    pool: "tasks",
    operator_owned: ["tasks/tvm-8"],
    live_names: ["tvm-7"],
    max_task_vm_id: 10,
    max_build_id: 20,
    recorded_build_ids: [17, 18, 19],
    retired_build_ids: [17],
    protected_build_ids: [18, 19],
};
const inventory = (names: string[]) =>
    JSON.stringify(names.map((name) => ({ name })));

Deno.test("sweep preserves live, operator-owned and post-admission VMs", () => {
    const plan = selectIncusOrphans(
        scope,
        inventory(["tvm-6", "tvm-7", "tvm-8", "tvm-11", "operator-service"]),
        "[]",
    );
    assertEquals(plan.instances, ["tvm-6"]);
});

Deno.test("sweep protects running and retained builders and future build IDs", () => {
    const plan = selectIncusOrphans(
        scope,
        inventory([
            "tvm-image-16",
            "tvm-image-17-attempt-2-smoke",
            "tvm-image-18",
            "tvm-image-19-smoke",
            "tvm-image-21",
        ]),
        "[]",
    );
    assertEquals(plan.instances, [
        "tvm-image-16",
        "tvm-image-17-attempt-2-smoke",
    ]);
});

Deno.test("sweep only selects orphan topology volumes after provider VM absence", () => {
    const volumes = JSON.stringify([
        "tvm-6-topology-seed",
        "tvm-7-topology-seed",
        "tvm-8-topology-seed",
        "tvm-11-topology-seed",
        "orbit-topology-seed-aaaaaaaaaaaa",
    ].map((name) => ({ name, type: "custom" })));
    assertEquals(
        selectIncusOrphans(scope, inventory(["tvm-6"]), volumes).volumes,
        ["tvm-8-topology-seed"],
    );
    assertEquals(
        selectIncusOrphans(scope, "[]", volumes).volumes,
        ["tvm-6-topology-seed", "tvm-8-topology-seed"],
    );
});

Deno.test("sweep preserves every resource in an operator-owned project", () => {
    const plan = selectIncusOrphans(
        { ...scope, operator_owned: ["tasks/*"] },
        inventory(["tvm-6", "tvm-image-16"]),
        JSON.stringify([{ name: "tvm-6-topology-seed", type: "custom" }]),
    );
    assertEquals(plan.instances, []);
    assertEquals(plan.volumes, []);
});

Deno.test("sweep refuses malformed inventory before returning any deletion plan", () => {
    assertThrows(() =>
        selectIncusOrphans(scope, '[{"name":"../tvm-6"}]', "[]")
    );
    assertThrows(() => selectIncusOrphans(scope, inventory(["tvm-6"]), "{}"));
});

import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import {
    imagePrefix,
    incusRows,
    incusStrays,
    instancePrefix,
    selectIncusOrphans,
    volumePrefix,
} from "./policy.ts";

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
const rows = (names: string[], project = "tasks") =>
    names.map((name) => ({ project, name }));

Deno.test("incus query URLs decode project and name; default has no project", () => {
    assertEquals(
        incusRows(
            JSON.stringify([
                "/1.0/instances/tvm-6?project=tasks",
                "/1.0/instances/web",
            ]),
            instancePrefix,
        ),
        [{ project: "tasks", name: "tvm-6" }, {
            project: "default",
            name: "web",
        }],
    );
    assertEquals(
        incusRows(
            '["/1.0/storage-pools/tasks/volumes/custom/tvm-6-topology-seed?project=tasks"]',
            volumePrefix("tasks"),
        ),
        [{ project: "tasks", name: "tvm-6-topology-seed" }],
    );
});

Deno.test("incus query URLs refuse another prefix or a path name", () => {
    assertThrows(() =>
        incusRows(
            '["/1.0/storage-pools/other/volumes/custom/a"]',
            volumePrefix("tasks"),
        )
    );
    assertThrows(() =>
        incusRows('["/1.0/instances/..%2Ftvm-6?project=tasks"]', instancePrefix)
    );
    assertThrows(() => incusRows("{}", instancePrefix));
});

Deno.test("sweep preserves live, operator-owned and post-admission VMs", () => {
    const plan = selectIncusOrphans(
        scope,
        rows(["tvm-6", "tvm-7", "tvm-8", "tvm-11", "operator-service"]),
        [],
    );
    assertEquals(plan.instances, ["tvm-6"]);
});

Deno.test("sweep changes only the host's project", () => {
    const plan = selectIncusOrphans(
        scope,
        [...rows(["tvm-6"], "other"), ...rows(["tvm-5"])],
        rows(["tvm-6-topology-seed"], "other"),
    );
    assertEquals(plan.instances, ["tvm-5"]);
    assertEquals(plan.observed_names, []);
    assertEquals(plan.volumes, []);
});

Deno.test("sweep protects running and retained builders and future build IDs", () => {
    const plan = selectIncusOrphans(
        scope,
        rows([
            "tvm-image-16",
            "tvm-image-17-attempt-2-smoke",
            "tvm-image-18",
            "tvm-image-19-smoke",
            "tvm-image-21",
        ]),
        [],
    );
    assertEquals(plan.instances, [
        "tvm-image-16",
        "tvm-image-17-attempt-2-smoke",
    ]);
});

Deno.test("sweep selects a topology volume whose VM is absent or removed first", () => {
    const volumes = rows([
        "tvm-6-topology-seed",
        "tvm-7-topology-seed",
        "tvm-8-topology-seed",
        "tvm-9-topology-seed",
        "tvm-11-topology-seed",
        "orbit-topology-seed-aaaaaaaaaaaa",
    ]);
    // tvm-6 is removed in this run; operator-owned tvm-8 stays, so its seed stays.
    assertEquals(
        selectIncusOrphans(scope, rows(["tvm-6", "tvm-8"]), volumes).volumes,
        ["tvm-6-topology-seed", "tvm-9-topology-seed"],
    );
});

Deno.test("sweep preserves every resource in an operator-owned project", () => {
    const plan = selectIncusOrphans(
        { ...scope, operator_owned: ["tasks/*"] },
        rows(["tvm-6", "tvm-image-16"]),
        rows(["tvm-6-topology-seed"]),
    );
    assertEquals(plan.instances, []);
    assertEquals(plan.volumes, []);
});

Deno.test("strays are foreign instances and sweep-pool volumes, never task VMs or kept seeds", () => {
    const fingerprints = incusRows(
        '["/1.0/images/aaaaaaaaaaaa0123"]',
        imagePrefix,
    ).map((row) => row.name);
    const strays = incusStrays(
        scope,
        true,
        [
            ...rows(["tvm-6", "tvm-image-18", "tvm-image-16", "web"]),
            ...rows(["runner"], "orbit-ci"),
            ...rows(["tvm-8", "service"], "default"),
        ],
        rows([
            "tvm-6-topology-seed",
            "orbit-topology-seed-aaaaaaaaaaaa",
            "orbit-topology-seed-bbbbbbbbbbbb",
        ]),
        fingerprints,
    );
    assertEquals(strays.instances, [
        { project: "tasks", name: "tvm-image-16" },
        { project: "tasks", name: "web" },
        { project: "default", name: "tvm-8" },
        { project: "default", name: "service" },
    ]);
    assertEquals(strays.volumes, [{
        project: "tasks",
        name: "orbit-topology-seed-bbbbbbbbbbbb",
        pool: "tasks",
    }]);
    assertEquals(
        incusStrays(scope, false, rows(["runner"], "orbit-ci"), [], [])
            .instances,
        rows(["runner"], "orbit-ci"),
    );
});

Deno.test("the plan names what it deletes and what the host keeps after the removals", () => {
    const plan = selectIncusOrphans(
        scope,
        rows(["tvm-6", "tvm-7", "tvm-image-18"]),
        rows(["tvm-6-topology-seed", "tvm-7-topology-seed"]),
    );
    assertEquals(plan.observed_names, ["tvm-7", "tvm-image-18"]);
    assertEquals(plan.deleted_names, ["tvm-6", "tvm-6-topology-seed"]);
});

Deno.test("sweep refuses malformed scope before returning any deletion plan", () => {
    assertThrows(() =>
        selectIncusOrphans({ ...scope, project: "../tasks" }, [], [])
    );
});

Deno.test("a slot's VM, seed clone and identity volume are owned and never deleted", () => {
    const names = ["beast-tvm-1", "beast-tvm-1-topology-seed", "beast-tvm-1-identity"];
    const strays = incusStrays(
        scope,
        false,
        [...rows(["beast-tvm-1", "beast-tvm-x1"]), ...rows(["beast-tvm-2"], "default")],
        rows(names.slice(1)),
        [],
    );
    assertEquals(strays.instances, [
        { project: "tasks", name: "beast-tvm-x1" },
        { project: "default", name: "beast-tvm-2" },
    ]);
    assertEquals(strays.volumes, []);
    const orphans = selectIncusOrphans(scope, rows(names.slice(0, 1)), rows(names.slice(1)));
    assertEquals([orphans.instances, orphans.volumes], [[], []]);
});

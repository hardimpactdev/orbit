import { assertEquals } from "jsr:@std/assert@1.0.19";
import { check, type ImageReaperHost, pick } from "./image_reaper.ts";

const now = Date.parse("2026-10-31T12:00:00Z");
const host: ImageReaperHost = {
    node_id: 9,
    project: "orbit-tasks",
    pool: "orbit-tasks",
    operator_owned: [],
    protected_builds: [],
    host: "10.44.0.9",
};
const vm = (
    name: string,
    hours = 3,
    patch: Record<string, unknown> = {},
) => ({
    name,
    type: "virtual-machine",
    status: "Running",
    created_at: new Date(now - hours * 60 * 60 * 1000).toISOString(),
    expanded_devices: {
        root: { type: "disk", path: "/", pool: "orbit-tasks" },
    },
    ...patch,
});
const listed = (...rows: unknown[]) => ({
    "list-0": { outputs: { exitCode: 0, stdout: JSON.stringify(rows) } },
});
const names = (h: ImageReaperHost, steps: Record<string, unknown>) =>
    pick([h], steps, now).deletions.map((d) => d.vm);

Deno.test("only builders older than two hours on the host pool are chosen", () => {
    const steps = listed(
        vm("tvm-image-7"),
        vm("tvm-image-8-attempt-2-smoke", 3, { status: "Stopped" }),
        vm("tvm-image-11-builder"),
        vm("tvm-image-9", 1),
        vm("tvm-image-10", 2),
        vm("tvm-175"),
        vm("builder"),
    );
    assertEquals(names(host, steps), [
        "tvm-image-7",
        "tvm-image-8-attempt-2-smoke",
        "tvm-image-11-builder",
    ]);
});

Deno.test("an active build protects its builder, attempt and smoke names", () => {
    const result = pick(
        [{ ...host, protected_builds: [7] }],
        listed(
            vm("tvm-image-7"),
            vm("tvm-image-7-attempt-3"),
            vm("tvm-image-7-attempt-3-smoke"),
            vm("tvm-image-70"),
        ),
        now,
    );
    assertEquals(result.deletions.map((d) => d.vm), ["tvm-image-70"]);
    assertEquals(result.protected, [
        "tvm-image-7",
        "tvm-image-7-attempt-3",
        "tvm-image-7-attempt-3-smoke",
    ]);
});

Deno.test("foreign root pool, container, invalid timestamp and odd states are never candidates", () => {
    const steps = listed(
        vm("tvm-image-7", 3, {
            expanded_devices: {
                root: { type: "disk", path: "/", pool: "foreign-pool" },
            },
        }),
        vm("tvm-image-20", 3, { type: "container" }),
        vm("tvm-image-21", 3, { created_at: "not-a-time" }),
        vm("tvm-image-22", 3, { status: "Error" }),
        vm("tvm-image-23", 3, { expanded_devices: {} }),
    );
    assertEquals(names(host, steps), []);
});

Deno.test("explicit operator-owned names and project reservations are preserved", () => {
    for (const owned of ["orbit-tasks/tvm-image-7", "orbit-tasks/*"]) {
        assertEquals(
            names(
                { ...host, operator_owned: [owned] },
                listed(vm("tvm-image-7")),
            ),
            [],
        );
    }
    assertEquals(
        names(
            { ...host, operator_owned: ["other/tvm-image-7"] },
            listed(vm("tvm-image-7")),
        ),
        ["tvm-image-7"],
    );
});

Deno.test("a failed, missing, invalid or duplicate list marks only that host unlisted", () => {
    const other = { ...host, node_id: 13, host: "10.44.0.13" };
    for (
        const first of [
            { outputs: { exitCode: 1, stdout: "[]" } },
            undefined,
            { outputs: { exitCode: 0, stdout: "not json" } },
            {
                outputs: {
                    exitCode: 0,
                    stdout: JSON.stringify([
                        vm("tvm-image-7"),
                        vm("tvm-image-7"),
                    ]),
                },
            },
        ]
    ) {
        const result = pick([host, other], {
            "list-0": first,
            "list-1": listed(vm("tvm-image-30"))["list-0"],
        }, now);
        assertEquals(result.failed, [9]);
        assertEquals(result.deletions.map((d) => [d.node_id, d.vm]), [[
            13,
            "tvm-image-30",
        ]]);
        assertEquals(result.hosts.map((h) => h.node_id), [13]);
    }
});

Deno.test("a deletion counts only when the delete succeeded and no root volume or snapshot remains", () => {
    const picked = pick(
        [host],
        listed(vm("tvm-image-7"), vm("tvm-image-8"), vm("tvm-image-9")),
        now,
    );
    const ok = (stdout = "") => ({ outputs: { exitCode: 0, stdout } });
    const volumes = (...names: string[]) =>
        ok(JSON.stringify([
            ...names.map((name) => ({ type: "virtual-machine", name })),
            { type: "custom", name: "tvm-image-9" },
        ]));
    assertEquals(
        check(picked, {
            "delete-0": ok(),
            "delete-1": { status: "failed" },
            "delete-2": ok(),
            "volumes-0": volumes("tvm-image-8", "tvm-image-7/snapshot0"),
        }),
        [{
            node_id: 9,
            deleted: ["tvm-image-9"],
            failed: ["tvm-image-7", "tvm-image-8"],
        }],
    );
    assertEquals(
        check(picked, { "delete-0": ok(), "delete-1": ok(), "delete-2": ok() }),
        [{
            node_id: 9,
            deleted: [],
            failed: ["tvm-image-7", "tvm-image-8", "tvm-image-9"],
        }],
    );
});

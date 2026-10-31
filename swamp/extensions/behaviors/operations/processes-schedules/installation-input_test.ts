import { assertEquals } from "jsr:@std/assert@1.0.14";
import { ProcessInstallation } from "./installation-input.ts";

const systemd = {
    process_id: 17,
    runtime: "systemd",
    name: "orbit-process-17-worker.service",
    desired: "running",
    on_demand: false,
    content_hash: "a".repeat(64),
    activation_pending: false,
    retired: null,
};

Deno.test("installation mount dependencies default empty and preserve canonical literal paths", () => {
    assertEquals(ProcessInstallation.parse(systemd).required_mount_paths, []);
    const paths = ["/", "/srv/checkout with space/.swamp", "/srv/literal%path"];
    assertEquals(
        ProcessInstallation.parse({
            ...systemd,
            required_mount_paths: paths,
        }).required_mount_paths,
        paths,
    );
    assertEquals(
        ProcessInstallation.safeParse({
            ...systemd,
            required_mount_paths: Array.from(
                { length: 64 },
                (_, id) => "/srv/m" + id,
            ),
        }).success,
        true,
    );
});

Deno.test("installation mount dependencies refuse noncanonical null duplicate and oversized paths", () => {
    for (
        const value of [
            null,
            "not-an-array",
            ["relative"],
            ["/srv/../state"],
            ["/srv/./state"],
            ["/srv//state"],
            ["/srv/state/"],
            ["/srv/\nstate"],
            ["/srv/\x7fstate"],
            ["/same", "/same"],
            ["/" + "界".repeat(1366)],
            Array.from({ length: 65 }, (_, id) => "/srv/m" + id),
        ]
    ) {
        assertEquals(
            ProcessInstallation.safeParse({
                ...systemd,
                required_mount_paths: value,
            }).success,
            false,
        );
    }
});

Deno.test("Docker installation accepts only empty required mounts", () => {
    const docker = {
        process_id: 17,
        runtime: "docker",
        name: "orbit-process-17-worker",
        desired: "running",
        on_demand: false,
        spec: "a".repeat(64),
        retired: null,
    };
    assertEquals(ProcessInstallation.parse(docker).required_mount_paths, []);
    assertEquals(
        ProcessInstallation.safeParse({
            ...docker,
            required_mount_paths: ["/srv/state"],
        }).success,
        false,
    );
});

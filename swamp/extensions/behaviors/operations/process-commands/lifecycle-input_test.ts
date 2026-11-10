import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.14";
import { LifecycleArgs, lifecycleAssets } from "./lifecycle-input.ts";
const control = {
    process_id: 17,
    runtime: "systemd",
    name: "orbit-process-17-worker.service",
    on_demand: false,
    vite: null,
};
const unit =
    "[Unit]\nX-Orbit-Process-ID=17\n[Service]\nExecStart=/bin/sleep 30\n";
Deno.test("lifecycle public unit is digest-bound, serializable, and has no private material input", async () => {
    const hash = Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(unit),
            ),
        ),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    const args = LifecycleArgs.parse({
        control,
        installation: {
            process_id: 17,
            runtime: "systemd",
            name: control.name,
            on_demand: false,
            unit,
            desired: "running",
            content_hash: hash,
            has_environment: false,
            environment_path: "/etc/orbit/processes/17/" + "a".repeat(64) +
                ".env",
            activation_pending: false,
            retired: null,
        },
    });
    const result = await lifecycleAssets(args);
    assertEquals(result.unit, unit);
    assertEquals(result.container_argv, []);
    await assertRejects(() =>
        lifecycleAssets({
            ...args,
            installation: {
                ...args.installation!,
                content_hash: "b".repeat(64),
            },
        })
    );
    await assertRejects(() =>
        lifecycleAssets({
            ...args,
            installation: {
                ...args.installation!,
                retired: { runtime: "systemd", name: control.name },
            },
        })
    );
});
Deno.test("Docker public input refuses private environment and shell image syntax before effects", () => {
    const value = {
        control: {
            ...control,
            runtime: "docker",
            name: "orbit-process-17-worker",
        },
        installation: {
            process_id: 17,
            runtime: "docker",
            name: "orbit-process-17-worker",
            desired: "stopped",
            on_demand: false,
            spec: "a".repeat(64),
            has_environment: true,
            retired: null,
            definition: {
                image: "example:1",
                command: ["sleep", "30"],
                restart: "no",
                working_directory: null,
                ports: [],
                mounts: [],
            },
        },
    };
    LifecycleArgs.parse(value);
    for (
        const definition of [{
            ...value.installation.definition,
            environment: { PASSWORD: "private" },
        }, {
            ...value.installation.definition,
            image: "image; touch /tmp/other",
        }]
    ) {
        assertThrows(() =>
            LifecycleArgs.parse({
                ...value,
                installation: { ...value.installation, definition },
            })
        );
    }
});

Deno.test("Docker creation is one detached run with exact public argv and an env-file path", async () => {
    const name = "orbit-process-17-worker";
    const result = await lifecycleAssets(LifecycleArgs.parse({
        control: { ...control, runtime: "docker", name },
        installation: {
            process_id: 17,
            runtime: "docker",
            name,
            desired: "running",
            on_demand: false,
            spec: "a".repeat(64),
            retired: null,
            has_environment: true,
            environment_path: "/etc/orbit/processes/17/" + "b".repeat(64) +
                ".env",
            definition: {
                image: "alpine:3",
                command: ["printf", "two words", "$VALUE"],
                restart: "unless-stopped",
                working_directory: "/work dir",
                ports: [],
                mounts: [],
            },
        },
    }));
    assertEquals(result.container_argv.slice(0, 7), [
        "timeout",
        "20",
        "docker",
        "run",
        "-d",
        "--name",
        name,
    ]);
    assertEquals(result.container_argv.slice(-4), [
        "alpine:3",
        "printf",
        "two words",
        "$VALUE",
    ]);
    assertEquals(result.container_argv.includes("--env-file"), true);
    assertEquals(result.container_argv.includes("/work dir"), true);
});

Deno.test("Valkey replacement passes the admitted health probe ACL command and volume to Docker", async () => {
    const name = "orbit-process-17-valkey";
    const command = ["valkey-server", "--aclfile", "/data/users.acl"];
    const healthcheck = [
        "--health-cmd",
        "valkey-cli PING",
        "--health-interval",
        "2s",
        "--health-timeout",
        "5s",
        "--health-retries",
        "3",
        "--health-start-period",
        "300s",
    ];
    const result = await lifecycleAssets(LifecycleArgs.parse({
        control: { ...control, runtime: "docker", name },
        installation: {
            process_id: 17,
            runtime: "docker",
            name,
            desired: "running",
            on_demand: false,
            spec: "a".repeat(64),
            retired: null,
            has_environment: false,
            definition: {
                image: "valkey/valkey:8.2",
                command,
                restart: "unless-stopped",
                working_directory: "/data",
                ports: ["10.44.0.1:6379:6379"],
                mounts: [{
                    source: "orbit-valkey-data",
                    target: "/data",
                    read_only: false,
                }],
                healthcheck_arguments: healthcheck,
            },
        },
    }));
    const argv = result.container_argv;
    const probe = argv.indexOf("--health-cmd");
    assertEquals(argv.slice(probe, probe + healthcheck.length), healthcheck);
    assertEquals(argv.slice(-4), ["valkey/valkey:8.2", ...command]);
    const mount = argv.indexOf("--mount");
    assertEquals(
        argv[mount + 1],
        "type=volume,source=orbit-valkey-data,target=/data",
    );
});

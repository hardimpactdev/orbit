import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { observe, plan } from "./mod.ts";
import {
    LifecycleArgs,
    lifecycleAssets,
} from "../process-commands/lifecycle-input.ts";
const container = {
    image: "ghcr.io/plausible/community-edition:v3.3.0",
    command: ["/entrypoint.sh", "run"],
    restart: "unless-stopped",
    working_directory: null,
    ports: [],
    mounts: [],
    process_id: 9,
    container: "orbit-process-9-plausible",
    desired: "running",
    spec: "b".repeat(64),
};
const runId = "0198e15c-bf97-7c23-8f1f-61b8fe67a844";
export const intent = {
    version: "3.3.0",
    previous_version: "3.2.1",
    process_id: 9,
    assignment_id: 1,
    before: {
        ...container,
        image: "ghcr.io/plausible/community-edition:v3.2.1",
        spec: "a".repeat(64),
    },
    after: container,
    health_url: "http://192.0.2.3:8000/api/health",
    ssh_execution: {
        control_node_id: 209,
        identity_file: "/approved",
        target: {
            name: "target",
            host: "192.0.2.3",
            user: "orbit",
            port: 22,
            host_keys: ["ssh-ed25519 fixture"],
        },
    },
};
Deno.test("public plan matches the canonical Process lifecycle schema for before and after", async () => {
    const value = plan(intent, runId);
    for (const child of [value.before, value.after]) {
        const result = await lifecycleAssets(
            LifecycleArgs.parse({
                control: child.control,
                installation: child.installation,
            }),
        );
        assertEquals(
            result.container_argv.slice(-3),
            [
                child.installation.definition.image,
                ...child.installation.definition.command,
            ],
        );
        assertEquals(result.container_argv.includes("--env-file"), true);
        assertEquals(
            result.container_argv.includes(child.installation.environment_path),
            true,
        );
        assertEquals(
            result.container_argv.some((value) =>
                value.includes("SECRET_KEY_BASE=")
            ),
            false,
        );
    }
    assertEquals(value.control.name, "orbit-process-9-plausible");
    assertEquals(value.after.installation.spec, intent.after.spec);
});
Deno.test("owned current spec selects replacement and already-current spec skips it", () => {
    const row = (spec: string) => `true\nprocess\n9\n${spec}\nrunning\n`;
    assertEquals(observe(intent, row(intent.before.spec)), { replace: true });
    assertEquals(observe(intent, row(intent.after.spec)), { replace: false });
    assertThrows(() => observe(intent, row("c".repeat(64))));
    assertThrows(() =>
        observe(intent, row(intent.before.spec).replace("process", "foreign"))
    );
});
Deno.test("runtime plan refuses mismatched Process and image before host effects", () => {
    assertThrows(() => plan({ ...intent, process_id: 10 }, "/scripts"));
    assertThrows(() =>
        plan({
            ...intent,
            after: { ...container, image: "foreign/image:v3.3.0" },
        }, "/scripts")
    );
});
Deno.test("initialization runs the new image once per phase with the private env on stdin", () => {
    const value = plan(
        {
            ...intent,
            after: {
                ...intent.after,
                mounts: [{
                    source: "plausible-data",
                    target: "/var/lib/plausible",
                    read_only: false,
                }],
            },
        },
        runId,
    );
    assertEquals(value.createdb_argv, [
        "/usr/bin/docker",
        "--host=unix:///var/run/docker.sock",
        "run",
        "--rm",
        "--name",
        `orbit-analytics-${runId}-createdb`,
        "--env-file",
        "/dev/stdin",
        "--volume",
        "plausible-data:/var/lib/plausible",
        "ghcr.io/plausible/community-edition:v3.3.0",
        "/entrypoint.sh",
        "db",
        "createdb",
    ]);
    assertEquals(value.migrate_argv.slice(-2), ["db", "migrate"]);
    assertThrows(() => plan(intent, "not-a-run"));
});

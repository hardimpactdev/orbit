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
    const value = plan(intent, "/shipped/scripts");
    for (const child of [value.before, value.after]) {
        const result = await lifecycleAssets(
            LifecycleArgs.parse({
                control: child.control,
                installation: child.installation,
            }),
            "/shared/scripts",
        );
        assertEquals(
            JSON.parse(result.intent_json).installation.definition.environment,
            undefined,
        );
    }
    assertEquals(value.control.name, "orbit-process-9-plausible");
    assertEquals(JSON.parse(value.definition_json).spec, intent.after.spec);
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

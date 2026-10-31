import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.19";
import { behavior, RoleTools } from "./role_tool_assets.ts";
import { roleToolSteps } from "./role_tool_steps.ts";
const base = {
    schema_version: 1 as const,
    node_id: 73,
    user: "orbit",
    recipe_identity: "fixed-recipe",
    apt: {
        snapshot: "20261029T000000Z",
        packages: [{ name: "curl", version: "1.0.0", artifact: null }],
    },
    javascript: null,
    browser: null,
    composer: null,
    caddy: null,
};
const ctx = {
    extensionFile: (path: string) => "/cache/" + path,
    writeResource: () => Promise.resolve(),
};
Deno.test("tool metadata reads only public fixed installers and serializes immutable manager input", async () => {
    const value = await behavior.methods.read.execute({ intent: base }, ctx);
    assertEquals(value.scripts_directory, "/cache/tool_scripts");
    assertEquals(JSON.parse(value.apt_json), base.apt);
    assertEquals(value.javascript_json, null);
    assertThrows(() => RoleTools.parse({ ...base, user: "root" }));
    assertThrows(() =>
        RoleTools.parse({
            ...base,
            apt: {
                ...base.apt,
                packages: [{ name: "--option", version: "1", artifact: null }],
            },
        })
    );
});
Deno.test("tool receipt requires installer proof and includes canonical Caddy only after its action", async () => {
    assertEquals(
        await behavior.methods.result.execute({
            intent: base,
            apt: '{"apt_ready":true}',
            javascript: null,
            browser: null,
            composer: null,
            caddy: null,
        }, ctx),
        { receipt: { ready: true, node_id: 73, packages: ["curl"] } },
    );
    for (
        const apt of [
            "{}",
            '{"apt_ready":false}',
            '{"error_code":"node.tool_apt_failed"}',
        ]
    ) {
        await assertRejects(() =>
            behavior.methods.result.execute({
                intent: base,
                apt,
                javascript: null,
                browser: null,
                composer: null,
                caddy: null,
            }, ctx)
        );
    }
    const intent = {
        ...base,
        composer: { user: "orbit", version: "2.9.5-1" },
        caddy: {
            release: "2.11.7",
            minimum_version: "2.11.0",
            amd64_sha512: "a".repeat(128),
        },
    };
    await assertRejects(() =>
        behavior.methods.result.execute({
            intent,
            apt: '{"apt_ready":true}',
            javascript: null,
            browser: null,
            composer: null,
            caddy: null,
        }, ctx)
    );
    assertEquals(
        (await behavior.methods.result.execute({
            intent,
            apt: '{"apt_ready":true}',
            javascript: null,
            browser: null,
            composer: '{"composer_ready":true}',
            caddy: { caddy_installed: true },
        }, ctx)).receipt.packages,
        ["caddy", "curl"],
    );
});
Deno.test("role tool steps retain full pins, one exec per manager, native guards and terminal proof dependencies", () => {
    const globals = {
        name: "pinned",
        transport: { kind: "ssh", identityFile: "/approved" },
        hosts: [{
            name: "target",
            address: "10.44.0.73",
            transport: { hostKeys: ["ssh-ed25519 admitted"] },
        }],
    };
    const steps = roleToolSteps("tools", "inputs.tools", globals, "bootstrap");
    for (const step of steps) {
        assertEquals(step.target, "orbit-node-209");
        assertEquals(step.labels, { node: "209" });
    }
    for (const manager of ["apt", "javascript", "composer"]) {
        const step = steps.find((s) => s.name === "tools_" + manager)!;
        const task = step.task as Record<string, unknown>,
            args = task.inputs as Record<string, unknown>;
        assertEquals(task.behaviorType, "@swamp/ssh");
        assertEquals(task.methodName, "exec");
        assertEquals(task.globalArgs, globals);
        assertEquals(args.argv instanceof Array, true);
        assertEquals(
            args.stdin,
            '${{ steps["tools_assets"].outputs.' + manager + "_json }}",
        );
    }
    const deps = (steps.at(-1)!.dependsOn as { step: string }[]).map((item) =>
        item.step
    );
    assertEquals(
        deps.includes("tools_apt") && deps.includes("tools_caddy_command") &&
            deps.includes("tools_javascript") &&
            deps.includes("tools_composer"),
        true,
    );
});

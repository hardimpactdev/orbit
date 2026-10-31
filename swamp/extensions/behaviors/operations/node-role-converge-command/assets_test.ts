import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { behavior } from "./assets.ts";
import { roleBaselineSteps } from "./steps.ts";

const context = {
    extensionFile: (name: string) => new URL(name, import.meta.url).pathname,
    writeResource: () => Promise.resolve(),
};

Deno.test("baseline assets deliver admitted public input without treating it as Python", async () => {
    const input = { root: '/home/orbit/a"b', user: "orbit", group: "orbit" };
    const asset = await behavior.methods.read.execute({
        action: "storage",
        input,
    }, context);
    const result = await new Deno.Command("python3", {
        args: [
            "-c",
            asset.script_body.replace(
                'if __name__ == "__main__":',
                "print(json.dumps(ROLE_INPUT))\nif False:",
            ),
        ],
    }).output();
    assertEquals(result.code, 0);
    assertEquals(JSON.parse(new TextDecoder().decode(result.stdout)), input);
});

Deno.test("baseline action refuses foreign paths, resource names and uncertain outcomes", async () => {
    for (
        const args of [
            { action: "directory" as const, input: { path: "/etc" } },
            {
                action: "ci_resource" as const,
                input: { resource: "instances" },
            },
            {
                action: "storage" as const,
                input: { root: "/home/../etc", user: "orbit", group: "orbit" },
            },
        ]
    ) {
        await assertRejects(() => behavior.methods.read.execute(args, context));
    }
    await assertRejects(() =>
        behavior.methods.decode.execute({
            stdout: '{"ready":false,"error_code":"ci_runner.host_failed"}',
        }, context)
    );
    assertEquals(
        await behavior.methods.decode.execute({
            stdout: '{"ready":true,"changed":false}',
        }, context),
        { ready: true, changed: false },
    );
});

Deno.test("baseline composition places every action on209 and passes full pinned official SSH globals", () => {
    const globals = {
        name: "roles",
        transport: { kind: "ssh", user: "orbit", identityFile: "/identity" },
        hosts: [{ name: "target", address: "10.44.0.13", hostKeys: ["PIN"] }],
    };
    const steps = roleBaselineSteps(
        "ci_network",
        "ci_resource",
        { resource: "network" },
        globals,
        "ci_acl",
    );
    assertEquals(steps.map((step) => step.target), [
        "orbit-node-209",
        "orbit-node-209",
        "orbit-node-209",
    ]);
    assertEquals(steps[0].dependsOn, [{
        step: "ci_acl",
        condition: { type: "succeeded" },
    }]);
    const task = steps[1].task as Record<string, unknown>;
    assertEquals(task.behaviorType, "@swamp/ssh");
    assertEquals(task.methodName, "script");
    assertEquals(task.globalArgs, globals);
    assertEquals((task.inputs as Record<string, unknown>).hosts, "name:target");
});

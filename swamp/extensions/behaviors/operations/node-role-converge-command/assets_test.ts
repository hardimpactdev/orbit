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
    assertEquals(JSON.parse(new TextDecoder().decode(result.stdout)), {
        ...input,
        create_missing: true,
    });
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

Deno.test("private role files consume only the whole execution copy through official exec stdin", () => {
    const globals = { name: "role-files" };
    const steps = roleBaselineSteps(
        "metrics_config",
        "file",
        {
            path: "/etc/orbit/metrics/prometheus.yml",
            mode: "0400",
            owner: "root:root",
            content: null,
            material_field: "configuration",
        },
        globals,
        "packages",
        "vault.get(inputs.execution_secrets.runtime_material.vault, inputs.execution_secrets.runtime_material.key)",
    );
    const task = steps[1].task as Record<string, unknown>;
    const inputs = task.inputs as Record<string, unknown>;
    assertEquals(task.methodName, "exec");
    assertEquals(task.globalArgs, globals);
    assertEquals(
        inputs.stdin,
        "${{ vault.get(inputs.execution_secrets.runtime_material.vault, inputs.execution_secrets.runtime_material.key) }}",
    );
    assertEquals(inputs.argv, [
        "/usr/bin/python3",
        "-c",
        '${{ steps["metrics_config_assets"].outputs.script_body }}',
    ]);
    assertEquals(inputs.sudo, true);
    assertEquals("env" in inputs, false);
    assertEquals("script" in inputs, false);
});

Deno.test("observer files retain public templates and resolve only admitted checkout access", async () => {
    const { observerFiles } = await import("./assets.ts");
    const args = {
        configuration: 'gateway_url = "https://gateway.orbit"\n',
        certificate: "public-ca",
        unit: "# Managed by Orbit: agent\n[Service]\nProtectHome=yes\n",
        apps_root: null,
        home: "/home/orbit",
    };
    assertEquals(observerFiles(args), {
        configuration: args.configuration,
        certificate: args.certificate,
        unit:
            "# Managed by Orbit: agent\n[Service]\nProtectHome=tmpfs\nBindReadOnlyPaths=-/home/orbit/apps\n",
    });
    assertEquals(
        observerFiles({ ...args, apps_root: "/srv/apps" }).unit,
        "# Managed by Orbit: agent\n[Service]\nProtectHome=tmpfs\n",
    );
});

Deno.test("parent tools must prove the same Node and every required OS package", async () => {
    const { preparedRoleTools } = await import("./assets.ts");
    const args = {
        node_id: 7,
        required_packages: ["caddy", "composer"],
        contribution: {
            ready: true,
            node_id: 7,
            packages: ["caddy", "composer", "git"],
        },
    };
    assertEquals(preparedRoleTools(args), { ready: true });
    for (
        const contribution of [{ ...args.contribution, node_id: 8 }, {
            ...args.contribution,
            packages: ["caddy"],
        }, { ...args.contribution, ready: false }]
    ) {
        await assertRejects(async () =>
            preparedRoleTools({ ...args, contribution })
        );
    }
});

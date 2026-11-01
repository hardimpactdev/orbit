import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./assets.ts";
import { nodeRemovalWorkflow, roleRemovalWorkflow } from "./workflows.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
import { analyzeYaml } from "../../../../bin/check-swamp-shape.ts";

function context() {
    let output: unknown;
    return {
        extensionFile: (path: string) =>
            new URL(path, import.meta.url).pathname,
        writeResource: (_spec: string, _name: string, value: unknown) => {
            output = value;
            return Promise.resolve({});
        },
        output: () => output,
    };
}
Deno.test("offline consent does not skip a reachable target or authenticate without pins", async () => {
    const ctx = context();
    await behavior.methods.preflight.execute({
        stdout: '{"reachable":true}',
        offline: true,
        has_ssh: true,
    }, ctx);
    assertEquals(ctx.output(), { offline: false });
    await behavior.methods.preflight.execute({
        stdout: '{"reachable":false}',
        offline: true,
        has_ssh: false,
    }, ctx);
    assertEquals(ctx.output(), { offline: true });
    await assertRejects(
        () =>
            behavior.methods.preflight.execute({
                stdout: '{"reachable":false}',
                offline: false,
                has_ssh: true,
            }, ctx),
        Error,
        "node.unreachable",
    );
    await assertRejects(
        () =>
            behavior.methods.preflight.execute({
                stdout: '{"reachable":true}',
                offline: true,
                has_ssh: false,
            }, ctx),
        Error,
        "ssh_target_unavailable",
    );
});
Deno.test("native token selection is exact and cannot revoke shared209 or Gateway access", async () => {
    const ctx = context();
    await behavior.methods.tokens.execute({
        node_id: 701,
        worker_stdout: JSON.stringify([{ name: "orbit-node-701" }, {
            name: "orbit-node-7010",
        }, { name: "orbit-node-209" }]),
        server_stdout: JSON.stringify([{ name: "orbit-node-701-access" }, {
            name: "orbit-gateway",
        }, { name: "orbit-node-209-access" }]),
    }, ctx);
    assertEquals(ctx.output(), {
        worker: ["orbit-node-701"],
        server: ["orbit-node-701-access"],
    });
});
Deno.test("terminal output is typed and reports intentionally retained host data", async () => {
    const ctx = context();
    await behavior.methods.result.execute({
        node_id: 701,
        name: "disposable",
        offline: false,
        roles_shed: ["database"],
        retained_on_node: ["user data"],
    }, ctx);
    const result = ctx.output() as Record<string, unknown>;
    assertEquals(result.removed, true);
    assertEquals(result.follow_up, null);
    assertEquals((JSON.parse(String(result.result_json))).retained_on_node, [
        "user data",
    ]);
});
Deno.test("shipped scripts bind only public input and include canonical certificate paths", async () => {
    const ctx = context();
    await behavior.methods.read.execute({
        action: "remove_certificate",
        input: { scope: "role-metrics" },
    }, ctx);
    const script = (ctx.output() as { script_body: string }).script_body;
    assertEquals(script.includes("def role_directory(scope):"), true);
    assertEquals(script.includes("REMOVAL_INPUT = None"), false);
    assertEquals(script.includes("from role_paths import"), false);
});
Deno.test("published role and parent match their generator and add no shape allowances", async () => {
    for (const value of [nodeRemovalWorkflow(), roleRemovalWorkflow()]) {
        const path = new URL(
            "../../../../workflows/workflow-" + value.name + ".yaml",
            import.meta.url,
        );
        const source = await Deno.readTextFile(path);
        assertEquals(parse(source), value);
        assertEquals(
            analyzeYaml("workflows/" + path.pathname.split("/").at(-1), source)
                .findings,
            [],
        );
    }
});

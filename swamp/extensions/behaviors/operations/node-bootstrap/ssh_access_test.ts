import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { behavior } from "./ssh_access.ts";

Deno.test("tracked key action delivers only admitted public data and refuses a root account", async () => {
    const writes: unknown[] = [];
    const context = {
        extensionFile: (name: string) =>
            new URL(name, import.meta.url).pathname,
        writeResource: (...args: unknown[]) =>
            Promise.resolve(writes.push(args)),
    };
    const result = await behavior.methods.read.execute({
        user: "orbit",
        public_key: 'ssh-ed25519 AAAA quote"',
    }, context);
    assertEquals(result.script_body.includes("BOOTSTRAP_INPUT = None"), false);
    assertEquals(writes, [["assets", "assets", result]]);
    await assertRejects(() =>
        behavior.methods.read.execute({
            user: "root",
            public_key: "ssh-ed25519 AAAA",
        }, context)
    );
    await assertRejects(() =>
        behavior.methods.read.execute({
            user: "orbit",
            public_key: "ssh-ed25519 AAAA\ninjected",
        }, context)
    );
});
Deno.test("readiness requires successful own-key verification and retains only its public result", async () => {
    const writes: unknown[] = [];
    const context = {
        extensionFile: () => "",
        writeResource: (...args: unknown[]) =>
            Promise.resolve(writes.push(args)),
    };
    assertEquals(
        await behavior.methods.result.execute(
            { verification: { exitCode: 0 } },
            context,
        ),
        { stdout: '{"ssh_access_ready":true}' },
    );
    assertEquals(writes.length, 1);
    await assertRejects(() =>
        behavior.methods.result.execute(
            { verification: { exitCode: 1 as 0 } },
            context,
        )
    );
    assertEquals(writes.length, 1);
});

Deno.test("first-key workflow declares its exact bootstrap vault", async () => {
    const { sshAccessWorkflow } = await import("./ssh_access_workflow.ts");
    assertEquals(sshAccessWorkflow().vaults, ["orbit-control-inputs"]);
    const yaml = await Deno.readTextFile(
        new URL(
            "../../../../workflows/workflow-operation-node-ssh-access-converge.yaml",
            import.meta.url,
        ),
    );
    assertEquals(yaml.includes("\nvaults: [orbit-control-inputs]\n"), true);
});

import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { behavior } from "./firewall_assets.ts";
import { roleFirewallSteps } from "./firewall_steps.ts";
const context = { writeResource: () => Promise.resolve() };

Deno.test("native firewall accepts only canonical tuples and requires verified output", async () => {
    const rules = { desired: [], remove: [], enable: false };
    const asset = await behavior.methods.read.execute({ rules }, context);
    assertEquals(asset.script_body.includes('json.load(sys.stdin)'), false);
    await assertRejects(() => behavior.methods.read.execute({
        rules: { ...rules, command: "not-admitted" } as typeof rules,
    }, context));
    await assertRejects(() => behavior.methods.decode.execute({
        stdout: '{"error_code":"node.firewall_convergence_failed"}',
    }, context));
    assertEquals(await behavior.methods.decode.execute({
        stdout: '{"matches":true}',
    }, context), { ready: true });
});

Deno.test("native firewall preserves official SSH globals and one209 selector", () => {
    const globals = { name: "admitted-target", transport: { kind: "ssh", user: "orbit",
        identityFile: "/existing/identity", hostKeys: ["FULL_PIN"] },
        hosts: [{ name: "target", address: "10.44.0.31" }] };
    const steps = roleFirewallSteps("trust", "inputs.rules", globals, "snapshot", "inputs.blocked");
    for (const step of steps) {
        assertEquals(step.target, "orbit-node-209");
        assertEquals(step.labels, undefined);
        assertEquals(step.guard, "${{ inputs.blocked }}");
    }
    const task = steps[1].task as Record<string, unknown>;
    assertEquals(task.behaviorType, "@swamp/ssh");
    assertEquals(task.globalArgs, globals);
    assertEquals((task.inputs as Record<string, unknown>).hosts, "name:target");
});

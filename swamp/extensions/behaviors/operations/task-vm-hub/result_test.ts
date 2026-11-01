import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { hubResult } from "./result.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
function input(source = "10.44.0.15", port: unknown = 22) {
    const match = (protocol: string, field: string, right: unknown) => ({
        match: { op: "==", left: { payload: { protocol, field } }, right },
    });
    return {
        node_id: 1,
        source: "10.44.0.15",
        range: "10.44.0.128/25",
        stdout: JSON.stringify({
            nftables: [{
                rule: {
                    family: "inet",
                    table: "orbit_task_vms",
                    chain: "forward",
                    comment: "orbit:task-vm-control-ssh",
                    expr: [
                        match("ip", "saddr", source),
                        match("ip", "daddr", {
                            prefix: { addr: "10.44.0.128", len: 25 },
                        }),
                        match("tcp", "dport", port),
                        { accept: null },
                    ],
                },
            }],
        }),
    };
}
Deno.test("hub proof requires exact control source and TCP22 without Pi access", () => {
    assertEquals(JSON.parse(hubResult(input()).stdout), {
        ready: true,
        node_id: 1,
        control_ssh_source: "10.44.0.15",
        control_ssh_port: 22,
    });
    assertThrows(() => hubResult(input("10.44.0.2")));
    assertThrows(() => hubResult(input("10.44.0.15", { set: [22, 3774] })));
    assertThrows(() => hubResult({ ...input(), stdout: '{"nftables":[]}' }));
});
Deno.test("hub workflow uses one209 selector and validates before visible effects", async () => {
    const flow = parse(
        await Deno.readTextFile(
            new URL(
                "../../../../workflows/workflow-operation-task-vm-hub-converge.yaml",
                import.meta.url,
            ),
        ),
    ) as {
        jobs: { steps: { name: string; target: string; labels?: unknown }[] }[];
    };
    for (const step of flow.jobs[0].steps) {
        assertEquals(step.target, "orbit-node-209");
        assertEquals("labels" in step, false);
    }
    const names = flow.jobs[0].steps.map((s) => s.name);
    assertEquals(names[0], "validate");
    assertEquals(names.indexOf("activate") < names.indexOf("observe"), true);
});

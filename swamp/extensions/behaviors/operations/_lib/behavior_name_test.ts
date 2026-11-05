import { assertEquals } from "jsr:@std/assert@1.0.19";
import { behaviorName } from "./behavior_name.ts";

const ssh = {
    hosts: [{
        name: "target",
        address: "${{ inputs.intent.ssh.target.host }}",
    }],
};

Deno.test("an SSH step is named after its address, a local step is fixed", () => {
    assertEquals(
        behaviorName("account", ssh),
        '${{ ("account-" + string(inputs.intent.ssh.target.host)).split(".").join("-") }}',
    );
    assertEquals(behaviorName("result", undefined), '${{ "result" }}');
    assertEquals(
        behaviorName("unit", {
            nodeHost: "${{ inputs.intent.ssh_execution.target.host }}",
        }),
        '${{ ("unit-" + string(inputs.intent.ssh_execution.target.host)).split(".").join("-") }}',
    );
});

Deno.test("a fan-out adds its index unless each item names its own target", () => {
    assertEquals(
        behaviorName("keys", ssh, true),
        '${{ ("keys-" + string(inputs.intent.ssh.target.host) + "-" + string(self._index)).split(".").join("-") }}',
    );
    assertEquals(
        behaviorName("stop", {
            hosts: [{ address: "${{ self.action.ssh.target.host }}" }],
        }, true),
        '${{ ("stop-" + string(self.action.ssh.target.host)).split(".").join("-") }}',
    );
    assertEquals(
        behaviorName("assets", undefined, true),
        '${{ "assets-" + string(self._index) }}',
    );
});

import { assertEquals } from "jsr:@std/assert@1.0.19";
import {
    invalidName,
    perRunNames,
    sharedAcrossTypes,
} from "./lint-behavior-names.ts";

const workflow = (steps: unknown[]) => ({ jobs: [{ name: "main", steps }] });
const step = (name: string, behaviorName: string) => ({
    name,
    task: { type: "behavior", behaviorType: "@swamp/ssh", behaviorName },
});

Deno.test("a behavior named by run or Operation is reported", () => {
    assertEquals(
        perRunNames(workflow([
            step("a", '${{ "route-ssh-" + run.id }}'),
            step("b", '${{ "db-" + string(inputs.operation_id) }}'),
            { name: "c", task: { modelName: '${{ "detect-" + run.id }}' } },
        ])).length,
        3,
    );
});

Deno.test("a behavior named by target or fixed is allowed", () => {
    assertEquals(
        perRunNames(workflow([
            step(
                "a",
                '${{ "route-ssh-" + string(inputs.intent.ssh_execution.target.host) }}',
            ),
            step("b", '${{ "gateway-swamp-load" }}'),
            step("c", '${{ "assets-" + string(self._index) }}'),
        ])),
        [],
    );
});

Deno.test("a per-run behavior the workflow deletes is allowed", () => {
    assertEquals(
        perRunNames(workflow([
            step("a", '${{ "scratch-" + run.id }}'),
            {
                name: "delete",
                task: {
                    type: "behavior",
                    behaviorType: "command/shell",
                    inputs: { run: 'swamp behavior delete "scratch-$RUN"' },
                },
            },
        ])),
        [],
    );
});

Deno.test("a name Swamp would refuse at run time fails here", () => {
    for (
        const name of [
            '${{ "horizon-ssh-" + string(inputs.intent.ssh_execution.target.host) }}',
            '${{ "db-" + inputs.scope }}',
            '${{ "Route-" + string(inputs.node_id) }}',
            '${{ "route.ssh-" + string(inputs.node_id) }}',
            "release-node-${{ self.node.host }}",
            "Bad_Name",
        ]
    ) assertEquals(typeof invalidName(name), "string", name);
});

Deno.test("dotless, integer and passed-in names are valid", () => {
    for (
        const name of [
            '${{ ("horizon-ssh-" + string(inputs.intent.ssh_execution.target.host)).split(".").join("-") }}',
            '${{ "db-" + string(inputs.node_id) + "-" + string(self._index) }}',
            '${{ "metrics-" + string(steps.capture.outputs.plan != null ? steps.capture.outputs.plan.metrics_node_id : inputs.node_id) }}',
            '${{ "doctor-read-" + run.id }}',
            "${{ inputs.ssh.name }}",
            "fleet-release-node-${{ self.node.node_id }}",
            "gateway-swamp-load",
        ]
    ) assertEquals(invalidName(name), null, name);
});

Deno.test("one behavior name used with two behavior types fails", () => {
    const workflow = (type: string, name = '${{ "result" }}') => ({
        jobs: [{
            name: "main",
            steps: [{
                name: "r",
                task: { behaviorType: type, behaviorName: name },
            }],
        }],
    });
    assertEquals(
        sharedAcrossTypes({
            a: workflow("command/shell"),
            b: workflow("command/shell"),
        }),
        [],
    );
    assertEquals(
        sharedAcrossTypes({
            a: workflow("command/shell"),
            b: workflow("@nckrtl/network-command", "result"),
        }).length,
        1,
    );
    assertEquals(
        sharedAcrossTypes({
            a: workflow("command/shell"),
            b: workflow("@nckrtl/network-command"),
        }).length,
        1,
    );
});

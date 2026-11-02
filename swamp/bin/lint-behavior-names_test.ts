import { assertEquals } from "jsr:@std/assert@1.0.19";
import { perRunNames } from "./lint-behavior-names.ts";

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

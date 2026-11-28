/**
 * Tool Manager setup installs only missing prerequisites (docs/reference/tools.md#where-tools-run-and-tool-managers): apt never upgrades an installed
 * prerequisite and never moves a held package, though it may upgrade an unheld dependency that a missing prerequisite needs.
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { type GraphJob, environment } from "./_support/swamp_workflow.ts";
import { swampExpression } from "./_support/swamp_expression.ts";

const flow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-tool-manager-setup.yaml", import.meta.url))) as {
    jobs: GraphJob[];
};
const step = flow.jobs.flatMap((job) => job.steps).find((s) => s.name === "Install prerequisites")!;

Deno.test("setup asks apt for the prerequisites without upgrading an installed one", () => {
    const packages = ["build-essential", "procps", "curl", "file", "git", "ca-certificates"];
    const argv = environment.evaluate(swampExpression(step.task.inputs!.argv), {
        steps: { "Prepare manager commands": { outputs: { packages } } },
    });
    assertEquals(argv, [
        "env", "DEBIAN_FRONTEND=noninteractive", "apt-get", "install", "--yes", "--no-upgrade", "--", ...packages,
    ]);
});

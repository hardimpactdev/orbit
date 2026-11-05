import { assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../bin/gate-test-support.ts";
const Workflow = "operation-task-vm-cache-fill";

Deno.test("the vault is read only for the GitHub token", async () => {
    // Serve redacts every vault value it reads with a RegExp built from the value; a whole agent request
    // as a vault value fails the step before SSH (Operation 8235). Only the small token may come from the vault.
    const source = await Deno.readTextFile(
        `${ROOT}/workflows/workflow-${Workflow}.yaml`,
    );
    assertEquals(
        [...source.matchAll(/vault\.get\(([^)]*)\)/g)].map((match) => match[1]),
        [
            "inputs.execution_secrets.github_token.vault, inputs.execution_secrets.github_token.key",
        ],
    );
});

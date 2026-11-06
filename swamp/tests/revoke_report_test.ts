import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = { name: string; task?: { workflowIdOrName?: string; methodName?: string; behaviorType?: string } };
type Workflow = { name: string; jobs: { name: string; steps: Step[] }[] };

const workflows = new URL("../workflows/", import.meta.url);
const definitions = new URL("../../apps/gateway/app/Domain/Tasks/direct-operations.php", import.meta.url);

async function workflow(name: string): Promise<Workflow> {
    return parse(await Deno.readTextFile(new URL(`workflow-${name}.yaml`, workflows))) as Workflow;
}
const steps = (flow: Workflow, job: string): Step[] => flow.jobs.find((candidate) => candidate.name === job)?.steps ?? [];

Deno.test("the package result captures the token outcome from a step that runs the remove child", async () => {
    const php = await Deno.readTextFile(definitions);
    const capture = php.match(
        /'captures' => \['dependency_token' => \['job' => '([^']+)', 'step' => '([^']+)', 'field' => '([^']+)'\]\]/,
    );
    assert(capture !== null, "the tasks.package definition names no dependency_token capture");
    const [, job, step, field] = capture;
    const selected = steps(await workflow("operation-task-package"), job).find((candidate) => candidate.name === step);

    assertEquals(selected?.task?.workflowIdOrName, "task-dependency-auth-remove", `${job}/${step} is not the remove child`);
    // A workflow step's outputs are keyed by the child's step names, so the field is the child's reporting step.
    const reporter = steps(await workflow("task-dependency-auth-remove"), "remove").find((candidate) => candidate.name === field);
    assertEquals(reporter?.task?.methodName, "revoke");
});

Deno.test("provision asks for the token beside the clone credential, writes both files in one stage, and revokes beside the removal", async () => {
    const source = steps(await workflow("task-workspace-source"), "source");
    const byName = Object.fromEntries(source.map((step) => [step.name, step]));
    const needs = (name: string) => ((byName[name] as unknown as { dependsOn?: { step: string }[] }).dependsOn ?? []).map((edge) => edge.step);

    assertEquals(byName.deps_mint.task?.workflowIdOrName, "task-github-mint");
    assertEquals(needs("deps_mint"), ["control"]);
    assertEquals(byName.deps_revoke.task?.behaviorType, "command/shell");
    // The two writes depend on the same steps, so one barrier covers both; neither waits for the other.
    assertEquals(needs("deps_write_composer"), needs("deps_write_npm"));
    assert(!needs("deps_write_npm").includes("deps_write_composer"), "the npm write must not wait for the composer write");
    // The removal and the revoke are siblings: the revoke does not wait for the files to go.
    assert(!needs("deps_revoke").includes("deps_remove_files") && !needs("deps_remove_files").includes("deps_revoke"));
    const text = await Deno.readTextFile(new URL("workflow-task-workspace-source.yaml", workflows));
    assertEquals(text.split('guard: ${{ !("deps_mint" in steps) }}').length - 1, 2, "the removal and the revoke are skipped when no token was asked for");
});

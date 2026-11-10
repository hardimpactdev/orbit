import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

/**
 * Swamp evaluates a skipped step's inputs too (docs/reference/swamp-command-steps.md), so an expression that reads a vault key or an
 * intent section that a run lacks fails that whole run with `No such key`. This evaluates every step input expression that reads
 * `inputs.execution_secrets` or `inputs.intent.read`, as each kind of run hands it over, and requires that none of them fails.
 * Expressions over `steps.` or a `forEach` item (`self.`) are left out: Swamp evaluates those per item or after the steps they name.
 */
const environment = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true })
  .registerFunction("vaultget(dyn, dyn): string", (vault: unknown, key: unknown) => `${vault}/${key}`);

type Workflow = { jobs: { steps: { name: string; task?: unknown }[] }[] };

function failures(workflow: string, inputs: Record<string, unknown>): string[] {
  const parsed = parse(Deno.readTextFileSync(new URL(`../workflows/${workflow}`, import.meta.url))) as Workflow;
  const failed: string[] = [];
  const evaluate = (step: string, value: unknown): void => {
    if (typeof value === "string") {
      for (const match of value.matchAll(/\$\{\{([\s\S]*?)\}\}/g)) {
        const expression = match[1].trim();
        if (!/execution_secrets|intent\.read/.test(expression) || /\b(self|steps)\./.test(expression)) continue;
        try {
          environment.evaluate(expression.replaceAll("vault.get(", "vaultget("), { inputs, run: { id: "run-1" } });
        } catch (error) {
          failed.push(`${step}: ${(error as Error).message.split("\n")[0]}`);
        }
      }
    } else if (value !== null && typeof value === "object") {
      for (const item of Object.values(value)) evaluate(step, item);
    }
  };
  for (const job of parsed.jobs) for (const step of job.steps) evaluate(step.name, step.task);
  return failed;
}

const copy = (key: string) => ({ vault: "orbit-control-inputs", key });

Deno.test("a checkout create reads no key that only a container create has", () => {
  assertEquals(failures("workflow-operation-instance-create.yaml", {
    execution_secrets: { git_credential: copy("instance-git-credential-1"), environment: copy("instance-create-environment-input-1") },
    intent: { container: null, environment_testing: false, server_database: null },
  }), []);
});

Deno.test("a container default's create and a clone's create read their own keys", () => {
  const execution_secrets = {
    git_credential: copy("instance-git-credential-1"), environment: copy("instance-create-environment-input-1"),
    process_environments: copy("instance-create-processes-1"),
  };
  const intent = { container: { clone: null }, environment_testing: false, server_database: null };
  assertEquals(failures("workflow-operation-instance-create.yaml", { execution_secrets, intent }), []);
  assertEquals(failures("workflow-operation-instance-create.yaml", {
    execution_secrets: { ...execution_secrets, model_environment: copy("instance-create-model-1") },
    intent: { ...intent, container: { clone: {} } },
  }), []);
});

Deno.test("a production and a checkout deployment read no key that only a container deployment has", () => {
  for (const variant of ["production", "development"]) {
    assertEquals(failures("workflow-operation-instance-deploy-direct.yaml", {
      execution_secrets: { credential: copy("instance-deploy-operation-1-credential"), environment: copy("instance-environment-1") },
      // Every deployment carries a `production` section; a development one carries it blank.
      intent: { deployment: { variant }, production: { instance_id: 1, home: "/srv/app" } },
    }), [], variant);
  }
});

Deno.test("a container process:start, stop and restart read no `read` section, which only a status or log read has", () => {
  for (const kind of ["process.start", "process.stop", "process.restart"]) {
    assertEquals(failures("workflow-operation-process-container.yaml", {
      kind, execution_secrets: {}, intent: { control: { process_id: 41, name: "orbit-process-41-worker.service" }, container: {} },
    }), [], kind);
  }
});

import { assertFalse } from "jsr:@std/assert@1.0.19";
import { type Data, git, instanceFixture, sha256 } from "./instance_fixture.ts";
export async function createFixture() {
  const f = await instanceFixture("operations");
  const application = `${f.node}/apps/project/proof`;
  await Deno.mkdir(application, { recursive: true });
  git(application, "init", "-q", "-b", "main");
  git(application, "config", "user.email", "fixture@example.test");
  git(application, "config", "user.name", "Fixture");
  git(application, "remote", "add", "origin", "https://github.com/example/project.git");
  await Deno.writeTextFile(`${application}/.env.example`, 'TEMPLATE="template-private-sentinel"\n');
  await Deno.writeTextFile(`${application}/package.json`, '{"name":"fixture"}');
  git(application, "add", ".");
  git(application, "commit", "-qm", "fixture");
  const commit = git(application, "rev-parse", "HEAD");
  await f.workflow("operation-instance-create");
  await f.repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await f.repo.cli(["vault", "put", "orbit-control-inputs", "instance-git-credential-71", ""]);
  const inputs: Data = {
    operation_id: 71, node_id: 9, kind: "instance.create",
    execution_secrets: {
      environment: { vault: "orbit-control-inputs", key: "instance-create-environment-input-71" },
      git_credential: { vault: "orbit-control-inputs", key: "instance-git-credential-71" },
    },
    intent: {
      instance_id: 17, instance_name: "proof", already_active: false, ssh_execution: f.connection,
      source: {
        root: `${f.node}/apps`,
        checkout: application, user: f.user,
        repository: "https://github.com/example/project.git",
        prepare_id: "admitted-prepare", seed_repository: null,
      },
      resolve: { branch: "main", default_branch: "main", seed_commit: null, network: true, protocols: ["https"] },
      checkout: application, application, project_type: "node-package", entry_point: "artisan", php_versions: ["8.4"],
      skip_profile: false,
      retained_source: { commit },
      processes: [], setup_steps: [], environment_directory: application,
    },
  };
  const environment = async (content: string, directory = application) => {
    await f.repo.cli(["vault", "put", "orbit-control-inputs", "instance-create-environment-input-71", content]);
    inputs.intent.environment_directory = directory;
  };
  await environment('STORED="stored-private-sentinel"\n');
  const setup = async (commands: string[]) => {
    inputs.intent.setup_steps = await Promise.all(
      commands.map(async (command, index) => ({
        index,
        name: `step-${index}`,
        command, sha256: await sha256(command), timeout_seconds: 30, directory: application, instance_id: 17,
        instance_name: "proof", seed_path: "", seed_commit: "",
      })),
    );
  };
  const run = async (success = true) => {
    const out = await f.run("operation-instance-create", inputs, success);
    assertFalse(JSON.stringify(out.history).includes("stored-private-sentinel"));
    assertFalse(JSON.stringify(out.history).includes("No such key"));
    return { ...out, result: JSON.parse(out.steps.finish.outputs.stdout) };
  };
  return { f, application, inputs, commit, environment, setup, run };
}

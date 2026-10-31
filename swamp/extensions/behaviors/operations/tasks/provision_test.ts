import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import type { Http } from "../../automatic-deploy/gateway.ts";
import {
  args as vmArgs,
  provisionFixture,
} from "../task-vms/provision_fixture.ts";
import {
  nativeTaskProvision,
  provisionAnalysisBase,
  ProvisionedWorkspace,
  TaskProvisionInput,
} from "./provision.ts";

const args = { ...vmArgs, kind: "tasks.provision" as const };
const commit = "c".repeat(40);
function fixture(setupExit = 0) {
  const vm = provisionFixture();
  const input = TaskProvisionInput.parse({
    ...vm.input,
    group_id: 11,
    instance_id: 31,
    project_id: 60,
    repository: "https://github.com/example/canary.git",
    repository_identity: "github.com/example/canary",
    direct_provisioning: {
      instance_id: 31,
      project_id: 60,
      node_id: 208,
      node_user: "orbit",
      platform: "linux",
      architecture: "x86_64",
      vp_materialize: { user: "orbit" },
      environment: "development",
      user: "orbit",
      checkout: "/home/orbit/apps/canary/workspace",
      application: "/home/orbit/apps/canary/workspace",
      project_type: "monorepo",
      entry_point: "artisan",
      skip_profile: true,
      php_versions: ["8.5"],
      source: {
        repository: "https://github.com/example/canary.git",
        checkout: "/home/orbit/apps/canary/workspace",
        root: "/home/orbit/apps/canary",
        user: "orbit",
        prepare_id: args.run_id,
        seed_repository: null,
      },
      resolve: {
        instance_name: "task-11",
        default_branch: "main",
        branch_override: "task-11",
        seed_commit: null,
        network: true,
        protocols: ["https"],
      },
      setup: {
        instance_id: 31,
        project_id: 60,
        environment: "development",
        list: "setup",
        execution: {
          variant: "checkout",
          user: "orbit",
          directory: "/home/orbit/apps/canary/workspace",
          seed_path: "",
          seed_commit: "",
        },
        steps: [],
      },
      already_active: false,
      retained_source: null,
    },
  });
  const effects: string[] = [];
  const programs: { name: string; command: string; timeout_seconds: number }[] =
    [];
  const http: Http = async (method, path, body) => {
    if (path === "/api/v1/projects/60/setup-steps") {
      return { status: 200, body: { data: programs } };
    }
    if (path.includes("/private-material")) {
      return {
        status: 200,
        body: { data: { git_read: { github_token: "fixture-secret" } } },
      };
    }
    const response = await vm.http(method, path, body);
    return {
      ...response,
      body: { data: { ...vm.operation, kind: args.kind, input } },
    };
  };
  const model = nativeTaskProvision(() => http, vm.actions, {
    identity: () =>
      Promise.resolve({
        node_id: 208,
        user: "orbit",
        hostname: "tvm-5",
        effective_user: "orbit",
        sudo_ready: true,
      }),
    invoke: (call) => {
      effects.push(call.name);
      if (call.name === "account.lookup") {
        return Promise.resolve({ user: "orbit", group: "orbit" });
      }
      if (call.name === "instance.resolve-development-source") {
        return Promise.resolve({ branch: "task-11", commit });
      }
      return Promise.resolve({});
    },
    run: (step, program) => {
      effects.push(`setup.${step.name}:${program}`);
      return Promise.resolve({
        exit_code: setupExit,
        timed_out: false,
        output_tail: "",
        truncated: false,
      });
    },
    base: () => Promise.resolve(commit),
  });
  const project = { ...args, admission: vm.admission, outcomes: vm.outcomes };
  return { ...vm, model, input, effects, project, programs };
}

Deno.test("Tasks admission uses its actual kind and records running before provider work", async () => {
  const f = fixture();
  await f.model.methods.admit.execute(args, f.context);
  assertEquals(f.operation.status, "running");
  assertEquals(f.stages, []);
  await f.model.methods.stage.execute({
    ...args,
    admission: f.admission,
    stage: "provider",
    provider: null,
    outcomes: [],
  }, f.context);
  assertEquals(f.stages, ["provider"]);
  assertEquals(
    f.calls.filter((call) => call.method === "POST").every((call) =>
      call.path.startsWith("/api/v1/direct-operations/7/")
    ),
    true,
  );
});

Deno.test("workspace source and setup must finish before the Tasks root succeeds", async () => {
  const f = fixture();
  await f.model.methods.workspace.execute(f.project, f.context);
  assertEquals(f.operation.status, "queued");
  assertEquals(f.effects, [
    "account.lookup",
    "instance.prepare-development-source",
    "account.lookup",
    "instance.resolve-development-source",
  ]);
  const workspace = ProvisionedWorkspace.parse(f.resources.get("workspace"));
  await f.model.methods.project.execute({ ...f.project, workspace }, f.context);
  assertEquals(f.operation.status, "succeeded");
  assertEquals(f.operation.result, {
    task_vm_id: 5,
    guest_node_id: 208,
    ready: true,
    provider_id: null,
    enrollment: {
      ready: true,
      node: {
        ssh_host_key_type: "ssh-ed25519",
        ssh_host_key: "public-key",
        ssh_host_fingerprint: "SHA256:public",
        address: "10.251.77.2",
        wireguard_public_key: "a".repeat(43) + "=",
      },
      process: { status: "active" },
    },
    source: { branch: "task-11", commit, php_version: null, laravel: false },
    instance_id: 31,
    node_id: 208,
    starting_commit: commit,
    analysis_base: {
      base: commit,
      instance_id: 31,
      repository: f.input.repository,
      branch: "task-11",
    },
  });
  assertEquals(
    JSON.stringify([...f.resources.values()]).includes("fixture-secret"),
    false,
  );
});

Deno.test("VM readiness alone cannot finish a Tasks provision", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      f.model.methods.project.execute(
        { ...f.project, workspace: null },
        f.context,
      ),
    Error,
    "tasks.provision_failed",
  );
  assertEquals(f.operation.status, "failed");
  assertEquals(f.operation.result, { failed_step: "workspace" });
});

Deno.test("incomplete enrollment refuses checkout without running source effects", async () => {
  const f = fixture();
  await f.model.methods.workspace.execute({
    ...f.project,
    outcomes: f.outcomes.slice(0, -1),
  }, f.context);
  assertEquals(f.effects, []);
  assertEquals(
    ProvisionedWorkspace.parse(f.resources.get("workspace")).ok,
    false,
  );
});

Deno.test("prestart and mid-provision cancellation never mark the group ready", async () => {
  const f = fixture();
  f.operation.cancel_requested = true;
  await f.model.methods.workspace.execute(f.project, f.context);
  assertEquals(f.effects, []);
  await f.model.methods.project.execute(
    { ...f.project, workspace: null },
    f.context,
  );
  assertEquals(f.operation.status, "cancelled");
  assertEquals(f.operation.result, {});
});

Deno.test("an already completed root replays without source, setup or result writes", async () => {
  const f = fixture();
  f.operation.status = "succeeded";
  assertEquals(
    await f.model.methods.project.execute(
      { ...f.project, workspace: null },
      f.context,
    ),
    { status: "succeeded" },
  );
  assertEquals(f.effects, []);
  assertEquals(f.calls.filter((call) => call.method === "POST"), []);
});

Deno.test("the guest, Project and source repository are immutable parts of workspace admission", () => {
  const f = fixture();
  for (const field of ["instance_id", "project_id", "node_id"] as const) {
    assertThrows(() =>
      TaskProvisionInput.parse({
        ...f.input,
        direct_provisioning: { ...f.input.direct_provisioning, [field]: 999 },
      })
    );
  }
  assertThrows(() =>
    TaskProvisionInput.parse({
      ...f.input,
      repository: "https://example.com/foreign.git",
    })
  );
});

Deno.test("the Tasks DAG preserves canonical VM placements and adds workspace before final projection", async () => {
  const workflow = parse(
    await Deno.readTextFile(
      new URL(
        "../../../../workflows/workflow-operation-task-provision.yaml",
        import.meta.url,
      ),
    ),
  ) as {
    jobs: {
      name: string;
      target: string;
      dependsOn?: { job: string }[];
      steps: {
        name: string;
        task: {
          behaviorType: string;
          methodName: string;
          inputs: Record<string, unknown>;
        };
      }[];
    }[];
  };
  const workspace = workflow.jobs.find((job) => job.name === "workspace")!;
  assertEquals(
    workspace.target,
    '${{ "orbit-node-" + string(steps.admit.outputs.input.guest_node_id) }}',
  );
  assertEquals(workspace.dependsOn?.map((value) => value.job), [
    "vm-ready",
  ]);
  assertEquals(
    workflow.jobs.find((job) => job.name === "projection")?.dependsOn?.some((
      value,
    ) => value.job === "workspace"),
    true,
  );
  const children = workflow.jobs.flatMap((job) => job.steps).filter((step) =>
    JSON.stringify(step).includes("operations/start")
  );
  assertEquals(children, []);
});

Deno.test("VM completion is projected by the host before guest work and never completes the Tasks root", async () => {
  const f = fixture();
  await f.model.methods.vmReady.execute(f.project, f.context);
  assertEquals(f.operation.status, "running");
  assertEquals(f.operation.result.phase, "vm-ready");
  assertEquals(f.effects, []);
  const before = f.calls.length;
  await f.model.methods.workspace.execute(f.project, f.context);
  assertEquals(
    f.calls.slice(before).filter((call) => call.method === "POST"),
    [],
  );
});

Deno.test("a failing Project setup step produces a bounded workspace failure", async () => {
  const f = fixture(17);
  const command = "printf ready";
  const digest = Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(command)),
    ),
  ).map((v) => v.toString(16).padStart(2, "0")).join("");
  f.programs.push({ name: "prepare", command, timeout_seconds: 30 });
  f.input.direct_provisioning.setup!.steps.push({
    name: "prepare",
    command_digest: digest,
    timeout_seconds: 30,
  });
  await f.model.methods.workspace.execute(f.project, f.context);
  const workspace = ProvisionedWorkspace.parse(f.resources.get("workspace"));
  assertEquals(workspace.ok, false);
  assertEquals(f.effects.at(-1), "setup.prepare:printf ready");
  await assertRejects(() =>
    f.model.methods.project.execute({ ...f.project, workspace }, f.context)
  );
  assertEquals(f.operation.status, "failed");
});

Deno.test("the analysis pin uses the local merge base and is unavailable without origin/main", async () => {
  const directory = await Deno.makeTempDir({ prefix: "task-provision-base-" });
  async function git(...args: string[]) {
    const result = await new Deno.Command("git", {
      args: ["-c", "core.hooksPath=/dev/null", "-C", directory, ...args],
      stdout: "piped",
      stderr: "null",
    }).output();
    if (!result.success) throw new Error("Git fixture failed");
    return new TextDecoder().decode(result.stdout).trim();
  }
  try {
    await git("init", "-b", "main");
    await git(
      "-c",
      "user.name=Fixture",
      "-c",
      "user.email=fixture@example.test",
      "commit",
      "--allow-empty",
      "-m",
      "Base",
    );
    const base = await git("rev-parse", "HEAD");
    await git("update-ref", "refs/remotes/origin/main", base);
    await git(
      "-c",
      "user.name=Fixture",
      "-c",
      "user.email=fixture@example.test",
      "commit",
      "--allow-empty",
      "-m",
      "Task start",
    );
    const start = await git("rev-parse", "HEAD");
    const plan = {
      ...fixture().input.direct_provisioning,
      checkout: directory,
    };
    assertEquals(await provisionAnalysisBase(plan, start), base);
    await git("update-ref", "-d", "refs/remotes/origin/main");
    assertEquals(await provisionAnalysisBase(plan, start), null);
  } finally {
    await Deno.remove(directory, { recursive: true });
  }
});

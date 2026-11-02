import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { teardownArgv } from "./assets.ts";
import { ciCacheImportProgram } from "../instance-deploy/app_step_runtime.ts";
import {
  connection,
  Intent,
  observe,
  result,
  setupArgv,
  sourceResult,
} from "./provision.ts";

const intent = {
  task_vm_id: 10,
  guest_node_id: 20,
  group_id: 30,
  instance_id: 40,
  project_id: 50,
  wireguard_ip: "10.44.0.20",
  ssh_identity: {
    control_node_id: 209,
    identity_file: "/run/credentials/key",
    public_key: "ssh-ed25519 PUBLIC",
  },
  workspace: {
    checkout_path: "/home/orbit/apps/project/workspace",
    repository: "https://github.com/acme/test.git",
    repository_identity: "github:acme/test",
    branch: "task-30",
    default_branch: "main",
    start_commit: "c".repeat(40),
    user: "orbit",
    project_type: "monorepo",
    entry_point: "artisan",
    skip_profile: true,
    php_versions: ["8.5"],
    setup: [],
  },
};
const vm = {
  task_vm_id: 10,
  guest_node_id: 20,
  ready: true,
  provider: { address: "192.0.2.20" },
  enrollment: {
    ready: true,
    node: {
      ssh_host_key_type: "ssh-ed25519",
      ssh_host_key: "PUBLIC",
      observer_digest: "public-digest",
    },
    process: { process_id: 60, status: "active" },
  },
};
Deno.test("provision source uses the trusted child pin with captured WG/account and original identities", () => {
  const workspace = connection(intent, vm);
  assertEquals(workspace.task_vm_id, 10);
  assertEquals(workspace.checkout_path, intent.workspace.checkout_path);
  assertEquals(workspace.ssh_execution.target, {
    name: "target",
    host: "10.44.0.20",
    port: 22,
    user: "orbit",
    host_keys: ["ssh-ed25519 PUBLIC"],
  });
  assertThrows(() => connection(intent, { ...vm, guest_node_id: 99 }));
});
Deno.test("a failed source child retains VM readiness but never supplies successful source or analysis evidence", () => {
  const receipt = result(intent, {
    vm: { status: "succeeded", outputs: { result: vm } },
    guest: { status: "succeeded" },
    source: { status: "failed" },
  });
  assertEquals(receipt.ready, true);
  assertEquals(receipt.error_code, "tasks.provision_failed");
  assertEquals(receipt.failed_step, "source");
  assertEquals("source" in receipt, false);
  assertEquals("analysis_base" in receipt, false);
});
Deno.test("wrong child identity is never retained as confirmed VM readiness", () => {
  const receipt = result(intent, {
    vm: {
      status: "succeeded",
      outputs: { result: { ...vm, task_vm_id: 99 } },
    },
    guest: { status: "failed" },
  });
  assertEquals("ready" in receipt, false);
});
Deno.test("source result requires every ordered setup step before exposing the initial analysis pin", () => {
  const input = {
    ...intent,
    workspace: {
      ...intent.workspace,
      setup: [{
        name: "setup",
        command: "true",
        timeout_seconds: 30,
        input_json: '{"command":"true"}',
      }],
    },
  };
  const source = {
    branch: "task-30",
    commit: "a".repeat(40),
    php_version: null,
    laravel: false,
  };
  const steps = Object.fromEntries(
    [
      "prepare",
      "directory",
      "control",
      "clone_public",
      "bundle",
      "prepare_remote",
      "copy_bundle",
      "import",
      "origin",
      "branch",
      "identity",
      "analysis_base",
      "inspect",
      "composer",
      "profile",
    ].map((name) => [name, { status: "succeeded", outputs: {} }]),
  );
  steps.profile.outputs = { source, analysis_base: "b".repeat(40) };
  assertEquals(sourceResult(input, steps), {
    error_code: "tasks.provision_failed",
    failed_step: "setup-0",
  });
  // A guard skips the composer copy without a regular composer.json; a failed copy fails.
  steps.composer.status = "failed";
  assertEquals(sourceResult(input, steps), {
    error_code: "tasks.provision_failed",
    failed_step: "composer",
  });
  delete steps.composer;
  steps["setup-0"] = { status: "succeeded", outputs: {} };
  assertEquals(sourceResult(input, steps), {
    source,
    analysis_base: "b".repeat(40),
  });
  const receipt = result(input, {
    vm: { status: "succeeded", outputs: { result: vm } },
    guest: { status: "succeeded" },
    source: {
      status: "succeeded",
      outputs: { result: sourceResult(input, steps) },
    },
  });
  assertEquals(receipt.analysis_base, {
    base: "b".repeat(40),
    instance_id: 40,
    repository: "https://github.com/acme/test.git",
    branch: "task-30",
  });
  assertEquals("result_json" in receipt, false);
});

Deno.test("proof club descriptors never enter the canonical VM child input", async () => {
  const { behavior } = await import("./provision.ts");
  const resource: Record<string, unknown>[] = [];
  await behavior.methods.vm_inputs.execute({
    execution_secrets: {
      proof_club: {
        vault: "orbit-control-inputs",
        key: "swamp-proof-club-api-key",
      },
      git_authorization: {
        vault: "orbit-control-inputs",
        key: "task-provision-git-10",
      },
      pi: { vault: "orbit-control-inputs", key: "task-vm-pi-token-10" },
    },
  }, {
    extensionFile: () => "unused",
    writeResource: (_spec, _name, value) => {
      resource.push(value as Record<string, unknown>);
      return Promise.resolve({});
    },
  });
  assertEquals(resource, [{
    execution_secrets: {
      pi: { vault: "orbit-control-inputs", key: "task-vm-pi-token-10" },
    },
  }]);
});

Deno.test("proof provision requires private delivery before group readiness but preserves observed VM facts on refusal", () => {
  const input = { ...intent, project_id: 62, proof_club_required: true };
  const steps = {
    vm: { status: "succeeded", outputs: { result: vm } },
    guest: { status: "succeeded" },
    source: {
      status: "succeeded",
      outputs: {
        result: {
          source: {
            branch: "task-30",
            commit: "a".repeat(40),
            php_version: null,
            laravel: false,
          },
          analysis_base: null,
        },
      },
    },
    proof_club: { status: "failed" },
  };
  const refused = result(input, steps);
  assertEquals(refused.failed_step, "proof_club");
  assertEquals(refused.ready, true); // VM facts only; error prevents successful group/source projection.
  assertEquals("source" in refused, false);
  steps.proof_club.status = "succeeded";
  assertEquals(result(input, steps).error_code, undefined);
});

Deno.test("profile refuses a checkout that is not on the admitted start commit", async () => {
  const { behavior, Intent } = await import("./provision.ts");
  const admitted = Intent.parse(intent);
  const ctx = {
    extensionFile: () => "unused",
    writeResource: () => Promise.resolve({}),
  };
  const read = (commit: string) => ({
    identity: commit + "\ntask-30\n",
    analysis_base: commit + "\n",
    files: "",
    composer: "/nonexistent/composer.json",
  });
  await behavior.methods.profile.execute({
    intent: admitted,
    ...read("c".repeat(40)),
  }, ctx);
  await assertRejects(
    () =>
      behavior.methods.profile.execute({
        intent: admitted,
        ...read("d".repeat(40)),
      }, ctx),
    Error,
    "instance.source_identity_changed",
  );
});

Deno.test("profile reads and deletes the downloaded composer.json and keeps only its facts", async () => {
  const { behavior } = await import("./provision.ts");
  const directory = await Deno.makeTempDir();
  const path = directory + "/composer.json";
  const secret = ["ghp_", "C".repeat(36)].join("");
  await Deno.writeTextFile(
    path,
    JSON.stringify({
      require: { php: "^8.5", "laravel/framework": "^13" },
      extra: { token: secret },
    }),
  );
  const written: unknown[] = [];
  await behavior.methods.profile.execute({
    intent: Intent.parse({
      ...intent,
      workspace: {
        ...intent.workspace,
        project_type: "laravel-app",
        skip_profile: false,
      },
    }),
    identity: "c".repeat(40) + "\ntask-30\n",
    analysis_base: "",
    files: "composer.json|regular file|90\nartisan|regular file|20\n",
    composer: path,
  }, {
    extensionFile: () => "unused",
    writeResource: (_spec, _name, value) => {
      written.push(value);
      return Promise.resolve({});
    },
  });
  assertEquals(written, [{
    source: {
      branch: "task-30",
      commit: "c".repeat(40),
      php_version: "8.5",
      laravel: true,
    },
    analysis_base: null,
  }]);
  await assertRejects(() => Deno.stat(path), Deno.errors.NotFound);
  await Deno.remove(directory);
});

Deno.test("each setup and teardown command runs as one SSH exec argv in the checkout with empty seeds, its output in a file", () => {
  const setup = (command: string) => ({
    ...intent,
    workspace: {
      ...intent.workspace,
      setup: [{
        name: "setup",
        command,
        timeout_seconds: 30,
        input_json: JSON.stringify({ command }),
      }],
    },
  });
  const argv = (directory: string, name: string, command: string) => [
    "/bin/bash",
    "-c",
    'mkdir -p -m 0700 -- "$1" && exec >"$1/$2" 2>&1 && shift 2 && exec "$@"',
    "orbit-output",
    directory,
    name,
    "env",
    "-C",
    "/home/orbit/apps/project/workspace",
    "COMPOSER_NO_INTERACTION=1",
    "ORBIT_CHECK_PROCESSES=1",
    "COMPOSER_CACHE_DIR=/home/orbit/.cache/composer",
    "ORBIT_SEED_PATH=",
    "ORBIT_SEED_COMMIT=",
    "/bin/bash",
    "-lc",
    command,
  ];
  assertEquals(setupArgv(setup("composer install"), "/tmp/run"), [
    argv("/tmp/run/output", "setup-0", "composer install"),
  ]);
  // The Gateway-owned step expands to the shared CI cache program, never a literal command.
  assertEquals(setupArgv(setup("@orbit/ci-caches"), "/tmp/run"), [
    argv("/tmp/run/output", "setup-0", ciCacheImportProgram),
  ]);
  assertEquals(
    teardownArgv({
      checkout_path: "/home/orbit/apps/project/workspace",
      ssh_execution: { target: { user: "orbit", host: "10.44.0.20" } },
      teardown: [{
        input_json: JSON.stringify({
          name: "drop",
          command: "rm -f db.sqlite",
          timeout_seconds: 30,
        }),
        timeout_seconds: 30,
      }],
    }, "/tmp/teardown"),
    { teardown: [argv("/tmp/teardown", "teardown-0", "rm -f db.sqlite")] },
  );
});

Deno.test("the checkout reads become the identity, analysis pin and source profile", () => {
  const workspace = Intent.parse({
    ...intent,
    workspace: {
      ...intent.workspace,
      project_type: "laravel-app",
      skip_profile: false,
    },
  }).workspace;
  const head = "c".repeat(40);
  const composer = '{"require":{"php":"^8.4","laravel/framework":"^13"}}';
  const read = (
    files: string,
    base = "b".repeat(40) + "\n",
    bytes: Uint8Array | null = new TextEncoder().encode(composer),
  ) => ({
    identity: head + "\ntask-30\n",
    base,
    files,
    composer: bytes,
  });
  const laravel = observe(
    workspace,
    read("composer.json|regular file|52\nartisan|regular file|300\n"),
  );
  assertEquals(laravel.branch, "task-30");
  assertEquals(laravel.commit, head);
  assertEquals(laravel.analysis_base, "b".repeat(40));
  assertEquals(laravel.profile, {
    kind: "composer",
    composer: btoa(composer),
    entry_point: "regular",
  });
  // inspect saw a regular composer.json, so a missing download is a failure.
  assertThrows(
    () =>
      observe(
        workspace,
        read("composer.json|regular file|52\n", undefined, null),
      ),
    Error,
    "tasks.provision_failed",
  );
  // Git found no merge base: the pin is unavailable, never the current HEAD.
  assertEquals(observe(workspace, read("", "")).analysis_base, null);
  assertEquals(observe(workspace, read("")).profile.kind, "none");
  assertEquals(
    observe(workspace, read("artisan|regular file|300\n")).profile.kind,
    "partial",
  );
  assertEquals(
    observe(workspace, read("composer.json|symbolic link|13\n")).profile
      .kind,
    "unsafe",
  );
  assertEquals(
    observe(workspace, read("composer.json|regular file|1048577\n")).profile
      .kind,
    "unsafe",
  );
  assertEquals(
    observe(
      workspace,
      read("composer.json|regular file|52\nartisan|symbolic link|7\n"),
    ).profile.entry_point,
    "unsafe",
  );
  assertEquals(
    observe(workspace, read("composer.json|regular file|52\n")).profile
      .entry_point,
    "absent",
  );
  assertEquals(
    observe(
      { ...workspace, skip_profile: true },
      read("composer.json|regular file|52\n"),
    ).profile.kind,
    "none",
  );
});

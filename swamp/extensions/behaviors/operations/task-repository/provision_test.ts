import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { connection, result, sourceResult } from "./provision.ts";

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
      "copy_assets",
      "copy_bundle",
      "import",
      "origin",
      "branch",
      "observe",
      "profile",
    ].map((name) => [name, { status: "succeeded", outputs: {} }]),
  );
  steps.profile.outputs = { source, analysis_base: "b".repeat(40) };
  assertEquals(sourceResult(input, steps), {
    error_code: "tasks.provision_failed",
    failed_step: "setup-0",
  });
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

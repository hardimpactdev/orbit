import { assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../tests/_support/native_harness.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
import { assertSwampLabels } from "../../../../tests/_support/task_vm_provision.ts";

Deno.test("tasks.provision's default queue budget covers a loaded dispatch", () => {
  const workflow = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-operation-task-provision.yaml`)) as {
    queueTimeout: number;
  };
  assertEquals(workflow.queueTimeout, 30);
  for (
    const name of [
      "task-vm-provision",
      "operation-task-vm-provision",
      "task-vm-ssh-prepare",
      "task-vm-incus-first-visit",
      "task-vm-enrollment",
    ]
  ) assertSwampLabels(name);
});

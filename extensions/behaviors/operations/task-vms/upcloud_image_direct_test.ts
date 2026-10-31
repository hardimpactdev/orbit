import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { invocationFor } from "./contract.ts";
import { taskVms } from "./mod.ts";

Deno.test("direct UpCloud image kinds cannot enter the polled executor", () => {
  for (
    const kind of [
      "task_vm.upcloud_image_build",
      "task_vm.upcloud_image_prepare",
      "task_vm.upcloud_guest_image_owner",
    ]
  ) {
    assertEquals(
      taskVms().methods.execute.arguments.safeParse({ kind, worker: "fixture" })
        .success,
      false,
    );
  }
  assertThrows(() => invocationFor("task_vm.upcloud_image_prepare", {}));
  assertThrows(() => invocationFor("task_vm.upcloud_guest_image_owner", {}));
});

import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { Operation } from "./mod.ts";
import { SecretReference, secretReference } from "./secret.ts";

Deno.test("protected inputs carry only a named source and positive owner ID", () => {
  assertEquals(secretReference("project.git_read", 27), {
    $secret: "project.git_read",
    id: 27,
  });
  assertThrows(() => secretReference("project.git_read", 0));
  assertThrows(() =>
    SecretReference.parse({
      $secret: "project.git_read",
      id: 27,
      token: "plaintext",
    })
  );
});

Deno.test("Gateway Operations retain optional public completion projections", () => {
  const result = { deployment_id: 42, selected_release: "previous" };
  const operation = Operation.parse({
    id: 1,
    kind: "instance.deploy",
    node_id: null,
    input: {},
    status: "running",
    cancel_requested: true,
    step_results: [],
    execution_context: { descriptors: [], result },
  });
  assertEquals(operation.execution_context.result, result);
  assertEquals(operation.node_id, null);
});

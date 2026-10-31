import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { Creation } from "./create.ts";
import admitted from "./create-admitted-input.json" with { type: "json" };

// Captured by Orbit's real HTTP ProcessOperationAdmissionTest. Only record IDs,
// artifact names and hashes are normalized there; the public input shape is exact.
Deno.test("the real first-visit Gateway admission passes the native creation schema", () => {
  const input = Creation.parse(admitted);
  assert(input.installation.runtime === "systemd");
  assertEquals(input.installation.activation_pending, false);
  assertEquals(input.installation.process_id, input.control.process_id);
});

Deno.test("native creation still refuses the observed null activation flag", () => {
  assertThrows(() =>
    Creation.parse({
      ...admitted,
      installation: { ...admitted.installation, activation_pending: null },
    })
  );
});

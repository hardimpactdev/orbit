import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { localAccount } from "./converge_local.ts";
import type { SystemTransport } from "./system_command.ts";

Deno.test("account resolution reads passwd and group through the same placed transport", async () => {
  const seen: string[][] = [];
  const invoke: SystemTransport = (input) => {
    seen.push([input.command, ...input.args]);
    return Promise.resolve({
      success: true,
      stdout: input.args[0] === "passwd"
        ? "orbit:x:1000:1001:Node user:/srv/orbit:/bin/bash\n"
        : "managed:x:1001:\n",
    });
  };
  assertEquals(await localAccount("orbit", invoke), {
    home: "/srv/orbit",
    group: "managed",
  });
  assertEquals(seen, [["/usr/bin/getent", "passwd", "orbit"], [
    "/usr/bin/getent",
    "group",
    "1001",
  ]]);
});

Deno.test("account transport refusals cannot report success", async () => {
  const refused: SystemTransport = () =>
    Promise.resolve({ success: false, stdout: "" });
  await assertRejects(
    () => localAccount("orbit", refused),
    Error,
    "The admitted Node account cannot be resolved",
  );
});

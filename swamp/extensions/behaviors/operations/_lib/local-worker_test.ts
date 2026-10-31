import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.14";
import { localFilesystem } from "./local-worker.ts";
import { selectVitePort } from "../processes-schedules/vite-system.ts";
import { collectLogs } from "../../observation/doctor_logs.ts";

Deno.test("a route or process job in a proxy cgroup refuses before any local effect", async () => {
  const original = Deno.Command;
  let effects = 0;
  class ProxyCommand extends original {
    constructor(command: string | URL, options?: Deno.CommandOptions) {
      if (
        command !== "/usr/bin/timeout" ||
        !options?.args?.includes("/proc/self/cgroup")
      ) {
        effects++;
        throw new Error("Unexpected local effect");
      }
      super("/usr/bin/printf", {
        args: ["0::/system.slice/orbit-node-proxy-worker-1.service\n"],
        stdout: "piped",
        stderr: "null",
      });
    }
  }
  Object.defineProperty(Deno, "Command", {
    value: ProxyCommand,
    configurable: true,
    writable: true,
  });
  try {
    assertThrows(
      () => localFilesystem().makeTempDir(),
      Error,
      "proxy Node effect locally",
    );
    await assertRejects(
      () => selectVitePort({ preferred: 5173, excluded: [] }),
      Error,
      "proxy Node effect locally",
    );
    await assertRejects(
      () =>
        collectLogs({
          log_directory: "/nonexistent-orbit-proxy-proof",
          base_directory: "/nonexistent-orbit-proxy-proof",
          cursor: null,
        }),
      Error,
      "proxy Node effect locally",
    );
    assertEquals(effects, 0);
  } finally {
    Object.defineProperty(Deno, "Command", {
      value: original,
      configurable: true,
      writable: true,
    });
  }
});

import { z } from "npm:zod@4.4.3";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";

const Location = z.object({
  build_id: z.number().int().positive(),
  attempt: z.number().int().positive(),
  project: z.string().regex(/^[a-z0-9][a-z0-9-]{0,62}$/),
  pool: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/),
  name: z.string(),
}).refine((input) =>
  input.name === `tvm-image-${input.build_id}` ||
  input.name === `tvm-image-${input.build_id}-attempt-${input.attempt}`
);
const failed = () => new Error("task_vm.image_cleanup_failed");

/** Remove only the admitted attempt's two VMs; images and shared seeds stay owned by pruning. */
export async function cleanupImageVms(
  raw: z.input<typeof Location>,
  guard: () => Promise<void>,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<void> {
  try {
    const input = Location.parse(raw);
    const run = async (args: string[]) => {
      signal?.throwIfAborted();
      await guard();
      return await invoke({
        command: "/usr/bin/sudo",
        args: [
          "-n",
          "--",
          "/usr/bin/incus",
          "--project",
          input.project,
          ...args,
        ],
        timeoutMs: 150000,
        maxOutputBytes: 4 * 1024 * 1024,
      }, signal);
    };
    let incomplete = false;
    for (const name of [input.name, `${input.name}-smoke`]) {
      try {
        const observe = async () => {
          const result = await run(["list", "--format", "json", "--", name]);
          if (!result.success) throw failed();
          const rows = z.array(z.object({ name: z.string() }).passthrough())
            .max(4096).parse(JSON.parse(result.stdout))
            .filter((row) => row.name === name);
          if (rows.length === 0) return null;
          if (rows.length !== 1) throw failed();
          const row = z.object({
            type: z.literal("virtual-machine"),
            status: z.enum(["Running", "Stopped"]),
            expanded_devices: z.record(
              z.string(),
              z.object({
                type: z.string(),
                path: z.string().optional(),
                pool: z.string().optional(),
              }).passthrough(),
            ),
          }).parse(rows[0]);
          const roots = Object.values(row.expanded_devices)
            .filter((device) => device.type === "disk" && device.path === "/");
          if (roots.length !== 1 || roots[0].pool !== input.pool) {
            throw failed();
          }
          return row;
        };
        let observed = await observe();
        if (observed?.status === "Running") {
          // These are disposable image guests, never enrolled Task VMs.
          try {
            if (!(await run(["stop", "--force", "--", name])).success) {
              throw failed();
            }
          } catch {
            // A lost response is not permission to repeat a mutation.
            if ((await observe())?.status === "Running") throw failed();
          }
          observed = await observe();
          if (observed?.status === "Running") throw failed();
        }
        if (observed) {
          try {
            if (!(await run(["delete", "--", name])).success) throw failed();
          } catch {
            if (await observe()) throw failed();
          }
          if (await observe()) throw failed();
        }
        const volumes = await run([
          "storage",
          "volume",
          "list",
          input.pool,
          "--format",
          "json",
        ]);
        if (!volumes.success) throw failed();
        const remaining = z.array(
          z.object({ name: z.string(), type: z.string() }),
        )
          .max(65536).parse(JSON.parse(volumes.stdout));
        if (
          remaining.some((volume) =>
            volume.type === "virtual-machine" &&
            (volume.name === name || volume.name.startsWith(`${name}/`))
          )
        ) throw failed();
      } catch {
        incomplete = true;
      }
    }
    if (incomplete) throw failed();
  } catch {
    throw failed();
  }
}

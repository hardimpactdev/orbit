import { z } from "npm:zod@4.4.3";
import { processAction } from "../processes-schedules/process-control.ts";
import { Input } from "../processes-schedules/process-input.ts";
import { ProcessInstallation } from "../processes-schedules/installation-input.ts";
import { startActions, StartInput } from "../processes-schedules/start.ts";
import { selectVitePort } from "../processes-schedules/vite-system.ts";

const Member = z.object({
  id: z.number().int().positive(),
  input: z.object({ control: StartInput, installation: ProcessInstallation })
    .strict(),
}).strict().refine((v) =>
  v.id === v.input.control.process_id &&
  v.id === v.input.installation.process_id
);
export const TransferRuntime = z.object({
  node_id: z.number().int().positive(),
  field: z.enum(["source_installations", "destination_installations"])
    .nullable(),
  members: z.array(Member).max(4096),
  ports: z.array(z.number().int().min(1024).max(65535)).max(2),
}).strict().refine((v) =>
  new Set(v.members.map((m) => m.id)).size === v.members.length
);
export type TransferRuntime = z.infer<typeof TransferRuntime>;
const controlInput = (control: z.infer<typeof StartInput>) =>
  Input.parse({
    process_id: control.process_id,
    agent_sha256: control.agent_sha256,
    runtime: control.runtime,
    name: control.name,
    on_demand: control.on_demand,
    vite: control.vite,
    ...(control.directory === undefined
      ? {}
      : { directory: control.directory }),
  });
const requireReady = (value: Record<string, unknown>) => {
  if (value.error_code !== undefined) {
    throw new Error(
      z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(value.error_code),
    );
  }
};

/** Canonical Process actions stay inside this owning native transfer root. */
export function transferRuntimeActions(
  control = processAction(),
  traffic = startActions(),
  select = selectVitePort,
) {
  const prepare = async (runtime: TransferRuntime, signal?: AbortSignal) => {
    for (const member of runtime.members) {
      if (member.input.installation.desired === "running") {
        requireReady(
          await traffic.prepare(member.input.control, signal),
        );
      }
    }
  };
  const verify = async (runtime: TransferRuntime, signal?: AbortSignal) => {
    for (const member of runtime.members) {
      if (
        member.input.installation.desired === "running" &&
        member.input.control.vite_plan !== undefined
      ) {
        requireReady(await traffic.verify(member.input.control, signal, true));
      }
    }
  };
  return {
    ready: async (runtime: TransferRuntime) => {
      for (const port of runtime.ports) {
        const result = await select({ preferred: port, excluded: [] });
        if (result.port !== port) {
          throw new Error("instance.transfer_port_busy");
        }
      }
    },
    pause: async (runtime: TransferRuntime, signal?: AbortSignal) => {
      for (const member of runtime.members) {
        requireReady(
          await control(
            "process.stop",
            controlInput(member.input.control),
            signal,
          ),
        );
      }
    },
    prepare,
    verify,
    retire: async (runtime: TransferRuntime, signal?: AbortSignal) => {
      for (const member of runtime.members) {
        requireReady(
          await control(
            "process.destroy",
            controlInput(member.input.control),
            signal,
          ),
        );
      }
    },
  };
}

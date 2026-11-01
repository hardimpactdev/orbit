import { z } from "npm:zod@4.4.3";
import { ProcessInstallation } from "./installation-input.ts";
import { StartInput } from "./start.ts";

/** Retained parent installation descriptors; direct updates use public native inputs. */
export const Creation = z.object({
  control: StartInput,
  installation: ProcessInstallation,
  caddy_publication: z.unknown().optional(),
}).strict().superRefine((input, ctx) => {
  const a = input.control;
  const b = input.installation;
  if (
    a.process_id !== b.process_id || a.runtime !== b.runtime ||
    a.name !== b.name || a.agent_sha256 !== b.agent_sha256 ||
    a.on_demand !== b.on_demand || a.directory !== b.directory
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Process installation identity differs",
    });
  }
});

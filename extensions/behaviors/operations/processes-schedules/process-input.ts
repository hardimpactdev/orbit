import { z } from "npm:zod@4.4.3";

const Removal = z.object({
  path: z.string().startsWith("/etc/orbit/vite/app-instance-").max(4096),
  instance_id: z.number().int().positive(),
}).strict().refine(
  (value) =>
    value.path === `/etc/orbit/vite/app-instance-${value.instance_id}.env`,
  "The environment path must belong to the admitted Instance",
);
export const Input = z.object({
  process_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  runtime: z.enum(["systemd", "docker"]),
  name: z.string().regex(/^orbit-process-[0-9]+-[a-z0-9-]+(?:\.service)?$/),
  on_demand: z.boolean(),
  directory: z.string().startsWith("/").max(4096).optional(),
  vite: Removal.nullable(),
}).strict();

import { z } from "npm:zod@4.4.3";

const Path = z.string().startsWith("/").refine((value) =>
  !["\0", "\r", "\n"].some((character) => value.includes(character)) &&
  !/(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(value)
);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Clone = z.object({
  variant: z.literal("clone"),
  directory: z.string().regex(/^\/tmp\/orbit-sqlite-clone-[0-9a-f]{32}$/),
}).strict();
export const Seed = z.object({
  variant: z.literal("seed"),
  target_instance_id: z.number().int().positive(),
  base: Path,
  source_path: Path,
  source_user: User,
  transport_user: User,
  operation: z.string().regex(/^[0-9a-f]{64}$/),
  state_dir: Path,
  snapshot_path: Path,
}).strict().refine((value) =>
  value.source_path.startsWith(value.base + "/") &&
  value.snapshot_path === `/tmp/orbit-sqlite-${value.operation}.sqlite` &&
  new RegExp(
    `^/var/lib/orbit/app-instance-sqlite-seeds/source-[1-9][0-9]*-target-${value.target_instance_id}(?:-${value.operation})?$`,
  ).test(value.state_dir)
);
export const CleanupInput = z.union([Clone, Seed]);

import { z } from "npm:zod@4.4.3";

const Path = z.string().startsWith("/").refine((value) =>
  !["\0", "\r", "\n"].some((character) => value.includes(character)) &&
  !/(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(value)
);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Pin = z.string().regex(/^[0-9a-f]{64}$/);
const Clone = z.object({
  variant: z.literal("clone"),
  agent_sha256: Pin,
  directory: z.string().regex(/^\/tmp\/orbit-sqlite-clone-[0-9a-f]{32}$/),
}).strict();
export const Seed = z.object({
  variant: z.literal("seed"),
  agent_sha256: Pin,
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
export type CleanupIntent = z.infer<typeof CleanupInput>;

/** The admitted pin is separate from the typed cleanup command input. */
export function cleanupPayload(raw: unknown) {
  const { agent_sha256, ...input } = CleanupInput.parse(raw);
  return { agent_sha256, input };
}

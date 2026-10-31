import { z } from "npm:zod@4.4.3";
import { DestroyDatabase } from "./destroy_database.ts";

export const DestroyCode = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const Path = z.string().max(4096).refine((value) => {
  const parts = value.split("/");
  return parts[0] === "" && parts.length > 2 &&
    parts.slice(1).every((part) =>
      part !== "" && part !== "." && part !== ".." && !/\p{Cc}/u.test(part)
    );
});
export { Path as DestroyPath };
const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
export const RemovalSource = z.object({
  root: Path,
  operation: z.string().uuid(),
  member: z.string().regex(/^[1-9][0-9]*$/),
  digest: Digest,
  user: Account,
  group: Account,
  checkout: Path,
  common_repository: Path,
  layout: z.enum(["checkout", "worktree"]),
  source_identity: z.string().regex(/^(?:[0-9]+:[0-9]+|absent)$/),
}).strict().superRefine((source, ctx) => {
  if (!source.checkout.startsWith(source.root + "/")) {
    ctx.addIssue({ code: "custom", message: "Source is outside its root" });
  }
});
export const DestroyMember = z.object({
  instance_id: z.number().int().positive(),
  source: RemovalSource,
  branch: z.string().max(1024),
  receipt: Digest,
  force: z.boolean(),
  origin: z.string().min(1).max(4096),
  worktrees: z.string().max(262144),
  commit: z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64}|)$/),
  grouping_directory: Path,
  unresolved: z.boolean(),
  independent_worktree: z.boolean(),
  own_units: z.array(
    z.string().regex(/^orbit-(?:process|schedule)-[a-z0-9-]+\.service$/),
  )
    .max(2048),
}).strict().superRefine((member, ctx) => {
  if (
    member.grouping_directory !==
      member.source.checkout.slice(0, member.source.checkout.lastIndexOf("/"))
  ) {
    ctx.addIssue({ code: "custom", message: "Invalid grouping directory" });
  }
});
export const DestroyRuntime = z.object({
  processes: z.array(
    z.object({
      process_id: z.number().int().positive(),
      runtime: z.enum(["systemd", "docker"]),
      name: z.string().regex(/^orbit-process-[0-9]+-[a-z0-9-]+(?:\.service)?$/),
      vite: z.union([
        z.null(),
        z.object({
          path: Path,
          instance_id: z.number().int().positive(),
        }).strict().refine(
          (value) =>
            value.path ===
              `/etc/orbit/vite/app-instance-${value.instance_id}.env`,
          "The Vite path must belong to the admitted Instance",
        ),
      ]),
    }).strict(),
  ).max(2048),
  schedules: z.array(
    z.object({
      id: z.string().uuid(),
      group: Account,
      cascade: z.literal(true),
    }).strict(),
  ).max(2048),
}).strict();
export const DestroyLocal = z.object({
  node_id: z.number().int().positive(),
  members: z.array(DestroyMember).min(1).max(1024),
  runtime: DestroyRuntime,
  database: DestroyDatabase,
}).strict().superRefine((input, ctx) => {
  const ids = input.members.map((member) => member.instance_id);
  if (new Set(ids).size !== ids.length) {
    ctx.addIssue({ code: "custom", message: "Duplicate removal member" });
  }
  const paths = input.members.map((member) => member.source.checkout);
  if (new Set(paths).size !== paths.length) {
    ctx.addIssue({ code: "custom", message: "Duplicate removal path" });
  }
  if (
    !ids.includes(input.database.owner_instance_id) ||
    input.database.sqlite.some((row) =>
      row.node_id !== input.node_id ||
      !input.members.some((member) => member.source.checkout === row.checkout)
    )
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Database source differs from removal",
    });
  }
  if (
    new Set(input.runtime.processes.map((process) => process.process_id))
        .size !== input.runtime.processes.length ||
    new Set(input.runtime.schedules.map((schedule) => schedule.id)).size !==
      input.runtime.schedules.length ||
    input.runtime.processes.some((process) =>
      !process.name.startsWith(`orbit-process-${process.process_id}-`) ||
      process.vite !== null && !ids.includes(process.vite.instance_id)
    )
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Runtime identities must belong to the admitted removal",
    });
  }
});
export type DestroyLocalInput = z.infer<typeof DestroyLocal>;

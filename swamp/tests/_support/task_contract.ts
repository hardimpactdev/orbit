import { z } from "npm:zod@4";

// The generated payload subset of Orbit CreateTaskGroupRequest::rules / TaskGroupGuard (ADR 0133).
// Keep this independent of the maintenance generator: backlog accepts no deliverables;
// todo requires a nonempty typed list on every child.
const text = (max: number) => z.string().min(1).max(max);
const relative = text(500).refine((s) =>
  !s.startsWith("/") && !s.split("/").includes("..")
);
const common = {
  id: text(64).regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/),
  description: text(500),
};
const deliverable = z.discriminatedUnion("type", [
  z.strictObject({ ...common, type: z.literal("review") }),
  z.strictObject({
    ...common,
    type: z.literal("command"),
    command: text(1000),
    directory: relative.optional(),
  }),
  z.strictObject({
    ...common,
    type: z.literal("file"),
    path: relative,
    change: z.enum(["created", "modified", "any"]),
  }),
]);
export const taskInputs = z.array(z.object({
  title: text(160),
  brief: text(8000),
  deliverables: z.array(deliverable).max(5).refine((items) =>
    new Set(items.map((d) => d.id)).size === items.length
  ).optional(),
})).max(50);

export function todoError(tasks: unknown): string | null {
  const inputs = taskInputs.parse(tasks);
  if (!inputs.length) return "tasks.no_subtasks";
  return inputs.some((t) => !t.deliverables?.length)
    ? "tasks.subtask_deliverables_missing"
    : null;
}

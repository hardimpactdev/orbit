import { z } from "npm:zod@4.4.3";

export const Probe = z.strictObject({
  node_id: z.number().int().positive(),
  family: z.enum([
    "node",
    "role",
    "project",
    "instance",
    "schedule",
    "tool",
    "process",
    "firewall",
    "database_connection",
    "route",
    "database_server",
  ]),
  kind: z.enum([
    "Rows",
    "Inventory",
    "Environments",
    "Attachments",
    "Connections",
  ]),
  ids: z.array(z.union([z.number().int().positive(), z.string().min(1)])),
});
export const Plan = z.strictObject({
  collection: z.string().uuid(),
  enabled: z.boolean(),
  nodes: z.array(z.number().int().positive()),
  probes: z.array(Probe),
}).superRefine((plan, ctx) => {
  if (new Set(plan.nodes).size !== plan.nodes.length) {
    ctx.addIssue({ code: "custom", message: "Duplicate collection Node" });
  }
  for (const probe of plan.probes) {
    if (!plan.nodes.includes(probe.node_id)) {
      ctx.addIssue({
        code: "custom",
        message: "Probe outside collection Nodes",
      });
    }
  }
});
export const Progress = z.strictObject({
  plan: Plan,
  observed: z.array(z.number().int().positive()),
  inspected: z.array(z.number().int().nonnegative()),
  complete: z.boolean(),
});
export type Plan = z.infer<typeof Plan>;
export type Progress = z.infer<typeof Progress>;

/** Gateway owns read snapshots, selection, fingerprints and signal cursors. */
export interface CollectionReads {
  plan(collection: string): Promise<Plan>;
  observe(collection: string, node: number): Promise<void>;
  inspect(collection: string, probe: number): Promise<void>;
  signals(collection: string): Promise<void>;
}
export class CollectionDisabled extends Error {}

export interface CollectionData {
  read(): Promise<Progress | null>;
  write(progress: Progress): Promise<void>;
}

/** Resume an unfinished collection before admitting the next scheduled pass. */
export async function collect(
  collection: string,
  reads: CollectionReads,
  data: CollectionData,
): Promise<Progress> {
  let progress = await data.read();
  if (
    !progress || progress.complete && progress.plan.collection !== collection
  ) {
    const plan = Plan.parse(await reads.plan(collection));
    if (plan.collection !== collection) {
      throw new Error("Wrong collection receipt");
    }
    progress = { plan, observed: [], inspected: [], complete: !plan.enabled };
    await data.write(progress);
  }
  if (progress.complete) return progress;
  try {
    for (const node of progress.plan.nodes) {
      if (progress.observed.includes(node)) continue;
      await reads.observe(progress.plan.collection, node);
      progress = { ...progress, observed: [...progress.observed, node] };
      await data.write(progress);
    }
    for (const index of progress.plan.probes.keys()) {
      if (progress.inspected.includes(index)) continue;
      await reads.inspect(progress.plan.collection, index);
      progress = { ...progress, inspected: [...progress.inspected, index] };
      await data.write(progress);
    }
    await reads.signals(progress.plan.collection);
  } catch (error) {
    if (!(error instanceof CollectionDisabled)) throw error;
  }
  progress = { ...progress, complete: true };
  await data.write(progress);
  return progress;
}

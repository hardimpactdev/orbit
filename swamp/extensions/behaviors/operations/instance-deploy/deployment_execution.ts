import { z } from "npm:zod@4.4.3";

export const DeploymentExecution = z.object({
  operation_id: z.number().int().positive(),
  run_id: z.string().uuid(),
  active: z.boolean(),
}).strict();
type Owner = { operation_id: number; run_id: string };
type Context = {
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<unknown>;
};
export async function readDeploymentExecution(ctx: Context) {
  const value = await ctx.readResource("activeExecution");
  return value === null ? null : DeploymentExecution.parse(value);
}
export function sameDeploymentExecution(owner: Owner, other: Owner) {
  return owner.operation_id === other.operation_id &&
    owner.run_id === other.run_id;
}
/** Existing native model writer lock covers this pointer and all root effects. */
export async function assertDeploymentExecutionAvailable(
  ctx: Context,
  owner: Owner,
) {
  const previous = await readDeploymentExecution(ctx);
  if (previous?.active && !sameDeploymentExecution(owner, previous)) {
    throw new Error("deployment.target_busy");
  }
  return previous;
}
/** Persist the per-run journal first, then this pointer, before launching effects. */
export async function saveDeploymentExecution(
  ctx: Context,
  owner: Owner,
  active: boolean,
) {
  await assertDeploymentExecutionAvailable(ctx, owner);
  await ctx.writeResource("activeExecution", "activeExecution", {
    operation_id: owner.operation_id,
    run_id: owner.run_id,
    active,
  });
}

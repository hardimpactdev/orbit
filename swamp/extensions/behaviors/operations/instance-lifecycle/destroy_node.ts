import { destroySystem } from "./destroy_system.ts";

export type DestroyCommand =
  | "instance.prepare-source-removal"
  | "instance.read-source-removal-state"
  | "instance.finalize-source-removal"
  | "instance.clean-removal-receipt";
export type DestroyInvoke = (
  command: DestroyCommand,
  input: Record<string, unknown>,
  user?: string,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;

/** Source effects run locally as the admitted Node account, without an agent action. */
export const destroyNode: DestroyInvoke = async (
  command,
  input,
  user,
  signal,
) => {
  signal?.throwIfAborted();
  if (user === undefined || input.user !== user) {
    return { error_code: "instance.source_ownership_mismatch" };
  }
  return await destroySystem(command, input, signal);
};

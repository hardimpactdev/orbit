import { localSystemCommand } from "../node/system_command.ts";

/** Family argument adapter for the shared native Node transport. */
export type CommandResult = { code: number; stdout: string };
export type SystemCommand = (
  program: string,
  args: readonly string[],
  stdin?: string,
  signal?: AbortSignal,
  timeoutMs?: number,
) => Promise<CommandResult>;

export const systemCommand: SystemCommand = async (
  program,
  args,
  stdin,
  signal,
  timeoutMs = 300_000,
) => {
  const result = await localSystemCommand({
    command: program,
    args,
    stdin,
    timeoutMs,
  }, signal);
  return { code: result.success ? 0 : 1, stdout: result.stdout };
};

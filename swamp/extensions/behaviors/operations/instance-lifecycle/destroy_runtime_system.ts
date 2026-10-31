import { processSystem } from "../processes-schedules/process-system.ts";
import { scheduleSystem } from "../processes-schedules/schedule-system.ts";

export type RuntimeCommand = "schedule.remove" | "process.remove";
export type RuntimeInvoke = (
  name: RuntimeCommand,
  input: Record<string, unknown>,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;

const processes = processSystem();
/** The parent orders Schedules before Processes; each owner validates and removes its artifacts. */
export const destroyRuntimeSystem: RuntimeInvoke = (name, input, signal) =>
  name === "schedule.remove"
    ? scheduleSystem(name, input, signal)
    : processes(name, input, signal);

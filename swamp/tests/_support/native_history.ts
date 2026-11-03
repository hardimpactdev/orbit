import type { History } from "./native_harness.ts";
export const exists = (path: string) => {
  try {
    Deno.statSync(path);
    return true;
  } catch {
    return false;
  }
};
// Native history is JSON owned by the runtime, with heterogeneous step outputs.
// deno-lint-ignore no-explicit-any
export const stepsOf = (history: History): Record<string, any> =>
  Object.fromEntries(
    history.jobs.flatMap((job: { steps: { name: string }[] }) => job.steps).map((s: { name: string }) => [s.name, s]),
  );

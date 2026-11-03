import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "./native_harness.ts";
export const placed = [
  "project-maintenance-inspect",
  "project-routine",
  "project-observe",
  "maintenance-projects",
  "horizon-new-dawn-detect",
  "dependency-upgrade",
];
// YAML workflows contain arbitrary task-specific fields.
// deno-lint-ignore no-explicit-any
export const workflow = (name: string): Record<string, any> =>
  parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-${name}.yaml`)) as Record<string, unknown>;

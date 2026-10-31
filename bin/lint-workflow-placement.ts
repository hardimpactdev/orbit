import { parse } from "jsr:@std/yaml@1.0.12";

type ObjectValue = Record<string, unknown>;
const object = (value: unknown): ObjectValue =>
  value !== null && typeof value === "object" && !Array.isArray(value)
    ? value as ObjectValue
    : {};

// Swamp merges each field independently: an explicit empty labels map clears
// inherited labels, while an omitted target keeps its parent's target.
function placement(parent: ObjectValue, child: ObjectValue): ObjectValue {
  const result = { ...parent };
  for (const key of ["target", "labels", "platform"]) {
    if (key in child) result[key] = child[key];
  }
  return result;
}

export function placementErrors(value: unknown, source: string): string[] {
  const workflow = object(value);
  const errors: string[] = [];
  if (!Array.isArray(workflow.jobs)) {
    return [`${source}: jobs must be an array`];
  }
  for (const jobValue of workflow.jobs) {
    const job = object(jobValue);
    if (!Array.isArray(job.steps)) {
      errors.push(`${source}: job ${String(job.name)} has no steps array`);
      continue;
    }
    for (const stepValue of job.steps) {
      const step = object(stepValue);
      const fields = placement(placement(workflow, job), step);
      const named = [fields.target, fields.platform].some((v) =>
        typeof v === "string" && v.trim().length > 0
      );
      const labels = object(fields.labels);
      const labelled = Object.keys(labels).length > 0 &&
        Object.values(labels).every((v) =>
          typeof v === "string" && v.length > 0
        );
      if (!named && !labelled) {
        errors.push(
          `${source}: ${String(job.name)}/${
            String(step.name)
          } has no worker placement`,
        );
      }
    }
  }
  return errors;
}

export async function lintWorkflows(root: string): Promise<string[]> {
  const errors: string[] = [];
  for await (const entry of Deno.readDir(root)) {
    const path = `${root}/${entry.name}`;
    if (entry.isDirectory) errors.push(...await lintWorkflows(path));
    else if (entry.isFile && /\.ya?ml$/.test(entry.name)) {
      try {
        errors.push(
          ...placementErrors(parse(await Deno.readTextFile(path)), path),
        );
      } catch (error) {
        errors.push(
          `${path}: ${error instanceof Error ? error.message : String(error)}`,
        );
      }
    }
  }
  return errors;
}

if (import.meta.main) {
  const errors = await lintWorkflows(Deno.args[0] ?? "workflows");
  if (errors.length) {
    console.error(errors.join("\n"));
    Deno.exit(1);
  }
  console.log("All workflow steps have worker placement");
}

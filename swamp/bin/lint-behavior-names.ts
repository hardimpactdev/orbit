import { parse } from "jsr:@std/yaml@1.0.12";

// A behavior is named after its target, never its run (docs/flows/README.md).
// Each name a run makes stays behind as an auto-definition, and serve scans
// them all on every lookup by id (https://swamp-club.com/lab/4280). A fresh
// name is allowed only when the workflow deletes it with `swamp behavior delete`.
const PER_RUN = /\brun\.id\b|\boperation_id\b/;

type ObjectValue = Record<string, unknown>;
const object = (value: unknown): ObjectValue =>
    value !== null && typeof value === "object" && !Array.isArray(value)
        ? value as ObjectValue
        : {};

/** Steps whose behavior name is fresh per run and that no step of the workflow deletes. */
export function perRunNames(value: unknown): string[] {
    const workflow = object(value);
    const steps = (Array.isArray(workflow.jobs) ? workflow.jobs : []).flatMap((
        job,
    ) => (Array.isArray(object(job).steps)
        ? object(job).steps as unknown[]
        : []).map((step) => ({
            job: String(object(job).name),
            step: object(step),
        }))
    );
    const deletes = steps.map(({ step }) => JSON.stringify(step.task ?? {}))
        .filter((task) => task.includes("behavior delete"));
    const found: string[] = [];
    for (const { job, step } of steps) {
        const task = object(step.task);
        const name = task.behaviorName ?? task.modelName;
        if (typeof name !== "string" || !PER_RUN.test(name)) continue;
        const family = /"([^"]+)"/.exec(name)?.[1];
        if (family && deletes.some((task) => task.includes(family))) continue;
        found.push(`${job}/${String(step.name)}: ${name}`);
    }
    return found;
}

const names = (text: string | null): string[] =>
    text === null ? [] : perRunNames(parse(text));

async function atBase(base: string, path: string): Promise<string | null> {
    const shown = await new Deno.Command("git", {
        args: ["show", `${base}:./${path}`],
        stdout: "piped",
        stderr: "null",
    }).output();
    return shown.success ? new TextDecoder().decode(shown.stdout) : null;
}

/** Workflows with more per-run behavior names than at the base commit. */
export async function lintBehaviorNames(
    root: string,
    base: string,
): Promise<string[]> {
    const errors: string[] = [];
    for await (const entry of Deno.readDir(root)) {
        if (!entry.isFile || !/\.ya?ml$/.test(entry.name)) continue;
        const path = `${root}/${entry.name}`;
        const current = await names(await Deno.readTextFile(path));
        const before = (await names(await atBase(base, path))).length;
        if (current.length > before) {
            errors.push(
                `${path}: ${current.length} behavior names per run (base ${before}); name by target or delete at the end:\n  ` +
                    current.join("\n  "),
            );
        }
    }
    return errors;
}

if (import.meta.main) {
    const [flag, base, root = "workflows"] = Deno.args;
    if (flag !== "--base" || !base) {
        console.error(
            "usage: lint-behavior-names.ts --base <commit> [workflows]",
        );
        Deno.exit(2);
    }
    const errors = await lintBehaviorNames(root, base);
    if (errors.length) {
        console.error(errors.join("\n"));
        Deno.exit(1);
    }
    console.log("No workflow adds a behavior name per run");
}

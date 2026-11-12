import { resolveConstantName } from "./constant-name.ts";
import { parse } from "jsr:@std/yaml@1.0.12";

// A behavior is named after its target, never its run (docs/cli/README.md).
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

// Swamp refuses a definition name outside ^[a-z0-9][a-z0-9_-]*$ when the step
// runs. A literal may hold only those characters, and a dynamic part that can
// hold a dot (an address, a scope) is wrapped in `(...).split(".").join("-")`.
// The run id, the index and integer ids never hold a dot.
const NAME = /^[a-z0-9][a-z0-9_-]*$/;
const DOTLESS = /^\((.*)\)\.split\("\."\)\.join\("-"\)$/s;
const INTEGER_PATH =
    /(?:_index|node_id|process_id|operation_id|group|\.id|participant)$/;

/** True when a dynamic term can only render an integer or the run id. */
function integer(term: string): boolean {
    if (term === "run.id") return true;
    const inner = /^string\((.*)\)$/s.exec(term)?.[1] ?? term;
    // A path compared with null only selects a branch; it is never rendered.
    const paths = [
        ...inner.replace(/[A-Za-z_][\w.]*\s*[!=]=\s*null/g, "").matchAll(
            /[A-Za-z_]\w*(?:\.\w+)*/g,
        ),
    ].map((m) => m[0]).filter((path) =>
        !["null", "true", "false"].includes(path)
    );
    return paths.length > 0 && paths.every((path) => INTEGER_PATH.test(path));
}

/** Top-level `+` terms of a CEL string concatenation. */
function terms(expression: string): string[] {
    const out: string[] = [];
    let depth = 0, quote = "", current = "";
    for (const c of expression) {
        if (quote) {
            current += c;
            if (c === quote) quote = "";
            continue;
        }
        if (c === '"' || c === "'") quote = c;
        else if ("([{".includes(c)) depth++;
        else if (")]}".includes(c)) depth--;
        if (c === "+" && depth === 0) {
            out.push(current.trim());
            current = "";
        } else current += c;
    }
    return [...out, current.trim()];
}

/** Why Swamp would refuse this behavior name, or null when it is valid. */
export function invalidName(name: string): string | null {
    const expression = /^\$\{\{\s*(.*?)\s*\}\}$/s.exec(name)?.[1];
    if (expression === undefined) {
        // Plain text with embedded `${{ ... }}` parts.
        const text = name.replace(
            /\$\{\{\s*(.*?)\s*\}\}/gs,
            (_, part: string) => integer(part) ? "0" : ".",
        );
        return NAME.test(text)
            ? null
            : "not a valid name, or an embedded part can hold a dot";
    }
    // A name the caller passes in is checked where the caller builds it.
    if (!expression.includes('"')) return null;
    const wrapped = DOTLESS.exec(expression)?.[1];
    const parts = terms(wrapped ?? expression);
    const literal = /^"([^"]*)"$/;
    const first = literal.exec(parts[0] ?? "")?.[1];
    if (first === undefined || !/^[a-z0-9]/.test(first)) {
        return "does not start with a lowercase letter or digit";
    }
    for (const part of parts) {
        const text = literal.exec(part)?.[1];
        if (text !== undefined) {
            if (
                !/^[a-z0-9_.-]*$/.test(text) || (!wrapped && text.includes("."))
            ) {
                return `literal "${text}" holds a character Swamp refuses`;
            }
        } else if (!wrapped && !integer(part)) {
            return `"${part}" can hold a dot; wrap the name in (...).split(".").join("-")`;
        }
    }
    return null;
}

/** Steps whose behavior name Swamp would refuse when it runs. */
export function invalidNames(value: unknown): string[] {
    const workflow = object(value);
    const found: string[] = [];
    for (const job of Array.isArray(workflow.jobs) ? workflow.jobs : []) {
        for (
            const raw of Array.isArray(object(job).steps)
                ? object(job).steps as unknown[]
                : []
        ) {
            const step = object(raw);
            const task = object(step.task);
            const name = task.behaviorName ?? task.modelName;
            if (typeof name !== "string") continue;
            const reason = invalidName(name);
            if (reason) {
                found.push(
                    `${String(object(job).name)}/${
                        String(step.name)
                    }: ${name}: ${reason}`,
                );
            }
        }
    }
    return found;
}

/** Names used with more than one behavior type: Swamp refuses the second type ("exists but has type"). */
export function sharedAcrossTypes(
    workflows: Record<string, unknown>,
): string[] {
    const types = new Map<string, Set<string>>();
    for (const [path, value] of Object.entries(workflows)) {
        for (
            const job of Array.isArray(object(value).jobs)
                ? object(value).jobs as unknown[]
                : []
        ) {
            for (
                const raw of Array.isArray(object(job).steps)
                    ? object(job).steps as unknown[]
                    : []
            ) {
                const task = object(object(raw).task);
                const name = task.behaviorName ?? task.modelName;
                const type = task.behaviorType ?? task.modelType;
                if (typeof name !== "string" || typeof type !== "string") {
                    continue;
                }
                const key = resolveConstantName(name).replace(/\s+/g, "");
                if (!types.has(key)) types.set(key, new Set());
                types.get(key)!.add(`${type} (${path})`);
            }
        }
    }
    return [...types].filter(([, used]) =>
        new Set([...used].map((u) => u.split(" (")[0])).size > 1
    )
        .map(([name, used]) =>
            `${name} is used with several behavior types: ${
                [...used].join(", ")
            }`
        );
}

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
    const parsed: Record<string, unknown> = {};
    for await (const entry of Deno.readDir(root)) {
        if (!entry.isFile || !/\.ya?ml$/.test(entry.name)) continue;
        const path = `${root}/${entry.name}`;
        const text = await Deno.readTextFile(path);
        parsed[path] = parse(text);
        const invalid = invalidNames(parse(text));
        if (invalid.length) {
            errors.push(
                `${path}: Swamp refuses these behavior names:\n  ` +
                    invalid.join("\n  "),
            );
        }
        const current = names(text);
        const before = (await names(await atBase(base, path))).length;
        if (current.length > before) {
            errors.push(
                `${path}: ${current.length} behavior names per run (base ${before}); name by target or delete at the end:\n  ` +
                    current.join("\n  "),
            );
        }
    }
    errors.push(...sharedAcrossTypes(parsed));
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
    console.log(
        "No workflow adds a behavior name per run, one Swamp refuses, or one shared across types",
    );
}

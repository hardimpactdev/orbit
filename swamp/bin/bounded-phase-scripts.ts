import ts from "npm:typescript@5.9.3";
import { parser as pythonParser } from "npm:@lezer/python@1.1.18";
import { parse } from "jsr:@std/yaml@1.0.12";
import { posix } from "node:path";

const object = (value: unknown): Record<string, unknown> =>
  value && typeof value === "object" && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {};

/** Resolve shipped script paths, never execute metadata or infer permission from a filename. */
export function boundedPhaseScripts(
  sources: Map<string, string>,
): Map<string, number> {
  const assets = new Map<string, Map<string, Set<string>>>();
  for (const [file, source] of sources) {
    if (!file.endsWith(".ts")) continue;
    const root = ts.createSourceFile(
      file,
      source,
      ts.ScriptTarget.Latest,
      true,
    );
    const types = new Set<string>(), paths = new Map<string, Set<string>>();
    const visit = (node: ts.Node) => {
      if (ts.isPropertyAssignment(node)) {
        const key = node.name.getText(root).replace(/^["']|["']$/g, "");
        const value = node.initializer;
        if (
          key === "type" && ts.isStringLiteral(value) &&
          value.text.startsWith("@")
        ) types.add(value.text);
        if (
          ts.isCallExpression(value) &&
          ts.isPropertyAccessExpression(value.expression) &&
          value.expression.name.text === "extensionFile" &&
          value.arguments.length === 1 &&
          ts.isStringLiteral(value.arguments[0])
        ) {
          const path = posix.normalize(
            posix.join(
              posix.dirname(file),
              value.arguments[0].text,
            ),
          );
          if (/\.(?:py|sh)$/.test(path)) {
            const values = paths.get(key) ?? new Set<string>();
            values.add(path);
            paths.set(key, values);
          }
        }
      }
      ts.forEachChild(node, visit);
    };
    visit(root);
    for (const type of types) {
      const fields = assets.get(type) ?? new Map<string, Set<string>>();
      for (const [key, values] of paths) {
        fields.set(
          key,
          new Set([...(fields.get(key) ?? []), ...values]),
        );
      }
      assets.set(type, fields);
    }
  }
  const calls = new Map<string, (number | undefined)[]>();
  const record = (file: string, seconds?: number) => {
    if (sources.has(file)) {
      calls.set(file, [...(calls.get(file) ?? []), seconds]);
    }
  };
  for (const [file, source] of sources) {
    if (!file.startsWith("workflows/") || !/\.ya?ml$/.test(file)) continue;
    let workflow: Record<string, unknown>;
    try {
      workflow = object(parse(source));
    } catch {
      continue;
    }
    for (
      const rawJob of Array.isArray(workflow.jobs) ? workflow.jobs : []
    ) {
      const steps = (Array.isArray(object(rawJob).steps)
        ? object(rawJob).steps
        : []) as unknown[];
      const named = new Map(
        steps.map((step) => [object(step).name, object(step)]),
      );
      const asset = (value: unknown): string | undefined => {
        if (typeof value !== "string") {
          return undefined;
        }
        const ref = /^\$\{\{\s*steps\.([\w-]+)\.outputs\.([\w-]+)\s*\}\}$/.exec(
          value,
        );
        if (!ref) {
          return undefined;
        }
        const task = object(named.get(ref[1])?.task);
        const values = assets.get(
          String(task.behaviorType ?? task.modelType),
        )?.get(ref[2]);
        return values?.size === 1 ? [...values][0] : undefined;
      };
      for (const rawStep of steps) {
        const task = object(object(rawStep).task),
          inputs = object(task.inputs);
        if (
          task.behaviorType !== "command/shell" ||
          typeof inputs.run !== "string"
        ) {
          continue;
        }
        const run = inputs.run.trim(), env = object(inputs.env);
        const references = new Map<string, string>();
        for (const [key, value] of Object.entries(env)) {
          const path = asset(value);
          if (
            path &&
            (run.includes(`$${key}`) || run.includes(`\${${key}}`))
          ) {
            references.set(`$${key}`, path);
          }
        }
        // A single literal invocation: no command substitution, pipelines, shell wrappers or extra commands.
        const words = /[;|&`\n()<>\\]/.test(run)
          ? []
          : (run.match(/"[^"\n]*"|'[^'\n]*'|[^\s]+/g) ?? [])
            .map((word) =>
              word.replace(/^(["'])(.*)\1$/, "$2")
            );
        if (words[0] === "exec") words.shift();
        let seconds: number | undefined;
        if (words[0]?.split("/").at(-1) === "timeout") {
          words.shift();
          if (words[0] === "--verbose") words.shift();
          const duration = words.shift();
          if (duration && /^[1-9]\d*$/.test(duration)) {
            seconds = Number(duration);
          }
        }
        if (
          /^(?:python[0-9.]*|bash|sh)$/.test(
            words[0]?.split("/").at(-1) ?? "",
          )
        ) {
          words.shift();
          while (/^-[BIeu]+$/.test(words[0] ?? "")) words.shift();
        }
        const command = words[0]?.replace(/^\$\{(\w+)\}$/, "$$$1");
        const path = command
          ? references.get(command) ??
            (sources.has(command) ? command : undefined)
          : undefined;
        for (const referenced of references.values()) {
          record(
            referenced,
            path === referenced ? seconds : undefined,
          );
        }
        for (const referenced of sources.keys()) {
          if (
            /\.(?:py|sh)$/.test(referenced) &&
            run.includes(referenced)
          ) {
            record(
              referenced,
              path === referenced ? seconds : undefined,
            );
          }
        }
      }
    }
  }
  const budgets = new Map<string, number>();
  for (const [file, values] of calls) {
    const bounded = values.every((n) =>
      n !== undefined && Number.isSafeInteger(n)
    );
    budgets.set(file, bounded ? Math.min(...values as number[]) : 0);
  }
  // Follow calls/imports from step-executed files. A wrapper invoked without
  // a bound must invalidate a callee's otherwise bounded direct invocation.
  // Unused test harness imports do not create a runtime call path.
  const scripts = [...sources.keys()].filter((file) =>
    /\.(?:py|sh)$/.test(file)
  );
  const callees = new Map<string, string[]>();
  for (const file of scripts) {
    const source = sources.get(file)!;
    const imports = new Set<string>();
    if (file.endsWith(".py")) {
      pythonParser.parse(source).iterate({
        enter(node) {
          if (node.name !== "ImportStatement") return;
          const cursor = node.node.cursor();
          do {
            if (cursor.name === "VariableName") {
              imports.add(source.slice(cursor.from, cursor.to));
            }
          } while (cursor.next());
          return false;
        },
      });
    }
    callees.set(
      file,
      scripts.filter((target) => {
        if (target === file) return false;
        const name = posix.basename(target);
        return source.includes(target) || source.includes(name) ||
          target.endsWith(".py") && imports.has(name.replace(/\.py$/, ""));
      }),
    );
  }
  for (let i = 0; i <= scripts.length; i++) {
    let changed = false;
    for (const [caller, targets] of callees) {
      if (!budgets.has(caller)) continue;
      for (const target of targets) {
        const bound = Math.min(
          budgets.get(target) ?? Infinity,
          budgets.get(caller)!,
        );
        if (budgets.get(target) !== bound) {
          budgets.set(target, bound);
          changed = true;
        }
      }
    }
    if (!changed) break;
  }
  return budgets;
}

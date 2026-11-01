import ts from "npm:typescript@5.9.3";
import { parser as pythonParser } from "npm:@lezer/python@1.1.18";
import { parse } from "jsr:@std/yaml@1.0.12";
import { posix } from "node:path";

const object = (value: unknown): Record<string, unknown> =>
  value && typeof value === "object" && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {};

export type PhaseBound = { seconds: number; ssh: boolean };
type Asset = { file: string; body: boolean };

function sshCalls(
  task: Record<string, unknown>,
  asset: (value: unknown) => Asset | undefined,
): Map<string, number> {
  const calls = new Map<string, number>();
  const argv = object(task.inputs).argv;
  const expression =
    typeof argv === "string" && /^\$\{\{[\s\S]*\}\}$/.test(argv)
      ? argv.slice(3, -2).trim()
      : Array.isArray(argv)
      ? JSON.stringify(argv)
      : "";
  if (expression) {
    const parsed = ts.createSourceFile(
      "argv.ts",
      `const argv = (${expression});`,
      ts.ScriptTarget.Latest,
      true,
    );
    const declaration = (parsed.statements[0] as ts.VariableStatement)
      ?.declarationList?.declarations[0];
    const refs = new Map<string, Asset>();
    const reference = (node: ts.Node) => {
      const text = ts.isStringLiteral(node)
        ? node.text
        : "${{ " + node.getText(parsed) + " }}";
      return asset(text);
    };
    const collect = (node: ts.Node) => {
      const resolved = reference(node);
      if (resolved?.body) refs.set(resolved.file, resolved);
      ts.forEachChild(node, collect);
    };
    const start = declaration?.initializer;
    if (start) collect(start);
    const bounds = new Map<string, number[]>();
    const leaves = (node: ts.Expression): boolean => {
      if (ts.isParenthesizedExpression(node)) return leaves(node.expression);
      if (ts.isConditionalExpression(node)) {
        // The selector may read inputs, but cannot run a hidden action.
        let pure = true;
        const inspect = (part: ts.Node) => {
          if (
            ts.isCallExpression(part) || ts.isNewExpression(part) ||
            reference(part)?.body
          ) pure = false;
          ts.forEachChild(part, inspect);
        };
        inspect(node.condition);
        const yes = leaves(node.whenTrue), no = leaves(node.whenFalse);
        return pure && yes && no;
      }
      if (!ts.isArrayLiteralExpression(node)) {
        let hasScript = false;
        const inspect = (part: ts.Node) => {
          if (reference(part)?.body) hasScript = true;
          ts.forEachChild(part, inspect);
        };
        inspect(node);
        return !hasScript;
      }
      const elements = node.elements;
      const script = elements[5] && reference(elements[5]);
      const literal = (index: number): string => {
        const element = elements[index];
        return element && ts.isStringLiteral(element) ? element.text : "";
      };
      const duration = literal(1);
      const valid = (task.behaviorType === "@swamp/ssh" ||
        task.modelType === "@swamp/ssh") &&
        task.methodName === "exec" &&
        ["timeout", "/usr/bin/timeout"].includes(literal(0)) &&
        /^[1-9]\d*$/.test(duration) &&
        literal(2) === "/usr/bin/python3" && literal(3) === "-I" &&
        literal(4) === "-c" && script?.body;
      let pure = true;
      const inspect = (part: ts.Node) => {
        if (
          ts.isCallExpression(part) || ts.isNewExpression(part) ||
          ts.isSpreadElement(part) ||
          reference(part)?.body && part !== elements[5]
        ) pure = false;
        ts.forEachChild(part, inspect);
      };
      inspect(node);
      for (const element of elements) {
        const resolved = reference(element);
        if (resolved?.body) {
          const seconds = valid && element === elements[5]
            ? Number(duration)
            : 0;
          bounds.set(resolved.file, [
            ...(bounds.get(resolved.file) ?? []),
            seconds,
          ]);
        }
      }
      return pure;
    };
    const valid = start && leaves(start);
    for (const file of refs.keys()) {
      const values = bounds.get(file);
      calls.set(file, valid && values?.length ? Math.min(...values) : 0);
    }
  }
  return calls;
}

/** Resolve shipped script paths, never execute metadata or infer permission from a filename. */
export function boundedPhaseScripts(
  sources: Map<string, string>,
): Map<string, PhaseBound> {
  const assets = new Map<string, Map<string, Map<string, Asset>>>();
  for (const [file, source] of sources) {
    if (!file.endsWith(".ts")) continue;
    const root = ts.createSourceFile(
      file,
      source,
      ts.ScriptTarget.Latest,
      true,
    );
    const types = new Set<string>(),
      paths = new Map<string, Map<string, Asset>>();
    const visit = (node: ts.Node) => {
      if (ts.isPropertyAssignment(node)) {
        const key = node.name.getText(root).replace(/^["']|["']$/g, "");
        let value = node.initializer;
        let body = false;
        if (ts.isAwaitExpression(value)) {
          const read = value.expression;
          if (
            ts.isCallExpression(read) &&
            read.expression.getText(root) === "Deno.readTextFile" &&
            read.arguments.length === 1
          ) {
            value = read.arguments[0];
            body = true;
          }
        }
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
            const values = paths.get(key) ?? new Map<string, Asset>();
            values.set(`${path}:${body}`, { file: path, body });
            paths.set(key, values);
          }
        }
      }
      ts.forEachChild(node, visit);
    };
    visit(root);
    for (const type of types) {
      const fields = assets.get(type) ?? new Map<string, Map<string, Asset>>();
      for (const [key, values] of paths) {
        fields.set(
          key,
          new Map([...(fields.get(key) ?? []), ...values]),
        );
      }
      assets.set(type, fields);
    }
  }
  const calls = new Map<string, PhaseBound[]>();
  const record = (file: string, seconds = 0, ssh = false) => {
    if (sources.has(file)) {
      calls.set(file, [...(calls.get(file) ?? []), { seconds, ssh }]);
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
      const asset = (value: unknown): Asset | undefined => {
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
        return values?.size === 1 ? [...values.values()][0] : undefined;
      };
      for (const rawStep of steps) {
        const task = object(object(rawStep).task),
          inputs = object(task.inputs);
        // SSH accepts a script body as one argv element. Parse the small CEL
        // array/conditional form as syntax, never evaluate expressions or metadata.
        // Any script-body use outside argv is an unproved invocation path.
        const otherInputs = { ...inputs };
        delete otherInputs.argv;
        for (
          const match of JSON.stringify(otherInputs).matchAll(
            /steps\.([\w-]+)\.outputs\.([\w-]+)/g,
          )
        ) {
          const resolved = asset(
            "${{ steps." + match[1] + ".outputs." + match[2] + " }}",
          );
          if (resolved?.body) {
            record(resolved.file);
          }
        }
        for (const [file, seconds] of sshCalls(task, asset)) {
          record(file, seconds, true);
        }
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
            path && !path.body &&
            (run.includes(`$${key}`) || run.includes(`\${${key}}`))
          ) {
            references.set(`$${key}`, path.file);
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
  const budgets = new Map<string, PhaseBound>();
  for (const [file, values] of calls) {
    const seconds = Math.min(...values.map((value) => value.seconds));
    budgets.set(file, {
      seconds: Number.isSafeInteger(seconds) ? seconds : 0,
      ssh: values.every((value) => value.ssh),
    });
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
        const current = budgets.get(target), parent = budgets.get(caller)!;
        const bound = {
          seconds: Math.min(current?.seconds ?? Infinity, parent.seconds),
          ssh: (current?.ssh ?? true) && parent.ssh,
        };
        if (current?.seconds !== bound.seconds || current?.ssh !== bound.ssh) {
          budgets.set(target, bound);
          changed = true;
        }
      }
    }
    if (!changed) break;
  }
  return budgets;
}

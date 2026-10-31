import ts from "npm:typescript@5.9.3";
import { parse } from "jsr:@std/yaml@1.0.12";
import { posix } from "node:path";

export const SCHEMA = 1;
export const BASELINE = "config/swamp-shape-baseline.json";
export const INVENTORY = "config/agent-command-kinds.json";
const secretName =
  /(?:secret|password|passwd|credential|private.?key|api.?key|token|identity)/i;
const referenceName = /(?:key|id|ref|name|hash|digest|public|count|field)s?$/i;
const secret = (name: string) =>
  /^(?:api|private|access|secret)[_-]?key$/i.test(name) ||
  secretName.test(name) && !referenceName.test(name);
const object = (v: unknown): Record<string, unknown> =>
  v && typeof v === "object" && !Array.isArray(v)
    ? v as Record<string, unknown>
    : {};
export type Finding = {
  file: string;
  rule: string;
  count: number;
  line: number;
  evidence: string[];
};
export type Entry = {
  file: string;
  rule: string;
  count: number;
  evidence: string[];
};
export type Baseline = { schema: number; entries: Entry[] };
type Analysis = {
  findings: Finding[];
  imports: string[];
  types: string[];
  inlineLines: number;
  loops: number;
  agentActions: number;
  unknown: boolean;
};
const empty = (): Analysis => ({
  findings: [],
  imports: [],
  types: [],
  inlineLines: 0,
  loops: 0,
  agentActions: 0,
  unknown: false,
});
export const production = (file: string) =>
  !/(?:^|\/)(?:tests?|fixtures?)(?:\/|$)|(?:_test|\.test|_fixture|native_fixture)\./
    .test(file);
export const scope = (file: string) =>
  production(file) &&
  (/^extensions\/.*\.[cm]?tsx?$/.test(file) ||
    /^(?:workflows|models)\/.*\.ya?ml$/.test(file) ||
    /\.(?:py|sh)$/.test(file) && !file.startsWith("bin/check-swamp-shape"));
const controls =
  /\b(?:sleep|usleep|setTimeout|setInterval|delay|backoff|retry|poll|flock|lockf|Mutex|Semaphore|acquireLock)\b/i;
const python =
  /(?:^|\n)\s*(?:from\s+\w+\s+import|import\s+(?:os|sys|subprocess|json)|def\s+\w+\(|class\s+\w+[:(])|subprocess\.(?:run|Popen|call)/;
const shell =
  /#!.*\b(?:ba|da|z|k)?sh\b|(?:^|\n)\s*(?:set\s+-|sudo\s|systemctl\s|apt-get\s|export\s+[A-Z_]+=)|\b(?:then|fi|esac)\s*(?:\n|$)/;
const lines = (s: string) => s.trim() ? s.trim().split(/\r?\n/).length : 0;

export function analyzeTs(
  file: string,
  source: string,
  inventory: Record<string, string>,
  agentWrappers = new Set<string>(),
): Analysis {
  const result = empty();
  const root = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true);
  if (
    file.startsWith("extensions/behaviors/") &&
    /\bGatewayOperation\b|makeHttp|gatewayHttp|gatewayRequest|directOperation|withPrivateMaterial|\/api\/v1\/operations/
      .test(source)
  ) {
    result.findings.push({
      file,
      rule: "gateway-http",
      count: 1,
      line: 1,
      evidence: [],
    });
  }
  const bindings = new Map<string, ts.Expression>();
  const aliases = new Map<string, string>();
  const scripts = new Map<number, string>();
  const commands = new Set<string>();
  const sinks = new Set([
    "run",
    "invoke",
    "command",
    "system",
    "output",
    "runLocalProgram",
    "localSystemCommand",
    "runNodeCommand",
    "exec",
    "execFile",
    "spawn",
    "spawnSync",
    "execSync",
    "Command",
  ]);
  const agentNames = new Set([
    "runNodeAgent",
    "agentLaunch",
    "localAgent",
    "runAgent",
    ...agentWrappers,
  ]);
  const add = (
    node: ts.Node,
    rule: string,
    count = 1,
    evidence: string[] = [],
  ) => {
    result.findings.push({
      file,
      rule,
      count,
      line: root.getLineAndCharacterOfPosition(node.getStart(root)).line + 1,
      evidence,
    });
  };
  const visit = (node: ts.Node, fn: (node: ts.Node) => void) => {
    fn(node);
    ts.forEachChild(node, (child) => visit(child, fn));
  };
  visit(root, (node) => {
    if (
      ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) &&
      node.initializer
    ) bindings.set(node.name.text, node.initializer);
    if (
      ts.isImportDeclaration(node) && ts.isStringLiteral(node.moduleSpecifier)
    ) {
      const module = node.moduleSpecifier.text;
      if (module.startsWith(".")) {
        result.imports.push(
          posix.normalize(posix.join(posix.dirname(file), module)),
        );
      }
      const named = node.importClause?.namedBindings;
      if (named && ts.isNamedImports(named)) {
        for (const spec of named.elements) {
          aliases.set(
            spec.name.text,
            spec.propertyName?.text ?? spec.name.text,
          );
          if (/node-agent|agent-command|agent_runner/.test(module)) {
            agentNames.add(spec.name.text);
          }
        }
      }
    }
    if (
      ts.isExportDeclaration(node) && node.moduleSpecifier &&
      ts.isStringLiteral(node.moduleSpecifier) &&
      node.moduleSpecifier.text.startsWith(".")
    ) {
      result.imports.push(
        posix.normalize(
          posix.join(posix.dirname(file), node.moduleSpecifier.text),
        ),
      );
    }
    if (
      ts.isPropertyAssignment(node) && node.name.getText(root) === "type" &&
      ts.isStringLiteral(node.initializer) &&
      node.initializer.text.startsWith("@")
    ) result.types.push(node.initializer.text);
  });
  const name = (node: ts.Expression): string =>
    ts.isIdentifier(node)
      ? aliases.get(node.text) ?? node.text
      : ts.isPropertyAccessExpression(node)
      ? node.name.text
      : "";
  const value = (
    node: ts.Node | undefined,
    seen = new Set<string>(),
  ): string | undefined => {
    if (!node) return;
    if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) {
      return node.text;
    }
    if (ts.isTemplateExpression(node)) {
      return node.head.text +
        node.templateSpans.map((s) =>
          (value(s.expression, seen) ?? "<dynamic>") + s.literal.text
        ).join("");
    }
    if (ts.isTaggedTemplateExpression(node)) return value(node.template, seen);
    if (
      ts.isParenthesizedExpression(node) || ts.isAsExpression(node) ||
      ts.isNonNullExpression(node)
    ) return value(node.expression, seen);
    if (ts.isIdentifier(node) && !seen.has(node.text)) {
      const next = new Set(seen);
      next.add(node.text);
      return value(bindings.get(node.text), next);
    }
    if (
      ts.isBinaryExpression(node) &&
      node.operatorToken.kind === ts.SyntaxKind.PlusToken
    ) {
      const a = value(node.left, seen), b = value(node.right, seen);
      return a === undefined || b === undefined ? undefined : a + b;
    }
    if (
      ts.isCallExpression(node) &&
      ts.isPropertyAccessExpression(node.expression) &&
      node.expression.name.text === "join"
    ) {
      let array: ts.Node = node.expression.expression;
      if (ts.isIdentifier(array)) array = bindings.get(array.text) ?? array;
      if (ts.isArrayLiteralExpression(array)) {
        const parts = array.elements.map((e) => value(e, seen));
        if (parts.every((x) => x !== undefined)) {
          return parts.join(value(node.arguments[0], seen) ?? ",");
        }
      }
    }
  };
  const strings = (node: ts.Node): string[] => {
    const out: string[] = [], pending = [node], visited = new Set<ts.Node>();
    while (pending.length && visited.size < 10000) {
      const current = pending.pop()!;
      if (visited.has(current)) continue;
      visited.add(current);
      const literal = value(current);
      if (literal !== undefined) {
        out.push(literal);
        continue;
      }
      if (ts.isIdentifier(current) && bindings.has(current.text)) {
        pending.push(bindings.get(current.text)!);
      } else {ts.forEachChild(current, (child) => {
          pending.push(child);
        });}
    }
    if (pending.length) {
      add(node, "unknown-expression");
      result.unknown = true;
    }
    return out;
  };
  const origins = (node: ts.Node, seen = new Set<ts.Node>()): ts.Node[] => {
    if (seen.has(node)) return [];
    seen.add(node);
    if (
      ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node) ||
      ts.isTemplateExpression(node)
    ) return [node];
    if (ts.isIdentifier(node)) {
      return bindings.has(node.text)
        ? origins(bindings.get(node.text)!, seen)
        : [];
    }
    const out: ts.Node[] = [];
    ts.forEachChild(node, (child) => {
      for (const leaf of origins(child, seen)) out.push(leaf);
    });
    return out;
  };
  const scriptLeaves = new Map<number, string>();
  const oversizedLeaves = new Set<number>();
  const script = (node: ts.Node, text: string) => {
    for (
      const leaf of origins(node).filter((leaf) =>
        ts.isStringLiteralLike(leaf)
          ? leaf.text !== "\n" && leaf.text !== ""
          : true
      )
    ) {
      const raw = value(leaf) ?? "";
      if (!text.includes(raw)) continue;
      scriptLeaves.set(leaf.getStart(root), raw);
      if (lines(text) > 20) oversizedLeaves.add(leaf.getStart(root));
    }
    scripts.set(node.getStart(root), text);
  };
  const privateIds = new Set<string>();
  visit(root, (node) => {
    if (
      ts.isCallExpression(node) &&
      /withPrivateMaterial|privateMaterial/.test(name(node.expression))
    ) {
      for (const arg of node.arguments) {
        if (ts.isArrowFunction(arg) || ts.isFunctionExpression(arg)) {
          for (const parameter of arg.parameters) {
            visit(parameter.name, (part) => {
              if (ts.isIdentifier(part)) {
                privateIds.add(part.text);
              }
            });
          }
        }
      }
    }
  });
  const tainted = (node: ts.Node, seen = new Set<ts.Node>()): boolean => {
    if (seen.has(node)) return false;
    seen.add(node);
    if (ts.isIdentifier(node)) {
      if (privateIds.has(node.text)) return true;
      const expression = bindings.get(node.text);
      return expression ? tainted(expression, seen) : false;
    }
    if (ts.isCallExpression(node)) {
      const called = node.expression.getText(root);
      if (/vault\.get|withPrivateMaterial|privateMaterial/.test(called)) {
        return true;
      }
      if (
        /(?:env\.get|env\[)/.test(called) &&
        node.arguments.some((arg) => strings(arg).some(secret))
      ) return true;
    }
    if (
      ts.isPropertyAccessExpression(node) &&
      /^(?:process\.env|env)$/.test(node.expression.getText(root)) &&
      secret(node.name.text)
    ) return true;
    if (
      (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) &&
      /vault\.get\s*\(|private-material/.test(node.text)
    ) return true;
    let found = false;
    ts.forEachChild(node, (child) => {
      if (tainted(child, seen)) found = true;
    });
    return found;
  };
  // Resolve const aliases and local wrapper functions, not just imported spellings.
  for (let pass = 0; pass < 4; pass++) {
    for (const [id, expression] of bindings) {
      if (
        agentNames.has(name(expression)) ||
        ts.isIdentifier(expression) && agentNames.has(expression.text)
      ) agentNames.add(id);
    }
    visit(root, (node) => {
      if (ts.isFunctionDeclaration(node) && node.name && node.body) {
        let callsAgent = false;
        visit(node.body, (item) => {
          if (
            ts.isCallExpression(item) && agentNames.has(name(item.expression))
          ) callsAgent = true;
        });
        if (callsAgent) agentNames.add(node.name.text);
      }
    });
  }
  visit(root, (node) => {
    if (ts.isExpression(node)) {
      const text = value(node);
      if (text !== undefined && (python.test(text) || shell.test(text))) {
        // Count maximal constructed strings once; identifiers merely reference the same program.
        if (
          !ts.isIdentifier(node) &&
          !(ts.isExpression(node.parent) && value(node.parent)?.includes(text))
        ) script(node, text);
      }
    }
    if (
      ts.isWhileStatement(node) || ts.isDoStatement(node) ||
      ts.isForStatement(node) || ts.isForOfStatement(node)
    ) {
      const text = node.getText(root);
      if (
        /while\s*\(\s*(?:true|1)\s*\)|for\s*\(\s*;\s*;/.test(text) ||
        controls.test(text) ||
        /\b(?:attempt|deadline|retries|poll)\b/i.test(text)
      ) {
        add(node, "custom-control");
        result.loops++;
      }
    }
    if (!(ts.isCallExpression(node) || ts.isNewExpression(node))) return;
    const called = name(node.expression), args = [...(node.arguments ?? [])];
    if (
      /^(?:setTimeout|setInterval|sleep|delay|backoff|retry|poll|acquireLock|flock)$/i
        .test(called)
    ) {
      add(node, "custom-control", 1, [called]);
      result.loops++;
    }
    if (
      /withPrivateMaterial|privateMaterial|readPrivateMaterial/.test(called)
    ) add(node, "private-material");
    if (
      /writeResource|writeFile|appendFile|log|info|error|debug/.test(called) &&
      args.some((arg) => tainted(arg))
    ) add(node, "secret-output");
    const texts = args.flatMap((arg) => strings(arg));
    if (texts.some((t) => t.includes("/private-material"))) {
      add(node, "private-material");
    }
    const isAgent = agentNames.has(called) ||
      texts.some((t) => /(?:^|\/)orbit-agent(?:$|\s)/.test(t));
    if (isAgent) {
      const targets = [...new Set(texts.filter((t) => t in inventory))];
      const actions = targets.filter((t) => inventory[t] !== "observer");
      if (actions.length) {
        add(node, "agent-action", 1, actions.sort());
        result.agentActions++;
      }
      if (!targets.length) {
        add(node, "agent-dynamic");
        result.unknown = true;
      }
    }
    const sink = sinks.has(called) ||
      /(?:Command|spawn|execFile|execSync)$/.test(called);
    if (sink) {
      const binaries = texts.filter((t) =>
        /^\/?(?:[\w.-]+\/)*(?:sudo|python[\d.]*|sh|bash|systemctl|git|curl|apt-get|docker|incus|flock|sleep|orbit-agent|[a-z][\w.-]*)$/
          .test(t) &&
        (/^\/(?:usr\/)?s?bin\//.test(t) ||
          /^(?:sudo|python[\d.]*|sh|bash|systemctl|git|curl|docker|incus|flock|sleep)$/
            .test(t))
      );
      for (const binary of binaries) commands.add(binary);
      if (
        texts.some((t) =>
          /\b(?:flock|lockfile|\.lock\b|LOCK_EX|O_EXCL)\b/.test(t)
        )
      ) {
        add(node, "custom-control", 1, ["lock"]);
        result.loops++;
      }
      for (const arg of args) {
        // Environment, stdin and protected file content are not argv. Inspect only argv/run/command arguments.
        if (ts.isObjectLiteralExpression(arg)) {
          for (const prop of arg.properties) {
            if (
              ts.isPropertyAssignment(prop) &&
              /^(?:args|argv|run|command)$/.test(prop.name.getText(root)) &&
              tainted(prop.initializer)
            ) add(prop, "secret-argv");
          }
        } else if (tainted(arg)) add(arg, "secret-argv");
      }
      if (texts.some((t) => /^-(?:c|Command)$/.test(t))) {
        for (const arg of args) {
          for (const text of strings(arg)) {
            if (
              lines(text) > 1 && (python.test(text) || shell.test(text))
            ) {
              script(arg, text);
            }
          }
        }
      }
    }
  });
  // Deduplicate programs referenced by a wrapper and declared as a constant.
  const uniqueScripts = [...new Set(scripts.values())];
  result.inlineLines = [...scriptLeaves.values()].reduce(
    (sum, text) => sum + lines(text),
    0,
  );
  const oversizedLines = [...oversizedLeaves].reduce(
    (sum, key) => sum + lines(scriptLeaves.get(key)!),
    0,
  );
  if (oversizedLines) add(root, "inline-script", oversizedLines);
  for (const text of uniqueScripts) {
    const subprocesses = [
      ...text.matchAll(
        /(?:subprocess\.(?:run|call|Popen|check_output|check_call)|os\.system)\s*\(\s*(?:\[\s*)?[rbuf]*['"]([^'"\n]+)/g,
      ),
    ];
    for (const match of subprocesses) commands.add(match[1]);
    const loops = text.match(
      /\b(?:while\s+(?:True|true|:)|time\.sleep\s*\(|sleep\s+\d|flock\s*\(|LOCK_EX|O_EXCL|lockfile|for\s+\w*(?:attempt|retry)\w*\s+in)\b/g,
    ) ?? [];
    if (loops.length) {
      add(root, "custom-control", loops.length, ["embedded-control"]);
      result.loops += loops.length;
    }
    if (/set\s+-(?:\w*x\w*|o\s+xtrace)\b/.test(text)) {
      add(root, "secret-xtrace");
    }
    if (
      /(?:subprocess\.(?:run|Popen|call)|os\.system)[^\n]*(?:password|secret|private_key|token)|\b(?:curl|exec|sudo|python\w*)[^\n]*\$\{?\w*(?:SECRET|TOKEN|PASSWORD|IDENTITY)/i
        .test(text)
    ) add(root, "secret-argv");
  }
  if (commands.size > 1) {
    add(root, "multi-action", commands.size, [...commands].sort());
  }
  return result;
}

/** Public vault/key descriptors are references, including closed named containers. */
export function referenceOnlySchema(raw: unknown): boolean {
  const schema = object(raw),
    properties = object(schema.properties),
    keys = Object.keys(properties);
  if (
    schema.type !== "object" || schema.additionalProperties !== false ||
    !keys.length
  ) return false;
  const required = schema.required;
  if (!Array.isArray(required) || keys.some((key) => !required.includes(key))) {
    return false;
  }
  if (keys.length === 2 && keys.includes("vault") && keys.includes("key")) {
    const vault = object(properties.vault), key = object(properties.key);
    return vault.type === "string" && Array.isArray(vault.enum) &&
      vault.enum.length > 0 && vault.enum.every((value) =>
        typeof value === "string"
      ) && key.type === "string" && typeof key.pattern === "string";
  }
  return Object.values(properties).every(referenceOnlySchema);
}

export function analyzeYaml(file: string, source: string): Analysis {
  const result = empty();
  const add = (rule: string, evidence: string[] = [], count = 1) =>
    result.findings.push({ file, rule, count, line: 1, evidence });
  let data: Record<string, unknown>;
  try {
    data = object(parse(source));
  } catch {
    add("unknown-yaml");
    result.unknown = true;
    return result;
  }
  const walk = (value: unknown, path: string[]) => {
    if (typeof value === "string") {
      const envValue = path.length >= 4 && path.at(-2) === "env" &&
        path.at(-3) === "inputs" && path.at(-4) === "task";
      if (
        envValue && secret(path.at(-1) ?? "") &&
        !/^\s*\$\{\{\s*vault\.get\([\s\S]*\)\s*\}\}\s*$/.test(value)
      ) add("secret-env-source");
      if (
        /vault\.get\s*\(/.test(value) &&
        !(envValue &&
          /^\s*\$\{\{\s*vault\.get\([\s\S]*\)\s*\}\}\s*$/.test(value) &&
          !/\}\}[\s\S]*\$\{\{|\)\s*\+/.test(value))
      ) add("vault-placement");
      if (
        /\$\{\{\s*env\.[A-Za-z_]*(?:SECRET|PASSWORD|TOKEN|PRIVATE_KEY|IDENTITY)/i
          .test(value)
      ) add("secret-env-source");
      if (path.includes("properties") && secret(path.at(-1) ?? "")) {
        add("secret-input");
      }
      if (/private-material|withPrivateMaterial/.test(value)) {
        add("private-material");
      }
      if (/set\s+-(?:\w*x\w*|o\s+xtrace)\b/.test(value)) add("secret-xtrace");
      if (
        (path.at(-1) === "run" || path.includes("argv") ||
          path.includes("args")) &&
        /\$\{?\w*(?:SECRET|TOKEN|PASSWORD|PRIVATE_KEY|IDENTITY)|vault\.get\s*\(/i
          .test(value)
      ) add("secret-argv");
      if (path.at(-1) === "run") {
        const scripts =
          value.match(/(?:^|[\s"'])scripts\/[A-Za-z0-9_./-]+\.(?:py|sh)\b/g) ??
            [];
        for (const script of scripts) {
          result.imports.push(script.trim().replace(/^["']/, ""));
        }
        if (/^\s*\$\{\{/.test(value)) {
          add("unknown-command");
          result.unknown = true;
        }
        if (
          /(?:&&|\|\||;)[ \t]*(?:sudo|systemctl|apt|curl|docker|python|bash|sh)\b/
            .test(value)
        ) add("multi-action");
        const controlsFound = value.match(
          /\b(?:sleep|flock|lockfile)\b|while\s+(?:true|:)|for\s+\w*(?:attempt|retry)\w*/g,
        ) ?? [];
        for (const _ of controlsFound) {
          add("custom-control");
          result.loops++;
        }
        if (/\borbit-agent\b/.test(value)) {
          add("agent-action");
          result.agentActions++;
        }
      }
      return;
    }
    if (Array.isArray(value)) {
      value.forEach((item, i) => walk(item, [...path, String(i)]));
      return;
    }
    for (const [key, item] of Object.entries(object(value))) {
      if (
        path[0] === "inputs" && path.at(-1) === "properties" && secret(key) &&
        !referenceOnlySchema(item)
      ) {
        add("secret-input");
      }
      if (
        key === "env" && !path.includes("task") &&
        Object.keys(object(item)).some(secret)
      ) add("secret-env-source");
      walk(item, [...path, key]);
    }
  };
  walk(data, []);
  if (file.startsWith("workflows/")) {
    if (!Array.isArray(data.jobs) || !data.jobs.length) {
      add("unknown-workflow");
      result.unknown = true;
    }
    for (const raw of Array.isArray(data.jobs) ? data.jobs : []) {
      const job = object(raw);
      if (!Array.isArray(job.steps) || !job.steps.length) {
        add("unknown-workflow");
        result.unknown = true;
      }
      for (const rawStep of Array.isArray(job.steps) ? job.steps : []) {
        const task = object(object(rawStep).task);
        if (
          !["behavior", "model_method", "workflow", "manual_approval", "assert"]
            .includes(
              String(task.type),
            )
        ) {
          add("unknown-workflow");
          result.unknown = true;
        }
        const type = task.behaviorType ?? task.modelType ??
          (["behavior", "model_method"].includes(String(task.type)) &&
            (task.behavior ?? task.modelIdOrName));
        if (typeof type === "string" && type.startsWith("@")) {
          result.types.push(type);
        } else if (
          ["behavior", "model_method"].includes(String(task.type)) &&
          type !== "command/shell"
        ) {
          add("unknown-behavior");
          result.unknown = true;
        }
        if (task.type === "workflow") {
          add("unknown-nested-workflow");
          result.unknown = true;
        }
      }
    }
  }
  return result;
}

export function analyzeScript(file: string, source: string): Analysis {
  const result = empty();
  if (file.endsWith(".py")) return result; // Python is parsed by the AST helper, never regexed as executable text.
  const add = (rule: string, count = 1) =>
    result.findings.push({ file, rule, count, line: 1, evidence: [] });
  const withoutComments = source.split("\n").filter((l) => !/^\s*#/.test(l))
    .join("\n");
  if (/set\s+-(?:\w*x\w*|o\s+xtrace)\b/.test(withoutComments)) {
    add("secret-xtrace");
  }
  if (
    /(?:subprocess\.(?:run|Popen|call)|os\.system)[^\n]*(?:password|secret|private_key|token|identity)|\b(?:curl|exec|sudo|python\w*)[^\n]*\$\{?\w*(?:SECRET|TOKEN|PASSWORD|IDENTITY)/i
      .test(withoutComments)
  ) add("secret-argv");
  const controlsFound = withoutComments.match(
    /\b(?:time\.sleep\s*\(|sleep\s+\d|flock\b|LOCK_EX\b|lockfile\b)|while\s+(?:True|true|:)\b/g,
  ) ?? [];
  if (controlsFound.length) {
    add("custom-control", controlsFound.length);
    result.loops += controlsFound.length;
  }
  const agents = withoutComments.match(/\borbit-agent\b/g) ?? [];
  if (agents.length) {
    add("agent-action", agents.length);
    result.agentActions += agents.length;
  }
  return result;
}

export function aggregate(findings: Finding[]): Entry[] {
  const rows = new Map<string, Entry>();
  for (const f of findings) {
    const key = `${f.file}\0${f.rule}`;
    const entry = rows.get(key) ??
      { file: f.file, rule: f.rule, count: 0, evidence: [] };
    entry.count += f.count;
    entry.evidence = [...new Set([...entry.evidence, ...f.evidence])].sort();
    rows.set(key, entry);
  }
  return [...rows.values()].sort((a, b) =>
    a.file.localeCompare(b.file) || a.rule.localeCompare(b.rule)
  );
}

export function ratchet(
  current: Entry[],
  baseline: Baseline,
  previous?: Baseline,
): string[] {
  const errors: string[] = [];
  const index = (rows: Entry[]) =>
    new Map(rows.map((e) => [`${e.file}\0${e.rule}`, e]));
  const allowed = index(baseline.entries),
    old = previous ? index(previous.entries) : undefined;
  if (baseline.schema !== SCHEMA || previous && previous.schema !== SCHEMA) {
    errors.push("Unsupported baseline schema");
  }
  for (const entry of baseline.entries) {
    if (
      !Number.isSafeInteger(entry.count) || entry.count < 1 ||
      !scope(entry.file) || !Array.isArray(entry.evidence)
    ) errors.push(`Invalid baseline entry ${entry.file} ${entry.rule}`);
    if (old) {
      const prior = old.get(`${entry.file}\0${entry.rule}`);
      if (
        !prior || entry.count > prior.count ||
        entry.evidence.some((x) => !prior.evidence.includes(x))
      ) errors.push(`Baseline may only shrink: ${entry.file} ${entry.rule}`);
    }
  }
  if (allowed.size !== baseline.entries.length) {
    errors.push("Duplicate baseline entries");
  }
  for (const entry of current) {
    const limit = allowed.get(`${entry.file}\0${entry.rule}`);
    if (
      !limit || entry.count > limit.count ||
      entry.evidence.some((x) => !limit.evidence.includes(x))
    ) {
      errors.push(
        `${entry.file}: ${entry.rule} ${entry.count} exceeds ${
          limit?.count ?? 0
        }`,
      );
    }
  }
  return errors;
}

async function run(argv: string[], stdin?: string) {
  const process = new Deno.Command(argv[0], {
    args: argv.slice(1),
    stdin: stdin === undefined ? "null" : "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const output = process.output();
  if (stdin !== undefined) {
    const writer = process.stdin.getWriter();
    await writer.write(new TextEncoder().encode(stdin));
    await writer.close();
  }
  const result = await output;
  return { ...result, text: new TextDecoder().decode(result.stdout) };
}
let gitPrefix = "";
async function filesAt(ref?: string): Promise<Map<string, string>> {
  let listed = await run(
    ref
      ? ["git", "ls-tree", "-rz", "--name-only", ref, "--", "."]
      : ["git", "ls-files", "-z"],
  );
  if (!listed.success) throw new Error("Cannot enumerate source tree");
  let blobPrefix = gitPrefix;
  const selected = (text: string) =>
    text.split("\0").filter((f) =>
      scope(f) || f === BASELINE || f === INVENTORY
    );
  if (ref && gitPrefix && !selected(listed.text).length) {
    // Initial history-preserving import: the explicit source base is still at its old root.
    const original = await run(["git", "show", `${ref}:.swamp.yaml`]);
    const lineage = await run([
      "git",
      "merge-base",
      "--is-ancestor",
      ref,
      "HEAD",
    ]);
    const current = object(parse(await Deno.readTextFile(".swamp.yaml")));
    if (
      !original.success || !lineage.success || !current.repoId ||
      object(parse(original.text)).repoId !== current.repoId
    ) {
      throw new Error(
        "No matching Swamp base tree; pass the preserved source commit for the first import",
      );
    }
    const root = (await run(["git", "rev-parse", "--show-toplevel"])).text
      .trim();
    listed = await run([
      "git",
      "-C",
      root,
      "ls-tree",
      "-rz",
      "--name-only",
      ref,
    ]);
    blobPrefix = "";
  }
  const names = selected(listed.text);
  if (!ref) {
    return new Map(
      await Promise.all(
        names.map(async (f) => [f, await Deno.readTextFile(f)] as const),
      ),
    );
  }
  const batch = await run(
    ["git", "cat-file", "--batch"],
    names.map((f) => `${ref}:${blobPrefix}${f}\n`).join(""),
  );
  if (!batch.success) throw new Error("Cannot read base source tree");
  let offset = 0;
  const decoder = new TextDecoder(), sources = new Map<string, string>();
  for (const file of names) {
    const end = batch.stdout.indexOf(10, offset);
    const header = decoder.decode(batch.stdout.subarray(offset, end));
    const size = Number(header.split(" ").at(-1));
    if (!header.includes(" blob ") || !Number.isSafeInteger(size)) {
      throw new Error("Invalid base blob");
    }
    offset = end + 1;
    sources.set(
      file,
      decoder.decode(batch.stdout.subarray(offset, offset + size)),
    );
    offset += size + 1;
  }
  return sources;
}
let pythonAnalyzer = "bin/swamp-shape-python.py";
async function toolFindings(sources: Map<string, string>): Promise<Finding[]> {
  const files: [string, string, string][] = [...sources].filter(([file]) =>
    /\.(?:py|sh)$/.test(file)
  ).map(([file, text]) => [file, text, file.endsWith(".sh") ? "sh" : "py"]);
  for (const [file, source] of sources) {
    if (/\.ya?ml$/.test(file)) {
      let document: unknown;
      try {
        document = parse(source);
      } catch {
        continue;
      }
      const walk = (value: unknown) => {
        if (Array.isArray(value)) {
          value.forEach(walk);
          return;
        }
        for (const [key, item] of Object.entries(object(value))) {
          if (key === "run" && typeof item === "string") {
            const shell = item.replace(/\$\{\{[\s\S]*?\}\}/g, "shape_value");
            files.push([file, shell, "sh"]);
            for (
              const block of shell.matchAll(
                /(?:python[\d.]*[^\n]*<<[\t ]*['"]?(\w+)['"]?[^\n]*\n)([\s\S]*?)\n\1(?:\n|$)/g,
              )
            ) files.push([file, block[2], "py"]);
          } else walk(item);
        }
      };
      walk(document);
    }
  }
  const findings: Finding[] = [];
  let cursor = 0;
  await Promise.all(Array.from({ length: 4 }, async () => {
    while (cursor < files.length) {
      const [file, source, language] = files[cursor++];
      const shell = language === "sh";
      if (!shell) {
        const python = await run(["python3", pythonAnalyzer], source);
        if (!python.success) throw new Error("Python source analysis failed");
        for (const item of JSON.parse(python.text)) {
          findings.push({
            file,
            rule: item.rule,
            count: item.count,
            line: item.line,
            evidence: [],
          });
        }
      }
      const checked = await run(
        shell ? ["shellcheck", "--format=json", "--shell=bash", "-"] : [
          "ruff",
          "check",
          "--isolated",
          "--select=E4,E7,E9,F,I",
          "--output-format=json",
          "--stdin-filename",
          language === "py" ? file + ".py" : file,
          "-",
        ],
        source,
      );
      let diagnostics: Record<string, unknown>[];
      try {
        diagnostics = JSON.parse(checked.text);
      } catch {
        throw new Error(
          `${shell ? "shellcheck" : "ruff"} unavailable or invalid response`,
        );
      }
      if (!Array.isArray(diagnostics) || checked.code > 1) {
        throw new Error("Script linter failed");
      }
      for (const d of diagnostics) {
        findings.push({
          file,
          rule: `${shell ? "shellcheck.SC" : "ruff."}${d.code}`,
          count: 1,
          line: Number(d.line ?? object(d.location).row ?? 1),
          evidence: [],
        });
      }
    }
  }));
  return findings;
}
export function analyze(
  sources: Map<string, string>,
  inventory: Record<string, string>,
) {
  const analyses = new Map<string, Analysis>();
  const wrappers = new Set<string>();
  for (const [file, source] of sources) {
    if (!file.endsWith(".ts") || !/node-agent/.test(source)) continue;
    const ast = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true);
    const aliases = new Set(["runNodeAgent", "agentLaunch"]);
    const walk = (node: ts.Node) => {
      if (
        ts.isImportSpecifier(node) &&
        ["runNodeAgent", "agentLaunch"].includes(
          node.propertyName?.text ?? node.name.text,
        )
      ) aliases.add(node.name.text);
      if (ts.isFunctionDeclaration(node) && node.name && node.body) {
        let calls = false;
        const inspect = (child: ts.Node) => {
          if (
            ts.isCallExpression(child) && ts.isIdentifier(child.expression) &&
            aliases.has(child.expression.text)
          ) calls = true;
          ts.forEachChild(child, inspect);
        };
        inspect(node.body);
        if (calls) wrappers.add(node.name.text);
      }
      ts.forEachChild(node, walk);
    };
    walk(ast);
  }
  for (const [file, source] of sources) {
    if (scope(file)) {
      analyses.set(
        file,
        /\.[cm]?tsx?$/.test(file)
          ? analyzeTs(file, source, inventory, wrappers)
          : /\.ya?ml$/.test(file)
          ? analyzeYaml(file, source)
          : analyzeScript(file, source),
      );
    }
  }
  for (const [file, a] of analyses) {
    for (const dependency of a.imports) {
      if (/\.(?:ts|py|sh)$/.test(dependency) && !analyses.has(dependency)) {
        a.unknown = true;
        a.findings.push({
          file,
          rule: "unknown-dependency",
          count: 1,
          line: 1,
          evidence: [dependency],
        });
      }
    }
  }
  return analyses;
}
export function workflowCoverage(analyses: Map<string, Analysis>) {
  const types = new Map<string, string[]>();
  for (const [file, a] of analyses) {
    if (file.startsWith("extensions/")) {
      for (const type of a.types) {
        types.set(type, [...(types.get(type) ?? []), file]);
      }
    }
  }
  let clean = 0, unknown = 0, accepted = 0, total = 0;
  for (const [file, a] of analyses) {
    if (file.startsWith("workflows/")) {
      total++;
      const queue = [file], seen = new Set<string>();
      let uncertain = a.unknown, findings = false;
      for (const type of a.types) {
        const files = types.get(type);
        if (!files) uncertain = true;
        else queue.push(...files);
      }
      while (queue.length) {
        const current = queue.pop()!;
        if (seen.has(current)) continue;
        seen.add(current);
        const node = analyses.get(current);
        if (!node) {
          uncertain = true;
          continue;
        }
        findings ||= node.findings.length > 0;
        uncertain ||= node.unknown;
        for (const dependency of node.imports) queue.push(dependency);
      }
      if (uncertain) unknown++;
      else if (findings) accepted++;
      else clean++;
    }
  }
  return { total, clean, unknown, baseline_accepted: accepted };
}

if (import.meta.main) {
  try {
    const option = (key: string) => {
      const i = Deno.args.indexOf(key);
      return i < 0 ? undefined : Deno.args[i + 1];
    };
    pythonAnalyzer = option("--python-analyzer") ?? pythonAnalyzer;
    gitPrefix = (await run(["git", "rev-parse", "--show-prefix"])).text.trim();
    const baseRef = option("--base") ?? "origin/main";
    const base = await filesAt(baseRef), head = await filesAt();
    const inventory = JSON.parse(head.get(INVENTORY) ?? "{}");
    if (!Object.keys(inventory).length) {
      throw new Error("Missing explicit agent observer/action inventory");
    }
    const oldInventory = JSON.parse(base.get(INVENTORY) ?? "{}");
    for (const [command, kind] of Object.entries(inventory)) {
      if (
        !["action", "observer"].includes(String(kind)) ||
        kind === "observer" && Object.keys(oldInventory).length > 0 &&
          oldInventory[command] !== kind
      ) throw new Error("Agent inventory cannot expand observation permission");
    }
    const analyses = analyze(head, inventory);
    const external = await toolFindings(head);
    for (const f of external) analyses.get(f.file)?.findings.push(f);
    const entries = aggregate(
      [...analyses.values()].flatMap((a) => a.findings),
    );
    let prior: Baseline;
    if (base.has(BASELINE)) prior = JSON.parse(base.get(BASELINE)!);
    else {
      const baseAnalyses = analyze(base, inventory);
      prior = {
        schema: SCHEMA,
        entries: aggregate(
          [...baseAnalyses.values()].flatMap((a) => a.findings).concat(
            await toolFindings(base),
          ),
        ),
      };
    }
    if (Deno.args.includes("--write-baseline")) {
      // Intersect with base: even bootstrap cannot grandfather new code in this PR.
      const caps = new Map(
        prior.entries.map((e) => [`${e.file}\0${e.rule}`, e]),
      );
      const reduced = entries.map((e) => ({
        ...e,
        count: Math.min(e.count, caps.get(`${e.file}\0${e.rule}`)?.count ?? 0),
        evidence: e.evidence.filter((x) =>
          caps.get(`${e.file}\0${e.rule}`)?.evidence.includes(x)
        ),
      })).filter((e) => e.count > 0);
      await Deno.writeTextFile(
        BASELINE,
        JSON.stringify({ schema: SCHEMA, entries: reduced }, null, 2) + "\n",
      );
      head.set(BASELINE, JSON.stringify({ schema: SCHEMA, entries: reduced }));
    }
    const baseline: Baseline = JSON.parse(head.get(BASELINE) ?? "null");
    if (!baseline || !Array.isArray(baseline.entries)) {
      throw new Error("Missing explicit baseline");
    }
    const baseAnalyses = analyze(base, inventory);
    const baseEntries = aggregate(
      [...baseAnalyses.values()].flatMap((a) => a.findings).concat(
        await toolFindings(base),
      ),
    );
    const errors = [
      ...ratchet(entries, baseline, prior),
      ...ratchet(entries, { schema: SCHEMA, entries: baseEntries }),
    ];
    for (const entry of baseline.entries) {
      const actual = entries.find((row) =>
        row.file === entry.file && row.rule === entry.rule
      );
      if (
        !actual || entry.count !== actual.count ||
        entry.evidence.some((x) => !actual.evidence.includes(x))
      ) {
        errors.push(
          `Remove stale baseline allowance: ${entry.file} ${entry.rule}`,
        );
      }
    }
    const coverage = workflowCoverage(analyses);
    const commit = (await run(["git", "rev-parse", "HEAD"])).text.trim();
    const values = [...analyses.values()];
    const metrics = {
      schema_version: SCHEMA,
      commit,
      units: {
        inline_script_lines: "decoded_script_lines_by_source_literal",
        custom_loops: "syntactic_control_sites",
        agent_action_calls: "syntactic_call_sites",
        workflows: "workflow_files",
        grandfathered: "file_rule_entries",
      },
      inline_script_lines: values.reduce((n, a) => n + a.inlineLines, 0),
      custom_loops: values.reduce((n, a) => n + a.loops, 0),
      agent_action_calls: values.reduce((n, a) => n + a.agentActions, 0),
      workflows: coverage,
      grandfathered: entries.length,
      violations: entries,
      errors,
    };
    console.log(JSON.stringify(metrics));
    console.log(
      `SHAPE-GATE workflows_passing=${coverage.clean}/${coverage.total} grandfathered=${entries.length} baseline_accepted=${coverage.baseline_accepted} unknown=${coverage.unknown}`,
    );
    if (option("--report")) {
      await Deno.writeTextFile(
        option("--report")!,
        JSON.stringify(metrics, null, 2) + "\n",
      );
    }
    if (errors.length) {
      console.error(errors.join("\n"));
      Deno.exit(1);
    }
  } catch (error) {
    console.error(error instanceof Error ? error.stack : String(error));
    Deno.exit(1);
  }
}

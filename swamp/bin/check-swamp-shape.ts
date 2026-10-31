import { parser as pythonParser } from "npm:@lezer/python@1.1.18";
import ts from "npm:typescript@5.9.3";
import { parse } from "jsr:@std/yaml@1.0.12";
import { posix } from "node:path";
import {
  movedLimits,
  proveProxyHttpMove,
  PROXY_HTTP_MOVE,
  type SourceMove,
} from "./swamp-shape-relocations.ts";

export const SCHEMA = 1;
export const BASELINE = "config/swamp-shape-baseline.json";
export const INVENTORY = "config/agent-command-kinds.json";
const secretName =
  /(?:secret|password|passwd|credential|private.?key|api.?key|token|identity)/i;
const referenceName = /(?:key|id|ref|name|hash|digest|public|count|field)s?$/i;
// SourceControl's normalized host/org/repo identifier contains no authentication material.
const publicIdentifiers = new Set(["repository_identity"]);
const secret = (name: string) =>
  !publicIdentifiers.has(name) && (
    /^(?:api|private|access|secret)[_-]?key$/i.test(name) ||
    secretName.test(name) && !referenceName.test(name)
  );
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
  workflowNames: string[];
  workflowRefs: string[];
  modelNames: string[];
  modelRefs: string[];
  inlineLines: number;
  loops: number;
  agentActions: number;
  unknown: boolean;
};
const empty = (): Analysis => ({
  findings: [],
  imports: [],
  types: [],
  workflowNames: [],
  workflowRefs: [],
  modelNames: [],
  modelRefs: [],
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

// These are the only execution primitives a Swamp step may call.
export const GATEWAY_PRIMITIVES = [
  "/api/v1/direct-operations/{operation_id}/github-token",
  "/api/v1/direct-operations/{operation_id}/certificates/sign",
] as const;
export function isGatewayPrimitive(
  method: string | undefined,
  url: string | undefined,
): boolean {
  if (method !== "POST" || !url || /[?#\\]/.test(url)) return false;
  const path = url.replace(/^<gateway-origin>/, "").replace(
    /^https?:\/\/[^/]+/,
    "",
  );
  return GATEWAY_PRIMITIVES.some((route) => {
    const pattern = route.split("{operation_id}").map((part) =>
      part.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")
    ).join("(?:[1-9][0-9]*|\\{operation_id\\})");
    return new RegExp(`^${pattern}$`).test(path);
  });
}

export function gatewayShellFindings(file: string, source: string): Finding[] {
  const findings: Finding[] = [];
  const gatewayContext = /gateway|\/api\/v1\//i.test(source);
  for (const line of source.replace(/\\\r?\n/g, " ").split("\n")) {
    if (/^\s*#/.test(line) || !/\b(?:curl|wget)\s/.test(line)) continue;
    const urls = [...line.matchAll(/https?:\/\/[^\s'"`]+/g)].map((match) =>
      match[0]
    );
    const gatewayUrls = urls.filter((url) => /gateway|\/api\/v1\//i.test(url));
    if (!gatewayUrls.length && (!gatewayContext || urls.length)) continue;
    const method =
      /(?:-X\s*|--request(?:=|\s+))['"]?([A-Z]+)/.exec(line)?.[1] ??
        "GET";
    const targets = gatewayUrls.length ? gatewayUrls : [undefined];
    for (const url of targets) {
      if (!isGatewayPrimitive(method, url)) {
        findings.push({
          file,
          rule: "gateway-http",
          count: 1,
          line: 1,
          evidence: [`${method} ${url ?? "<unknown>"}`],
        });
      }
    }
  }
  return findings;
}

type PythonNode = ReturnType<typeof pythonParser.parse>["topNode"];
const quoteShellWord = (value: string) =>
  "'" + value.replaceAll("'", "'\\''") + "'";
export function agentPythonFindings(
  file: string,
  source: string,
  inventory: Record<string, string>,
  depth = 0,
): Finding[] {
  const root = pythonParser.parse(source).topNode;
  type Binding = { statement: PythonNode; value?: PythonNode };
  const bindings = new Map<string, Binding[]>(),
    aliases = new Map<string, string>(),
    crossScopeNames = new Set<string>();
  const children = (node: PythonNode) => {
    const out: PythonNode[] = [];
    for (let child = node.firstChild; child; child = child.nextSibling) {
      out.push(child);
    }
    return out;
  };
  const text = (node: PythonNode) => source.slice(node.from, node.to);
  const visit = (node: PythonNode, fn: (node: PythonNode) => void) => {
    fn(node);
    for (const child of children(node)) visit(child, fn);
  };
  const lexicalScope = (node: PythonNode): PythonNode => {
    let current = node;
    while (
      current.parent &&
      !["FunctionDefinition", "LambdaExpression", "Script"].includes(
        current.name,
      )
    ) current = current.parent;
    return current;
  };
  const bind = (id: PythonNode, statement: PythonNode, value?: PythonNode) => {
    const key = text(id);
    bindings.set(key, [...(bindings.get(key) ?? []), { statement, value }]);
  };
  visit(root, (node) => {
    if (node.name === "ScopeStatement") {
      for (const part of children(node)) {
        if (part.name === "VariableName") crossScopeNames.add(text(part));
      }
    }
    if (
      node.name === "NamedExpression" &&
      node.firstChild?.name === "VariableName"
    ) {
      bind(node.firstChild, node);
    }
    if (node.name === "DeleteStatement") {
      visit(node, (part) => {
        if (part.name === "VariableName") bind(part, node);
      });
    }
    if (
      ["AssignStatement", "UpdateStatement", "ForStatement", "WithStatement"]
        .includes(node.name)
    ) {
      const parts = children(node);
      if (node.name === "AssignStatement" || node.name === "UpdateStatement") {
        const end = parts.findIndex((part) =>
          ["AssignOp", "UpdateOp"].includes(part.name)
        );
        const targets = parts.slice(0, end);
        for (const target of targets) {
          visit(target, (id) => {
            if (id.name === "VariableName") {
              bind(
                id,
                node,
                targets.length === 1 && target === id &&
                  node.name === "AssignStatement"
                  ? node.lastChild ?? undefined
                  : undefined,
              );
            }
          });
        }
      } else if (node.name === "ForStatement") {
        const end = parts.findIndex((part) => part.name === "in");
        for (const target of parts.slice(1, end)) {
          visit(target, (id) => {
            if (id.name === "VariableName") bind(id, node);
          });
        }
      } else {
        const start = parts.findIndex((part) => part.name === "as");
        for (
          const target of parts.slice(start + 1).filter((part) =>
            part.name !== "Body"
          )
        ) {
          visit(target, (id) => {
            if (id.name === "VariableName") bind(id, node);
          });
        }
      }
    }
    if (node.name === "ImportStatement") {
      const statement = text(node);
      const imported = /^from\s+([\w.]+)\s+import\s+(\w+)(?:\s+as\s+(\w+))?/
        .exec(statement);
      const module = /^import\s+([\w.]+)\s+as\s+(\w+)/.exec(statement);
      if (imported) {
        aliases.set(
          imported[3] ?? imported[2],
          imported[1] + "." + imported[2],
        );
      }
      if (module) aliases.set(module[2], module[1]);
    }
  });
  const name = (node: PythonNode) => {
    const raw = text(node), first = /^[A-Za-z_]\w*/.exec(raw)?.[0];
    return first && aliases.has(first)
      ? aliases.get(first) + raw.slice(first.length)
      : raw;
  };
  const bound = (id: string, at: PythonNode): Binding | undefined => {
    // A global/nonlocal binding can change in a separate function invocation.
    // Local statement order alone cannot prove its value at this call.
    if (crossScopeNames.has(id)) return;
    let scope: PythonNode | null = lexicalScope(at);
    const own = scope;
    while (scope) {
      const writes = (bindings.get(id) ?? []).filter((binding) =>
        lexicalScope(binding.statement) === scope
      );
      if (writes.length) {
        if (scope !== own && writes.length !== 1) return;
        const prior = writes.filter((binding) =>
          binding.statement.from < at.from
        );
        const binding = prior.at(-1);
        if (!binding) return;
        const parent = binding.statement.parent;
        let use: PythonNode | null = at;
        while (use?.parent && use.parent !== parent) use = use.parent;
        if (use && use.parent === parent && binding.statement.from < use.from) {
          return binding;
        }
        return;
      }
      const parameters = children(scope).find((part) =>
        part.name === "ParamList"
      );
      let parameter = false;
      if (parameters) {
        visit(parameters, (part) => {
          if (part.name === "VariableName" && text(part) === id) {
            parameter = true;
          }
        });
      }
      if (parameter) return;
      scope = scope.parent ? lexicalScope(scope.parent) : null;
    }
  };
  const argumentsOf = (node: PythonNode) =>
    children(node).find((child) => child.name === "ArgList");
  const expressions = (node: PythonNode) =>
    children(node).filter((child) =>
      !/[()[\],]/.test(child.name) && child.name !== "AssignOp"
    );
  const value = (
    node: PythonNode | undefined,
    seen = new Set<string>(),
    at = node,
  ): string | string[] | undefined => {
    if (!node) return;
    if (
      node.name === "VariableName" && bindings.has(text(node)) &&
      !seen.has(text(node))
    ) {
      const binding = at ? bound(text(node), at) : undefined;
      return value(
        binding?.value,
        new Set([...seen, text(node)]),
        binding?.statement,
      );
    }
    if (node.name === "String") {
      const raw = text(node),
        match = /^([rRuUbB]*)(["']{1,3})([\s\S]*)\2$/.exec(raw);
      if (!match) return;
      return /r/i.test(match[1])
        ? match[3]
        : match[3].replace(/\\([\\'"nt])/g, (_, c) =>
          c === "n" ? "\n" : c === "t" ? "\t" : c);
    }
    if (["ArrayExpression", "TupleExpression"].includes(node.name)) {
      return expressions(node).flatMap((child) => {
        const v = value(child, seen, at);
        return child.name === "StarredExpression" && Array.isArray(v)
          ? v
          : [typeof v === "string" ? v : "<dynamic>"];
      });
    }
    if (node.name === "BinaryExpression") {
      const parts = children(node),
        left = value(parts[0], seen, at),
        right = value(parts.at(-1), seen, at);
      if (Array.isArray(left) && Array.isArray(right)) {
        return [...left, ...right];
      }
      if (typeof left === "string" || typeof right === "string") {
        return (left ?? "<dynamic>") + String(right ?? "<dynamic>");
      }
    }
    if (
      node.name === "CallExpression" && node.firstChild &&
      name(node.firstChild) === "shlex.quote"
    ) {
      const args = argumentsOf(node),
        v = args ? value(expressions(args)[0], seen, at) : undefined;
      if (typeof v === "string") return quoteShellWord(v);
    }
  };
  const findings: Finding[] = [];
  visit(root, (node) => {
    if (node.name !== "CallExpression" || !node.firstChild) return;
    let called = name(node.firstChild);
    if (bindings.has(called)) {
      const binding = bound(called, node);
      if (binding?.value) called = name(binding.value);
    }
    if (
      !/^(?:subprocess\.(?:run|Popen|call|check_call|check_output)|os\.(?:system|execv|execve|popen))$/
        .test(called)
    ) return;
    const args = argumentsOf(node),
      argv = args ? value(expressions(args)[0]) : undefined;
    if (typeof argv === "string") {
      findings.push(
        ...agentShellFindings(file, argv, inventory, depth + 1, true),
      );
    } else if (Array.isArray(argv)) {
      findings.push(
        ...agentShellFindings(
          file,
          argv.map(quoteShellWord).join(" "),
          inventory,
          depth + 1,
          true,
        ),
      );
    } else if (/\b(?:orbit-agent|sleep|flock|lockfile)\b/.test(source)) {
      findings.push({
        file,
        rule: /\borbit-agent\b/.test(source)
          ? "agent-dynamic"
          : "unknown-command",
        count: 1,
        line: 1,
        evidence: [],
      });
    }
  });
  return findings;
}

// Parse executable positions, not service names, artifact arguments or heredoc data.
export function agentShellFindings(
  file: string,
  source: string,
  inventory: Record<string, string> = {},
  depth = 0,
  controlCommands = false,
): Finding[] {
  if (depth > 8) {
    return [{
      file,
      rule: "agent-dynamic",
      count: 1,
      line: 1,
      evidence: [],
    }];
  }
  const visible: string[] = [],
    nested: string[] = [],
    heredocFindings: Finding[] = [];
  const documents: { tag: string; language: string; body: string[] }[] = [];
  for (const line of source.split("\n")) {
    if (documents.length) {
      const doc = documents[0];
      if (line.trim() === doc.tag) {
        if (doc.language === "python") {
          heredocFindings.push(
            ...agentPythonFindings(
              file,
              doc.body.join("\n"),
              inventory,
              depth + 1,
            ),
          );
        }
        if (doc.language === "shell") nested.push(doc.body.join("\n"));
        documents.shift();
      } else doc.body.push(line);
      continue;
    }
    visible.push(line);
    for (const match of line.matchAll(/<<-?\s*['"]?([A-Za-z_]\w*)['"]?/g)) {
      documents.push({
        tag: match[1],
        language: /\bpython[0-9.]*\b/.test(line)
          ? "python"
          : /\b(?:bash|sh|dash|zsh)\b/.test(line)
          ? "shell"
          : "data",
        body: [],
      });
    }
  }
  const text = visible.join("\n"), commands: string[][] = [];
  let words: string[] = [], word = "", quote = "";
  const finish = () => {
    if (word) words.push(word);
    word = "";
  };
  const command = () => {
    finish();
    if (words.length) commands.push(words);
    words = [];
  };
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (ch === "\\" && quote !== "'") {
      if (text[i + 1] !== "\n") word += text[++i] ?? "";
      else i++;
      continue;
    }
    if (quote !== "'" && ch === "`") {
      let end = i + 1;
      while (
        end < text.length && (text[end] !== "`" || text[end - 1] === "\\")
      ) end++;
      nested.push(text.slice(i + 1, end));
      word += "<substitution>";
      i = end;
      continue;
    }
    if (quote !== "'" && ch === "$" && text[i + 1] === "(") {
      let end = i + 2, level = 1;
      while (end < text.length && level) {
        if (text[end] === "(") level++;
        if (text[end] === ")") level--;
        end++;
      }
      nested.push(text.slice(i + 2, end - 1));
      word += "<substitution>";
      i = end - 1;
      continue;
    }
    if (quote) {
      if (ch === quote) quote = "";
      else word += ch;
      continue;
    }
    if (ch === "'" || ch === '"') {
      quote = ch;
      continue;
    }
    if (ch === "#" && !word) {
      while (i < text.length && text[i] !== "\n") i++;
      command();
      continue;
    }
    if ("\n;&|()".includes(ch)) {
      command();
      continue;
    }
    if (/\s/.test(ch)) finish();
    else word += ch;
  }
  command();
  const shellBindings = new Map<string, string>();
  for (const argv of commands) {
    for (const arg of argv[0] === "alias" ? argv.slice(1) : argv) {
      const match = /^([A-Za-z_]\w*)=(.*)$/.exec(arg);
      if (!match) break;
      shellBindings.set(
        match[1],
        shellBindings.has(match[1]) ? "<dynamic>" : match[2],
      );
    }
  }
  const findings: Finding[] = [
    ...heredocFindings,
    ...nested.flatMap((body) =>
      agentShellFindings(file, body, inventory, depth + 1, controlCommands)
    ),
  ];
  const executable = (argv: string[], level: number): void => {
    if (!argv.length || level > 8) return;
    while (
      argv.length &&
      (/^[A-Za-z_]\w*=/.test(argv[0]) ||
        ["exec", "command", "then", "do", "if", "!", "{", "time"]
          .includes(argv[0]))
    ) argv = argv.slice(1);
    if (!argv.length) return;
    const variable = /^\$\{?([A-Za-z_]\w*)\}?$/.exec(argv[0]);
    if (variable || shellBindings.has(argv[0])) {
      const key = variable?.[1] ?? argv[0];
      const resolved = shellBindings.get(key);
      if (!resolved || resolved === "<dynamic>") {
        if (/\borbit-agent\b/.test(source)) {
          findings.push({
            file,
            rule: "agent-dynamic",
            count: 1,
            line: 1,
            evidence: [],
          });
        }
        return;
      }
      argv = [resolved, ...argv.slice(1)];
    }
    const binary = argv[0]?.split("/").at(-1);
    if (
      controlCommands &&
      ["sleep", "usleep", "flock", "lockfile"].includes(binary ?? "")
    ) {
      findings.push({
        file,
        rule: "custom-control",
        count: 1,
        line: 1,
        evidence: [binary!],
      });
    }
    if (binary === "eval") {
      const body = argv.slice(1).map((arg) =>
        arg.replace(
          /^\$\{?([A-Za-z_]\w*)\}?$/,
          (_, key) => shellBindings.get(key) ?? "<dynamic>",
        )
      ).join(" ");
      findings.push(
        ...agentShellFindings(
          file,
          body,
          inventory,
          depth + 1,
          controlCommands,
        ),
      );
      return;
    }
    if (["sudo", "env"].includes(binary ?? "")) {
      let i = 1;
      while (
        i < argv.length &&
        (argv[i].startsWith("-") || /^[A-Za-z_]\w*=/.test(argv[i]))
      ) {
        const option = argv[i++];
        if (binary === "env" && ["-S", "--split-string"].includes(option)) {
          findings.push(
            ...agentShellFindings(
              file,
              argv.slice(i).join(" "),
              inventory,
              depth + 1,
              controlCommands,
            ),
          );
          return;
        }
        if (
          (binary === "sudo"
            ? ["-u", "-g", "-h", "-p", "-C", "--user", "--group", "--chdir"]
            : ["-u", "--unset", "-C", "--chdir"]).includes(option)
        ) i++;
      }
      executable(argv.slice(i), level + 1);
      return;
    }
    if (["sh", "bash", "dash", "zsh"].includes(binary ?? "")) {
      const i = argv.findIndex((arg) => /^-[a-z]*c[a-z]*$/.test(arg));
      if (i >= 0 && argv[i + 1]) {
        findings.push(
          ...agentShellFindings(
            file,
            argv[i + 1],
            inventory,
            depth + 1,
            controlCommands,
          ),
        );
      }
      return;
    }
    if (binary === "ssh") {
      let i = 1;
      while (i < argv.length && argv[i].startsWith("-")) {
        const option = argv[i++];
        if (
          ["-i", "-p", "-o", "-F", "-l", "-J", "-S", "-W"].includes(
            option,
          )
        ) i++;
      }
      if (argv[i] === "--") i++;
      findings.push(
        ...agentShellFindings(
          file,
          argv.slice(i + 1).join(" "),
          inventory,
          depth + 1,
          controlCommands,
        ),
      );
      return;
    }
    if (/^python[0-9.]*$/.test(binary ?? "")) {
      const i = argv.indexOf("-c");
      if (i >= 0 && argv[i + 1]) {
        findings.push(
          ...agentPythonFindings(file, argv[i + 1], inventory, depth + 1),
        );
      }
      return;
    }
    if (binary !== "orbit-agent") return;
    if (["--version", "--help", "-V", "-h"].includes(argv[1])) return;
    const target = argv[1] === "command" ? argv[2] : undefined;
    if (target && inventory[target] === "observer") return;
    findings.push({
      file,
      rule: target && inventory[target] === "action"
        ? "agent-action"
        : "agent-dynamic",
      count: 1,
      line: 1,
      evidence: target && inventory[target] === "action" ? [target] : [],
    });
  };
  for (const argv of commands) executable(argv, 0);
  return findings;
}

export function analyzeTs(
  file: string,
  source: string,
  inventory: Record<string, string>,
  agentWrappers = new Set<string>(),
): Analysis {
  const result = empty();
  const root = ts.createSourceFile(
    file,
    source,
    ts.ScriptTarget.Latest,
    true,
  );
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
    "localCommand",
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
      line: root.getLineAndCharacterOfPosition(node.getStart(root)).line +
        1,
      evidence,
    });
  };
  const visit = (node: ts.Node, fn: (node: ts.Node) => void) => {
    fn(node);
    ts.forEachChild(node, (child) => visit(child, fn));
  };
  visit(root, (node) => {
    if (
      (ts.isVariableDeclaration(node) || ts.isParameter(node)) &&
      ts.isIdentifier(node.name) &&
      node.initializer
    ) bindings.set(node.name.text, node.initializer);
    if (
      ts.isImportDeclaration(node) &&
      ts.isStringLiteral(node.moduleSpecifier)
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
      ts.isPropertyAssignment(node) &&
      node.name.getText(root) === "type" &&
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
    if (
      ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)
    ) {
      return node.text;
    }
    if (ts.isTemplateExpression(node)) {
      return node.head.text +
        node.templateSpans.map((s) =>
          (value(s.expression, seen) ?? "<dynamic>") + s.literal.text
        ).join("");
    }
    if (ts.isTaggedTemplateExpression(node)) {
      return value(node.template, seen);
    }
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
      if (ts.isIdentifier(array)) {
        array = bindings.get(array.text) ?? array;
      }
      if (ts.isArrayLiteralExpression(array)) {
        const parts = array.elements.map((e) => value(e, seen));
        if (parts.every((x) => x !== undefined)) {
          return parts.join(value(node.arguments[0], seen) ?? ",");
        }
      }
    }
  };
  // Endpoint exceptions apply to concrete calls, never to an entire client or file.
  const gatewayValue = (
    node: ts.Node | undefined,
    seen = new Set<string>(),
  ): string | undefined => {
    if (!node) return;
    if (
      ts.isIdentifier(node) && bindings.has(node.text) &&
      !seen.has(node.text)
    ) {
      return gatewayValue(
        bindings.get(node.text),
        new Set([...seen, node.text]),
      );
    }
    if (
      /^(?:[\w]+\.)*(?:gatewayUrl|gateway_url)$/.test(node.getText(root))
    ) {
      return "<gateway-origin>";
    }
    if (
      /^(?:[\w]+\.)*(?:operation_id|operationId)$/.test(
        node.getText(root),
      )
    ) {
      return "{operation_id}";
    }
    if (ts.isTemplateExpression(node)) {
      const spans = node.templateSpans.map((span) =>
        gatewayValue(span.expression, seen)
      );
      if (spans.some((span) => span === undefined)) return;
      return node.head.text +
        node.templateSpans.map((span, i) => spans[i] + span.literal.text).join(
          "",
        );
    }
    if (
      ts.isBinaryExpression(node) &&
      node.operatorToken.kind === ts.SyntaxKind.PlusToken
    ) {
      const left = gatewayValue(node.left, seen),
        right = gatewayValue(node.right, seen);
      return left === undefined || right === undefined
        ? undefined
        : left + right;
    }
    if (ts.isNewExpression(node) && name(node.expression) === "URL") {
      return gatewayValue(node.arguments?.[0], seen);
    }
    return value(node, seen);
  };
  const legacyGatewayMarker =
    /\bGatewayOperation\b|makeHttp|gatewayHttp|gatewayRequest|directOperation|withPrivateMaterial|\/api\/v1\/operations/
      .test(source);
  const gatewayMarker =
    /\bGatewayOperation\b|GatewayClient|makeHttp|gatewayHttp|gatewayRequest|directOperation|withPrivateMaterial|gatewayUrl|gateway_url|gateway\.orbit|\/api\/v1\//
      .test(source);
  const gatewayCalls: { node: ts.Node; method?: string; path?: string }[] = [];
  visit(root, (node) => {
    if (!ts.isCallExpression(node)) return;
    let target: ts.Expression = node.expression;
    const seen = new Set<string>();
    while (
      ts.isIdentifier(target) && bindings.has(target.text) &&
      !seen.has(target.text)
    ) {
      seen.add(target.text);
      target = bindings.get(target.text)!;
    }
    while (
      ts.isParenthesizedExpression(target) || ts.isAsExpression(target) ||
      ts.isNonNullExpression(target)
    ) target = target.expression;
    const called = ts.isElementAccessExpression(target)
        ? (value(target.argumentExpression) ?? "")
        : name(target),
      args = [...node.arguments];
    const property = (
      node: ts.Node | undefined,
      key: string,
    ): ts.Expression | undefined => {
      if (node && ts.isIdentifier(node)) node = bindings.get(node.text);
      if (
        !node || !ts.isObjectLiteralExpression(node) ||
        node.properties.some((p) => ts.isSpreadAssignment(p))
      ) return;
      const matches = node.properties.filter((p) =>
        ts.isPropertyAssignment(p) &&
        p.name.getText(root).replace(/^['"]|['"]$/g, "") === key
      );
      const entry = matches.length === 1 ? matches[0] : undefined;
      return entry && ts.isPropertyAssignment(entry)
        ? entry.initializer
        : undefined;
    };
    let method: string | undefined,
      path: string | undefined,
      request = false;
    if (called === "fetch") {
      request = true;
      path = gatewayValue(args[0]);
      method = args[1] ? value(property(args[1], "method")) : "GET";
    } else if (
      /^(?:http|gatewayHttp|gatewayRequest|proxyGatewayRequest)$/.test(
        called ?? "",
      )
    ) {
      request = true;
      method = value(args[0]);
      path = gatewayValue(args[1]);
    } else if (
      ts.isPropertyAccessExpression(target) &&
      /^(?:get|post|put|patch|delete|call|request|read|result|failure|fail|primitive|report|withPrivateMaterial)$/
        .test(called) &&
      /http|gateway|client|^g$/i.test(target.expression.getText(root))
    ) {
      request = true;
      if (["call", "request"].includes(called)) {
        method = value(args[0]);
        path = gatewayValue(args[1]);
      } else if (/^(?:get|post|put|patch|delete)$/.test(called)) {
        method = called.toUpperCase();
        path = gatewayValue(args[0]);
      }
    }
    const knownExternal = path && /^https?:\/\//.test(path) &&
      !/gateway|\/api\/v1\//i.test(path);
    if (
      request && !knownExternal &&
      (gatewayMarker || /\/api\/v1\//.test(path ?? ""))
    ) {
      gatewayCalls.push({ node, method, path });
    }
  });
  const forbiddenGateway = gatewayCalls.filter((call) =>
    !isGatewayPrimitive(call.method, call.path)
  );
  if (
    file.startsWith("extensions/behaviors/") &&
    (forbiddenGateway.length ||
      /\bGatewayOperation\b|GatewayClient|directOperation|withPrivateMaterial/
        .test(source) ||
      (legacyGatewayMarker ||
          gatewayMarker &&
            /\bfetch\b|\/api\/v1\/|gateway\.orbit/.test(source)) &&
        !gatewayCalls.length)
  ) {
    const denied = forbiddenGateway.map((call) =>
      `${call.method ?? "<unknown>"} ${call.path ?? "<unknown>"}`
    );
    result.findings.push({
      file,
      rule: "gateway-http",
      count: Math.max(1, forbiddenGateway.length),
      line: 1,
      evidence: [...new Set(denied)].sort(),
    });
  }
  const strings = (node: ts.Node): string[] => {
    const out: string[] = [],
      pending = [node],
      visited = new Set<ts.Node>();
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
      ts.isStringLiteral(node) ||
      ts.isNoSubstitutionTemplateLiteral(node) ||
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
      (ts.isStringLiteral(node) ||
        ts.isNoSubstitutionTemplateLiteral(node)) &&
      /vault\.get\s*\(|private-material/.test(node.text)
    ) return true;
    let found = false;
    ts.forEachChild(node, (child) => {
      if (tainted(child, seen)) found = true;
    });
    return found;
  };
  const agentExpression = (
    expression: ts.Expression,
    seen = new Set<ts.Node>(),
  ): boolean => {
    if (seen.has(expression)) return false;
    seen.add(expression);
    if (ts.isIdentifier(expression)) {
      return agentNames.has(name(expression)) ||
        (bindings.has(expression.text) &&
          agentExpression(bindings.get(expression.text)!, seen));
    }
    if (
      ts.isPropertyAccessExpression(expression) &&
      ["call", "apply", "bind"].includes(expression.name.text)
    ) return agentExpression(expression.expression, seen);
    if (ts.isElementAccessExpression(expression)) {
      let array: ts.Expression = expression.expression;
      if (ts.isIdentifier(array)) {
        array = bindings.get(array.text) ?? array;
      }
      return ts.isArrayLiteralExpression(array) &&
        array.elements.some((item) =>
          ts.isExpression(item) && agentExpression(item, seen)
        );
    }
    if (ts.isCallExpression(expression)) {
      return agentExpression(expression.expression, seen);
    }
    if (ts.isConditionalExpression(expression)) {
      return agentExpression(expression.whenTrue, seen) ||
        agentExpression(expression.whenFalse, seen);
    }
    return false;
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
            ts.isCallExpression(item) &&
            agentNames.has(name(item.expression))
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
          !(ts.isExpression(node.parent) &&
            value(node.parent)?.includes(text))
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
    const called = name(node.expression),
      args = [...(node.arguments ?? [])];
    if (
      /^(?:setTimeout|setInterval|sleep|delay|backoff|retry|poll|acquireLock|flock)$/i
        .test(called)
    ) {
      add(node, "custom-control", 1, [called]);
      result.loops++;
    }
    if (
      /withPrivateMaterial|privateMaterial|readPrivateMaterial/.test(
        called,
      )
    ) add(node, "private-material");
    if (
      /writeResource|writeFile|appendFile|log|info|error|debug/.test(
        called,
      ) &&
      args.some((arg) => tainted(arg))
    ) add(node, "secret-output");
    const texts = args.flatMap((arg) => strings(arg));
    if (texts.some((t) => t.includes("/private-material"))) {
      add(node, "private-material");
    }
    const sink = sinks.has(called) ||
      /(?:Command|spawn|execFile|execSync)$/.test(called);
    const literalAgentCalls: Finding[] = [];
    if (sink && args[0]) {
      let command = value(args[0]);
      let complete: string[] | undefined;
      if (ts.isArrayLiteralExpression(args[0])) {
        complete = args[0].elements.map((item) => value(item) ?? "<dynamic>");
      }
      if (ts.isObjectLiteralExpression(args[0])) {
        const commandField = args[0].properties.find((p) =>
          ts.isPropertyAssignment(p) &&
          p.name.getText(root) === "command"
        );
        if (commandField && ts.isPropertyAssignment(commandField)) {
          command = value(commandField.initializer);
        }
      }
      let argv: ts.Node | undefined = args[1];
      if (argv && ts.isObjectLiteralExpression(argv)) {
        argv = argv.properties.find((p) =>
          ts.isPropertyAssignment(p) &&
          p.name.getText(root) === "args"
        );
        if (argv && ts.isPropertyAssignment(argv)) {
          argv = argv.initializer;
        }
      }
      if (argv && ts.isIdentifier(argv)) argv = bindings.get(argv.text);
      if (complete) command = complete[0];
      const tail = complete
        ? complete.slice(1)
        : argv && ts.isArrayLiteralExpression(argv)
        ? argv.elements.map((item) => value(item) ?? "<dynamic>")
        : [];
      if (command) {
        literalAgentCalls.push(...agentShellFindings(
          file,
          tail.length
            ? [command, ...tail].map((part) => quoteShellWord(part))
              .join(" ")
            : command,
          inventory,
        ));
      }
    }
    if (sink && !literalAgentCalls.length) {
      for (const arg of args) {
        visit(arg, (child) => {
          if (!ts.isArrayLiteralExpression(child)) return;
          const argv = child.elements.map((item) => value(item) ?? "<dynamic>");
          const index = argv.findIndex((item) =>
            item.split("/").at(-1) === "orbit-agent"
          );
          if (index >= 0 && argv[index + 1] === "command") {
            literalAgentCalls.push(
              ...agentShellFindings(
                file,
                argv.slice(index).map((part) => quoteShellWord(part)).join(" "),
                inventory,
              ),
            );
          }
        });
      }
    }
    const isAgent = agentExpression(node.expression) ||
      agentNames.has(called);
    result.findings.push(...literalAgentCalls);
    result.agentActions += literalAgentCalls.filter((f) =>
      f.rule === "agent-action"
    ).length;
    result.unknown ||= literalAgentCalls.some((f) =>
      f.rule === "agent-dynamic" || f.rule.startsWith("unknown-")
    );
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
              /^(?:args|argv|run|command)$/.test(
                prop.name.getText(root),
              ) &&
              tainted(prop.initializer)
            ) add(prop, "secret-argv");
          }
        } else if (tainted(arg)) add(arg, "secret-argv");
      }
      if (texts.some((t) => /^-(?:c|Command)$/.test(t))) {
        for (const arg of args) {
          for (const text of strings(arg)) {
            if (
              lines(text) > 1 &&
              (python.test(text) || shell.test(text))
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

    if (/set\s+-(?:\w*x\w*|o\s+xtrace)\b/.test(text)) {
      add(root, "secret-xtrace");
    }
    if (
      /(?:subprocess\.(?:run|Popen|call)|os\.system)[^\n]*(?:password|secret|private_key|token)|\b(?:curl|exec|sudo|python\w*)[^\n]*\$\{?\w*(?:SECRET|TOKEN|PASSWORD|IDENTITY)/i
        .test(text)
    ) add(root, "secret-argv");
  }
  for (const text of scriptLeaves.values()) {
    result.findings.push(...gatewayShellFindings(file, text));
    const controlSites = text.match(
      /\b(?:while\s+(?:True|true|:)|time\.sleep\s*\(|sleep\s+\d|flock\s*\(|LOCK_EX|O_EXCL|lockfile|for\s+\w*(?:attempt|retry)\w*\s+in)/g,
    ) ?? [];
    if (controlSites.length) {
      add(root, "custom-control", controlSites.length, [
        "embedded-control",
      ]);
      result.loops += controlSites.length;
    }
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
    Object.keys(object(schema.patternProperties)).length > 0
  ) return false;
  if (!keys.length) {
    return (schema.properties === undefined ||
      schema.properties !== null && typeof schema.properties === "object" &&
        !Array.isArray(schema.properties)) &&
      (schema.required === undefined ||
        Array.isArray(schema.required) && !schema.required.length);
  }
  const required = schema.required;
  if (
    !Array.isArray(required) || keys.some((key) => !required.includes(key))
  ) {
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

function pythonHeredocs(source: string) {
  return [...source.matchAll(
    /(?:python[\d.]*[^\n]*<<[\t ]*['"]?(\w+)['"]?[^\n]*\n)([\s\S]*?)\n\1(?:\n|$)/g,
  )];
}

export function analyzeYaml(
  file: string,
  source: string,
  inventory: Record<string, string> = {},
): Analysis {
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
  // The binding guide approves one visible Route private-DNS TTL grace.
  // This is an exact source contract, never a file/rule grandfather cap.
  const routeDnsGrace = (path: string[]) => {
    if (
      file !== "workflows/workflow-route-dns-grace.yaml" ||
      path.join(".") !== "jobs.0.steps.0.task.inputs.run" ||
      !Array.isArray(data.jobs) || data.jobs.length !== 1
    ) return false;
    const job = object(data.jobs[0]);
    if (
      job.name !== "grace" || !Array.isArray(job.steps) ||
      job.steps.length !== 1
    ) return false;
    const step = object(job.steps[0]);
    const labels = object(step.labels), task = object(step.task);
    const inputs = object(task.inputs);
    return step.name === "wait-private-dns-ttl" &&
      step.target === "orbit-node-209" &&
      Object.keys(labels).join() === "node" && labels.node === "209" &&
      Object.keys(task).sort().join() ===
        "behaviorName,behaviorType,inputs,methodName,type" &&
      task.type === "behavior" && task.behaviorType === "command/shell" &&
      task.behaviorName === "route-dns-grace" &&
      task.methodName === "execute" &&
      Object.keys(inputs).join() === "run" &&
      inputs.run === "/usr/bin/sleep 31";
  };
  const officialSshTask = (path: string[]) => {
    if (
      !file.startsWith("workflows/") || path[0] !== "jobs" ||
      !/^\d+$/.test(path[1] ?? "") || path[2] !== "steps" ||
      !/^\d+$/.test(path[3] ?? "") || path[4] !== "task"
    ) return undefined;
    const jobs = Array.isArray(data.jobs) ? data.jobs : [];
    const steps = object(jobs[Number(path[1])]).steps;
    const step = Array.isArray(steps) ? steps[Number(path[3])] : undefined;
    const task = object(object(step).task);
    return task.type === "behavior" && task.behaviorType === "@swamp/ssh" ||
        task.type === "model_method" && task.modelType === "@swamp/ssh"
      ? task
      : undefined;
  };
  // Official @swamp/ssh 2026.10.23.1 consumes these transport fields natively.
  const sshIdentity = (path: string[]) => {
    let field: string[];
    if (
      file.startsWith("models/") && data.type === "@swamp/ssh" &&
      path[0] === "globalArguments"
    ) {
      field = path.slice(1);
    } else if (path[5] === "globalArgs" && officialSshTask(path)) {
      field = path.slice(6);
    } else return false;
    return field.join(".") === "transport.identityContent" ||
      field.length === 4 && field[0] === "hosts" && /^\d+$/.test(field[1]) &&
        field[2] === "transport" && field[3] === "identityContent";
  };
  const wholeVault = (value: unknown) => {
    if (typeof value !== "string") return false;
    const call = /^\s*\$\{\{\s*vault\.get\(([\s\S]*)\)\s*\}\}\s*$/.exec(value);
    if (!call) return false;
    let quote = "", escaped = false, depth = 0;
    for (const char of call[1]) {
      if (quote) {
        if (escaped) escaped = false;
        else if (char === "\\") escaped = true;
        else if (char === quote) quote = "";
      } else if (char === "'" || char === '"') quote = char;
      else if (char === "(") depth++;
      else if (char === ")" && --depth < 0) return false;
    }
    return !quote && depth === 0;
  };
  const walk = (value: unknown, path: string[]) => {
    const nativeIdentity = sshIdentity(path);
    const nativeStdin = path.length === 7 && path[5] === "inputs" &&
      path[6] === "stdin" && officialSshTask(path)?.methodName === "exec";
    if (nativeIdentity && !wholeVault(value)) add("secret-native-source");
    if (typeof value === "string") {
      const envValue = path.length >= 4 && path.at(-2) === "env" &&
        path.at(-3) === "inputs" && path.at(-4) === "task";
      if (
        envValue && secret(path.at(-1) ?? "") &&
        !/^\s*\$\{\{\s*vault\.get\([\s\S]*\)\s*\}\}\s*$/.test(value)
      ) add("secret-env-source");
      if (
        /vault\.get\s*\(/.test(value) &&
        !((nativeIdentity || nativeStdin) && wholeVault(value)) && !(envValue &&
          /^\s*\$\{\{\s*vault\.get\([\s\S]*\)\s*\}\}\s*$/.test(
            value,
          ) &&
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
      if (/set\s+-(?:\w*x\w*|o\s+xtrace)\b/.test(value)) {
        add("secret-xtrace");
      }
      if (
        (path.at(-1) === "run" || path.includes("argv") ||
          path.includes("args")) &&
        /\$\{?\w*(?:SECRET|TOKEN|PASSWORD|PRIVATE_KEY|IDENTITY)|vault\.get\s*\(/i
          .test(value)
      ) add("secret-argv");
      if (path.at(-1) === "run") {
        result.findings.push(...gatewayShellFindings(file, value));
        const scripts = value.match(
          /(?:^|[\s"'])scripts\/[A-Za-z0-9_./-]+\.(?:py|sh)\b/g,
        ) ??
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
        // The Python AST helper owns controls inside these same extracted bodies.
        // Do not count a tool transaction lock again as a shell token.
        let shellControls = value;
        for (const block of pythonHeredocs(value).reverse()) {
          const start = block.index! + block[0].indexOf("\n") + 1;
          shellControls = shellControls.slice(0, start) +
            block[2].replace(/[^\n]/g, " ") +
            shellControls.slice(start + block[2].length);
        }
        const controlsFound = routeDnsGrace(path) ? [] : shellControls.match(
          /\b(?:sleep|flock|lockfile)\b|while\s+(?:true|:)|for\s+\w*(?:attempt|retry)\w*/g,
        ) ?? [];
        for (const _ of controlsFound) {
          add("custom-control");
          result.loops++;
        }
        const agentFindings = agentShellFindings(
          file,
          value,
          inventory,
        );
        result.findings.push(...agentFindings);
        result.agentActions += agentFindings.filter((f) =>
          f.rule === "agent-action"
        ).length;
        result.unknown ||= agentFindings.some((f) =>
          f.rule === "agent-dynamic" || f.rule.startsWith("unknown-")
        );
      }
      return;
    }
    if (Array.isArray(value)) {
      value.forEach((item, i) => walk(item, [...path, String(i)]));
      return;
    }
    for (const [key, item] of Object.entries(object(value))) {
      if (
        path[0] === "inputs" && path.at(-1) === "properties" &&
        secret(key) &&
        // Official cfgmgmt uses this string as a privateKeyPath locator, not key bytes.
        !(key === "nodeIdentityFile" && object(item).type === "string") &&
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
  if (file.startsWith("models/")) {
    result.modelNames = [data.id, data.name].filter((
      value,
    ): value is string => typeof value === "string" && value.length > 0);
    if (
      typeof data.type === "string" && data.type.startsWith("@") &&
      !/\$\{\{|\}\}/.test(data.type)
    ) {
      result.types.push(data.type);
    } else if (data.type !== "command/shell") {
      add("unknown-behavior");
      result.unknown = true;
    }
  }
  if (file.startsWith("workflows/")) {
    result.workflowNames = [data.id, data.name].filter((
      value,
    ): value is string => typeof value === "string" && value.length > 0);
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
          ![
            "behavior",
            "model_method",
            "workflow",
            "manual_approval",
            "assert",
          ]
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
        if (
          task.type === "model_method" && !task.modelType &&
          typeof task.modelIdOrName === "string" &&
          task.modelIdOrName.length > 0 &&
          !/\$\{\{|\}\}/.test(task.modelIdOrName)
        ) {
          result.modelRefs.push(task.modelIdOrName);
        } else if (typeof type === "string" && type.startsWith("@")) {
          result.types.push(type);
        } else if (
          ["behavior", "model_method"].includes(String(task.type)) &&
          type !== "command/shell"
        ) {
          add("unknown-behavior");
          result.unknown = true;
        }
        if (task.type === "workflow") {
          if (
            typeof task.workflowIdOrName === "string" &&
            task.workflowIdOrName.length > 0 &&
            !/\$\{\{|\}\}/.test(task.workflowIdOrName)
          ) {
            result.workflowRefs.push(task.workflowIdOrName);
          } else {
            add("unknown-nested-workflow");
            result.unknown = true;
          }
        }
      }
    }
  }
  return result;
}

export function analyzeScript(
  file: string,
  source: string,
  inventory: Record<string, string> = {},
): Analysis {
  const result = empty();
  if (file.endsWith(".py")) {
    const findings = agentPythonFindings(file, source, inventory);
    result.findings.push(...findings);
    result.unknown ||= findings.some((f) =>
      f.rule === "agent-dynamic" || f.rule.startsWith("unknown-")
    );
    return result;
  } // Python is parsed by the AST helper, never regexed as executable text.
  const add = (rule: string, count = 1) =>
    result.findings.push({ file, rule, count, line: 1, evidence: [] });
  result.findings.push(...gatewayShellFindings(file, source));
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
  const agentFindings = agentShellFindings(file, source, inventory);
  result.findings.push(...agentFindings);
  result.agentActions +=
    agentFindings.filter((f) => f.rule === "agent-action").length;
  result.unknown ||= agentFindings.some((f) =>
    f.rule === "agent-dynamic" || f.rule.startsWith("unknown-")
  );
  return result;
}

export function aggregate(findings: Finding[]): Entry[] {
  const rows = new Map<string, Entry>();
  for (const f of findings) {
    const key = `${f.file}\0${f.rule}`;
    const entry = rows.get(key) ??
      { file: f.file, rule: f.rule, count: 0, evidence: [] };
    entry.count += f.count;
    entry.evidence = [...new Set([...entry.evidence, ...f.evidence])]
      .sort();
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
      ) {
        errors.push(
          `Baseline may only shrink: ${entry.file} ${entry.rule}`,
        );
      }
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
// Set only after the existing ancestry/repository-id relocation checks succeed.
const preservedRootRefs = new Set<string>();
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
    preservedRootRefs.add(ref);
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
async function toolFindings(
  sources: Map<string, string>,
): Promise<Finding[]> {
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
            const shell = item.replace(
              /\$\{\{[\s\S]*?\}\}/g,
              "shape_value",
            );
            files.push([file, shell, "sh"]);
            for (const block of pythonHeredocs(shell)) {
              files.push([file, block[2], "py"]);
            }
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
        const python = await run(
          [
            "python3",
            pythonAnalyzer,
            JSON.stringify(GATEWAY_PRIMITIVES),
            "--source-context",
          ],
          JSON.stringify({
            source,
            sources: Object.fromEntries(
              [...sources].filter(([path]) =>
                path.endsWith(".py") &&
                posix.dirname(path) === posix.dirname(file)
              ),
            ),
          }),
        );
        if (!python.success) {
          throw new Error("Python source analysis failed");
        }
        for (const item of JSON.parse(python.text)) {
          findings.push({
            file,
            rule: item.rule,
            count: item.count,
            line: item.line,
            evidence: item.evidence ?? [],
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
    const ast = ts.createSourceFile(
      file,
      source,
      ts.ScriptTarget.Latest,
      true,
    );
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
            ts.isCallExpression(child) &&
            ts.isIdentifier(child.expression) &&
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
          ? analyzeYaml(file, source, inventory)
          : analyzeScript(file, source, inventory),
      );
    }
  }
  const workflows = new Map<string, string[]>();
  const models = new Map<string, string[]>();
  for (const [file, a] of analyses) {
    for (const name of a.workflowNames) {
      workflows.set(name, [...new Set([...(workflows.get(name) ?? []), file])]);
    }
    for (const name of a.modelNames) {
      models.set(name, [...new Set([...(models.get(name) ?? []), file])]);
    }
  }
  for (const [file, a] of analyses) {
    for (const reference of a.modelRefs) {
      const matches = models.get(reference) ?? [];
      if (matches.length === 1) a.imports.push(matches[0]);
      else {
        a.unknown = true;
        a.findings.push({
          file,
          rule: "unknown-behavior",
          count: 1,
          line: 1,
          evidence: [reference],
        });
      }
    }
    for (const reference of a.workflowRefs) {
      const matches = workflows.get(reference) ?? [];
      if (matches.length === 1) a.imports.push(matches[0]);
      else {
        a.unknown = true;
        a.findings.push({
          file,
          rule: "unknown-nested-workflow",
          count: 1,
          line: 1,
          evidence: [reference],
        });
      }
    }
  }
  for (const [file, a] of analyses) {
    for (const dependency of a.imports) {
      if (
        /\.(?:ts|py|sh)$/.test(dependency) && !analyses.has(dependency)
      ) {
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
  for (const [file, a] of analyses) {
    if (
      !file.startsWith("extensions/behaviors/") || !a.types.length ||
      a.findings.some((f) => f.rule === "gateway-http")
    ) continue;
    const pending = [...a.imports], seen = new Set<string>();
    let reachable = false;
    while (pending.length) {
      const dependency = pending.pop()!;
      if (seen.has(dependency)) continue;
      seen.add(dependency);
      const child = analyses.get(dependency);
      if (!child) continue;
      if (child.findings.some((f) => f.rule === "gateway-http")) {
        reachable = true;
        break;
      }
      pending.push(...child.imports);
    }
    if (reachable) {
      a.findings.push({
        file,
        rule: "gateway-http",
        count: 1,
        line: 1,
        evidence: [],
      });
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
        for (const type of node.types) {
          const files = types.get(type);
          if (!files) uncertain = true;
          else queue.push(...files);
        }
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
    gitPrefix = (await run(["git", "rev-parse", "--show-prefix"])).text
      .trim();
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
      ) {
        throw new Error(
          "Agent inventory cannot expand observation permission",
        );
      }
    }
    const analyses = analyze(head, inventory);
    const external = await toolFindings(head);
    for (const f of external) {
      const analysis = analyses.get(f.file);
      analysis?.findings.push(f);
      if (
        analysis &&
        (f.rule === "agent-dynamic" || f.rule.startsWith("unknown-"))
      ) analysis.unknown = true;
    }
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
          [...baseAnalyses.values()].flatMap((a) => a.findings)
            .concat(
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
        count: Math.min(
          e.count,
          caps.get(`${e.file}\0${e.rule}`)?.count ?? 0,
        ),
        evidence: e.evidence.filter((x) =>
          caps.get(`${e.file}\0${e.rule}`)?.evidence.includes(x)
        ),
      })).filter((e) => e.count > 0);
      await Deno.writeTextFile(
        BASELINE,
        JSON.stringify({ schema: SCHEMA, entries: reduced }, null, 2) +
          "\n",
      );
      head.set(
        BASELINE,
        JSON.stringify({ schema: SCHEMA, entries: reduced }),
      );
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
    const moves: SourceMove[] = [];
    if (head.has(PROXY_HTTP_MOVE.to)) {
      const originInHead = await run([
        "git",
        "merge-base",
        "--is-ancestor",
        PROXY_HTTP_MOVE.origin,
        "HEAD",
      ]);
      const originInBase = await run([
        "git",
        "merge-base",
        "--is-ancestor",
        PROXY_HTTP_MOVE.origin,
        baseRef,
      ]);
      if (!originInHead.success) {
        throw new Error("HTTP source move lacks immutable origin ancestry");
      }
      if (!originInBase.success) {
        // The first import compares the actual preserved, unprefixed source,
        // which predates the approved origin checkpoint and has no gate/ledger.
        // Keep that comparison source and its scanned caps. The origin is only
        // an immutable code witness, never a substitute comparison base.
        const baseInOrigin = await run([
          "git",
          "merge-base",
          "--is-ancestor",
          baseRef,
          PROXY_HTTP_MOVE.origin,
        ]);
        const checkerAtBase = await run([
          "git",
          "cat-file",
          "-e",
          `${baseRef}:bin/check-swamp-shape.ts`,
        ]);
        if (
          !preservedRootRefs.has(baseRef) || base.has(BASELINE) ||
          checkerAtBase.success || !baseInOrigin.success
        ) {
          throw new Error("HTTP source move lacks immutable origin ancestry");
        }
      }
      const origin = await filesAt(PROXY_HTTP_MOVE.origin);
      const move = proveProxyHttpMove(
        origin,
        base,
        head,
        JSON.parse(origin.get(BASELINE) ?? "null"),
        prior,
        baseEntries,
        entries,
      );
      if (move) moves.push(move);
    }
    const errors = [
      // The checked-in list still only shrinks. A proved move derives its cap
      // separately and never writes a row at the new path.
      ...ratchet([], baseline, prior),
      ...ratchet(entries, movedLimits(baseline, moves)),
      ...ratchet(
        entries,
        movedLimits({ schema: SCHEMA, entries: baseEntries }, moves),
      ),
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
        gateway_client_files: "files_with_gateway_http_findings",
        agent_action_calls: "syntactic_call_sites",
        agent_unknown_calls: "syntactic_call_sites",
        workflows: "workflow_files",
        grandfathered: "file_rule_entries",
        baseline_entries: "checked_in_file_rule_entries",
        relocated_allowances: "derived_file_rule_entries",
      },
      inline_script_lines: values.reduce((n, a) => n + a.inlineLines, 0),
      custom_loops: entries.filter((e) => e.rule === "custom-control")
        .reduce(
          (n, e) => n + e.count,
          0,
        ),
      gateway_client_files: new Set(
        entries.filter((e) => e.rule === "gateway-http").map((e) => e.file),
      ).size,
      agent_action_calls: entries.filter((e) => e.rule === "agent-action")
        .reduce((n, e) => n + e.count, 0),
      agent_unknown_calls: entries.filter((e) => e.rule === "agent-dynamic")
        .reduce((n, e) => n + e.count, 0),
      workflows: coverage,
      grandfathered: entries.length,
      baseline_entries: baseline.entries.length,
      relocated_allowances: moves,
      violations: entries,
      errors,
    };
    console.log(JSON.stringify(metrics));
    console.log(
      `SHAPE-GATE workflows_passing=${coverage.clean}/${coverage.total} grandfathered=${entries.length} embedded_lines=${metrics.inline_script_lines} custom_loops=${metrics.custom_loops} gateway_client_files=${metrics.gateway_client_files}`,
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

import ts from "npm:typescript@5.9.3";
import { parse } from "jsr:@std/yaml@1.0.12";
import { posix } from "node:path";

export const SCHEMA = 1;
export const BASELINE = "config/swamp-shape-baseline.json";
export const INVENTORY = "config/agent-command-kinds.json";
const secretName = /(?:secret|password|passwd|credential|private.?key|api.?key|access.?token|auth.?token|identity)/i;
const referenceName = /(?:key|id|ref|name|hash|digest|public|count|field)s?$/i;
const secret = (name: string) => secretName.test(name) && !referenceName.test(name);
const object = (v: unknown): Record<string, unknown> => v && typeof v === "object" && !Array.isArray(v) ? v as Record<string, unknown> : {};
export type Finding = { file: string; rule: string; count: number; line: number; evidence: string[] };
export type Entry = { file: string; rule: string; count: number; evidence: string[] };
export type Baseline = { schema: number; entries: Entry[] };
type Analysis = { findings: Finding[]; imports: string[]; types: string[]; inlineLines: number; loops: number; agentActions: number; unknown: boolean };
const empty = (): Analysis => ({ findings: [], imports: [], types: [], inlineLines: 0, loops: 0, agentActions: 0, unknown: false });
export const production = (file: string) => !/(?:^|\/)(?:tests?|fixtures?)(?:\/|$)|(?:_test|\.test|_fixture|native_fixture)\./.test(file);
export const scope = (file: string) => production(file) && (/^extensions\/.*\.[cm]?tsx?$/.test(file) || /^(?:workflows|models)\/.*\.ya?ml$/.test(file) || /\.(?:py|sh)$/.test(file) && !file.startsWith("bin/check-swamp-shape"));
const controls = /\b(?:sleep|usleep|setTimeout|setInterval|delay|backoff|retry|poll|flock|lockf|Mutex|Semaphore|acquireLock)\b/i;
const python = /(?:^|\n)\s*(?:from\s+\w+\s+import|import\s+(?:os|sys|subprocess|json)|def\s+\w+\(|class\s+\w+[:(])|subprocess\.(?:run|Popen|call)/;
const shell = /#!.*\b(?:ba|da|z|k)?sh\b|(?:^|\n)\s*(?:set\s+-|sudo\s|systemctl\s|apt-get\s|export\s+[A-Z_]+=)|\b(?:then|fi|esac)\s*(?:\n|$)/;
const lines = (s: string) => s.trim() ? s.trim().split(/\r?\n/).length : 0;

export function analyzeTs(file: string, source: string, inventory: Record<string, string>, agentWrappers = new Set<string>()): Analysis {
  const result = empty();
  const root = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true);
  const bindings = new Map<string, ts.Expression>();
  const aliases = new Map<string, string>();
  const scripts = new Map<number, string>();
  const commands = new Set<string>();
  const sinks = new Set(["run", "invoke", "command", "system", "output", "runLocalProgram", "localSystemCommand", "runNodeCommand", "exec", "execFile", "spawn", "spawnSync", "execSync", "Command"]);
  const agentNames = new Set(["runNodeAgent", "agentLaunch", "localAgent", "runAgent", ...agentWrappers]);
  const add = (node: ts.Node, rule: string, count = 1, evidence: string[] = []) => {
    result.findings.push({ file, rule, count, line: root.getLineAndCharacterOfPosition(node.getStart(root)).line + 1, evidence });
  };
  const visit = (node: ts.Node, fn: (node: ts.Node) => void) => { fn(node); ts.forEachChild(node, child => visit(child, fn)); };
  visit(root, node => {
    if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) && node.initializer) bindings.set(node.name.text, node.initializer);
    if (ts.isImportDeclaration(node) && ts.isStringLiteral(node.moduleSpecifier)) {
      const module = node.moduleSpecifier.text;
      if (module.startsWith(".")) result.imports.push(posix.normalize(posix.join(posix.dirname(file), module)));
      const named = node.importClause?.namedBindings;
      if (named && ts.isNamedImports(named)) for (const spec of named.elements) {
        aliases.set(spec.name.text, spec.propertyName?.text ?? spec.name.text);
        if (/node-agent|agent-command|agent_runner/.test(module)) agentNames.add(spec.name.text);
      }
    }
    if (ts.isPropertyAssignment(node) && node.name.getText(root) === "type" && ts.isStringLiteral(node.initializer) && node.initializer.text.startsWith("@")) result.types.push(node.initializer.text);
  });
  const name = (node: ts.Expression): string => ts.isIdentifier(node) ? aliases.get(node.text) ?? node.text : ts.isPropertyAccessExpression(node) ? node.name.text : "";
  const value = (node: ts.Node | undefined, seen = new Set<string>()): string | undefined => {
    if (!node) return;
    if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) return node.text;
    if (ts.isTemplateExpression(node)) return node.head.text + node.templateSpans.map(s => (value(s.expression, seen) ?? "<dynamic>") + s.literal.text).join("");
    if (ts.isTaggedTemplateExpression(node)) return value(node.template, seen);
    if (ts.isParenthesizedExpression(node) || ts.isAsExpression(node) || ts.isNonNullExpression(node)) return value(node.expression, seen);
    if (ts.isIdentifier(node) && !seen.has(node.text)) { const next = new Set(seen); next.add(node.text); return value(bindings.get(node.text), next); }
    if (ts.isBinaryExpression(node) && node.operatorToken.kind === ts.SyntaxKind.PlusToken) {
      const a = value(node.left, seen), b = value(node.right, seen); return a === undefined || b === undefined ? undefined : a + b;
    }
    if (ts.isCallExpression(node) && ts.isPropertyAccessExpression(node.expression) && node.expression.name.text === "join" && ts.isArrayLiteralExpression(node.expression.expression)) {
      const parts = node.expression.expression.elements.map(e => value(e, seen));
      if (parts.every(x => x !== undefined)) return parts.join(value(node.arguments[0], seen) ?? ",");
    }
  };
  const strings = (node: ts.Node, seen = new Set<string>()): string[] => {
    const literal = value(node, seen);
    if (literal !== undefined) return [literal];
    if (ts.isIdentifier(node) && !seen.has(node.text) && bindings.has(node.text)) { const next = new Set(seen); next.add(node.text); return strings(bindings.get(node.text)!, next); }
    const out: string[] = []; ts.forEachChild(node, child => { out.push(...strings(child, seen)); }); return out;
  };
  const tainted = (node: ts.Node) => {
    let found = false;
    visit(node, item => { if (ts.isIdentifier(item) && secret(item.text)) found = true; if ((ts.isStringLiteral(item) || ts.isNoSubstitutionTemplateLiteral(item)) && /vault\.get\s*\(|private-material/.test(item.text)) found = true; });
    return found;
  };
  // Resolve const aliases and local wrapper functions, not just imported spellings.
  for (let pass = 0; pass < 4; pass++) {
    for (const [id, expression] of bindings) if (agentNames.has(name(expression)) || ts.isIdentifier(expression) && agentNames.has(expression.text)) agentNames.add(id);
    visit(root, node => {
      if (ts.isFunctionDeclaration(node) && node.name && node.body) {
        let callsAgent = false;
        visit(node.body, item => { if (ts.isCallExpression(item) && agentNames.has(name(item.expression))) callsAgent = true; });
        if (callsAgent) agentNames.add(node.name.text);
      }
    });
  }
  visit(root, node => {
    if (ts.isExpression(node)) {
      const text = value(node);
      if (text !== undefined && (python.test(text) || shell.test(text))) {
        // Count maximal constructed strings once; identifiers merely reference the same program.
        if (!ts.isIdentifier(node) && !(ts.isExpression(node.parent) && value(node.parent)?.includes(text))) scripts.set(node.getStart(root), text);
      }
    }
    if (ts.isWhileStatement(node) || ts.isDoStatement(node) || ts.isForStatement(node) || ts.isForOfStatement(node)) {
      const text = node.getText(root);
      if (/while\s*\(\s*(?:true|1)\s*\)|for\s*\(\s*;\s*;/.test(text) || controls.test(text) || /\b(?:attempt|deadline|retries|poll)\b/i.test(text)) { add(node, "custom-control"); result.loops++; }
    }
    if (!(ts.isCallExpression(node) || ts.isNewExpression(node))) return;
    const called = name(node.expression), args = [...(node.arguments ?? [])];
    if (/^(?:setTimeout|setInterval|sleep|delay|backoff|retry|poll|acquireLock|flock)$/i.test(called)) { add(node, "custom-control", 1, [called]); result.loops++; }
    if (/withPrivateMaterial|privateMaterial|readPrivateMaterial/.test(called)) add(node, "private-material");
    if (/writeResource|writeFile|appendFile|log|info|error|debug/.test(called) && args.some(tainted)) add(node, "secret-output");
    const texts = args.flatMap(arg => strings(arg));
    if (texts.some(t => t.includes("/private-material"))) add(node, "private-material");
    const isAgent = agentNames.has(called) || texts.some(t => /(?:^|\/)orbit-agent(?:$|\s)/.test(t));
    if (isAgent) {
      const targets = [...new Set(texts.filter(t => t in inventory))];
      const actions = targets.filter(t => inventory[t] !== "observer");
      if (actions.length) { add(node, "agent-action", 1, actions.sort()); result.agentActions++; }
      if (!targets.length) { add(node, "agent-dynamic"); result.unknown = true; }
    }
    const sink = sinks.has(called) || /(?:Command|spawn|execFile|execSync)$/.test(called);
    if (sink) {
      const binaries = texts.filter(t => /^\/?(?:[\w.-]+\/)*(?:sudo|python[\d.]*|sh|bash|systemctl|git|curl|apt-get|docker|incus|flock|sleep|orbit-agent|[a-z][\w.-]*)$/.test(t) && (t.startsWith("/") || /^(?:sudo|python[\d.]*|sh|bash|systemctl|git|curl|docker|incus|flock|sleep)$/.test(t)));
      for (const binary of binaries) commands.add(binary);
      if (texts.some(t => /\b(?:flock|lockfile|\.lock\b|LOCK_EX|O_EXCL)\b/.test(t))) { add(node, "custom-control", 1, ["lock"]); result.loops++; }
      for (const arg of args) {
        // Environment, stdin and protected file content are not argv. Inspect only argv/run/command arguments.
        if (ts.isObjectLiteralExpression(arg)) {
          for (const prop of arg.properties) if (ts.isPropertyAssignment(prop) && /^(?:args|argv|run|command)$/.test(prop.name.getText(root)) && tainted(prop.initializer)) add(prop, "secret-argv");
        } else if (tainted(arg)) add(arg, "secret-argv");
      }
      if (texts.some(t => /^-(?:c|Command)$/.test(t))) for (const arg of args) for (const text of strings(arg)) if (lines(text) > 1 && (python.test(text) || shell.test(text))) scripts.set(arg.getStart(root), text);
    }
  });
  // Deduplicate programs referenced by a wrapper and declared as a constant.
  const uniqueScripts = [...new Set(scripts.values())];
  result.inlineLines = uniqueScripts.reduce((sum, text) => sum + lines(text), 0);
  if (result.inlineLines > 20) add(root, "inline-script", result.inlineLines);
  for (const text of uniqueScripts) {
    const subprocesses = [...text.matchAll(/(?:subprocess\.(?:run|call|Popen|check_output|check_call)|os\.system)\s*\(\s*(?:\[\s*)?[rbuf]*['"]([^'"\n]+)/g)];
    for (const match of subprocesses) commands.add(match[1]);
    const loops = text.match(/\b(?:while\s+(?:True|true|:)|time\.sleep\s*\(|sleep\s+\d|flock\s*\(|LOCK_EX|O_EXCL|lockfile|for\s+\w*(?:attempt|retry)\w*\s+in)\b/g) ?? [];
    if (loops.length) { add(root, "custom-control", loops.length, ["embedded-control"]); result.loops += loops.length; }
    if (/set\s+-(?:\w*x\w*|o\s+xtrace)\b/.test(text)) add(root, "secret-xtrace");
    if (/(?:subprocess\.(?:run|Popen|call)|os\.system)[^\n]*(?:password|secret|private_key|token)|\b(?:curl|exec|sudo|python\w*)[^\n]*\$\{?\w*(?:SECRET|TOKEN|PASSWORD|IDENTITY)/i.test(text)) add(root, "secret-argv");
  }
  if (commands.size > 1) add(root, "multi-action", commands.size, [...commands].sort());
  return result;
}

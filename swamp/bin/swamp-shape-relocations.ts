/** Source-proven accounting for one approved extraction; no general rename rule. */
import ts from "npm:typescript@5.9.3";
import { posix } from "node:path";
import type { Baseline, Entry } from "./check-swamp-shape.ts";

export const PROXY_HTTP_MOVE = {
  origin: "529a72e86d2757cd8f90fedb564e4c69d1879b6d",
  from: "extensions/behaviors/operations/_lib/node-proxy.ts",
  to: "extensions/behaviors/operations/_lib/node-proxy-http.ts",
  caller: "extensions/behaviors/automatic-deploy/gateway.ts",
  rule: "gateway-http",
  declarations: ["curlQuote", "proxyHttpConfig", "proxyGatewayRequest"],
} as const;

type Sources = Map<string, string>;
export type SourceMove = {
  origin: string;
  from: string;
  to: string;
  rule: string;
  count: number;
  evidence: string[];
};
const parse = (file: string, source: string) =>
  ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true);
const name = (node: ts.Statement): string | undefined => {
  if (ts.isFunctionDeclaration(node)) return node.name?.text;
  if (
    ts.isVariableStatement(node) &&
    node.declarationList.declarations.length === 1
  ) {
    const declared = node.declarationList.declarations[0].name;
    if (ts.isIdentifier(declared)) return declared.text;
  }
};
const declarations = (source: string) => {
  const nodes = parse(PROXY_HTTP_MOVE.from, source).statements.filter((node) =>
    PROXY_HTTP_MOVE.declarations.some((identifier) => identifier === name(node))
  );
  return nodes.length === PROXY_HTTP_MOVE.declarations.length &&
      nodes.every((node, i) => name(node) === PROXY_HTTP_MOVE.declarations[i])
    ? nodes.map((node) => node.getText())
    : undefined;
};
const equal = (left: unknown, right: unknown) =>
  JSON.stringify(left) === JSON.stringify(right);
const resolves = (file: string, path: string) =>
  path.startsWith(".")
    ? posix.normalize(posix.join(posix.dirname(file), path))
    : undefined;

/** Every import and every use of the moved export must be the existing call. */
function callerUses(sources: Sources, moved: boolean): string[] | undefined {
  const uses: string[] = [];
  let imports = 0;
  for (const [file, source] of sources) {
    if (!/\.[cm]?tsx?$/.test(file)) continue;
    const root = parse(file, source);
    let invalid = false;
    let localName: string | undefined;
    // `require` can be a locally declared assertion, not a CommonJS loader.
    // Its body is still traversed, so dynamic import remains unprovable.
    const localRequire = root.statements.some((statement) =>
      ts.isFunctionDeclaration(statement) &&
        statement.name?.text === "require" ||
      ts.isVariableStatement(statement) &&
        statement.declarationList.declarations.some((declaration) =>
          ts.isIdentifier(declaration.name) &&
          declaration.name.text === "require" && declaration.initializer &&
          (ts.isArrowFunction(declaration.initializer) ||
            ts.isFunctionExpression(declaration.initializer))
        )
    );
    const walk = (node: ts.Node) => {
      if (
        ts.isImportDeclaration(node) &&
        ts.isStringLiteral(node.moduleSpecifier) &&
        ["node:module", "module"].includes(node.moduleSpecifier.text)
      ) invalid = true;
      if (
        (ts.isCallExpression(node) || ts.isNewExpression(node)) &&
        ts.isIdentifier(node.expression) &&
        ["eval", "Function"].includes(node.expression.text)
      ) invalid = true;
      if (
        ts.isCallExpression(node) &&
        (node.expression.kind === ts.SyntaxKind.ImportKeyword ||
          !localRequire && ts.isIdentifier(node.expression) &&
            node.expression.text === "require")
      ) {
        if (
          node.arguments.length !== 1 ||
          !ts.isStringLiteralLike(node.arguments[0])
        ) invalid = true;
        else if (
          resolves(file, node.arguments[0].text) === PROXY_HTTP_MOVE.from ||
          ["node:module", "module"].includes(node.arguments[0].text)
        ) invalid = true;
      }
      // Namespace imports, re-exports, require and dynamic imports cannot hide a
      // new caller of the dedicated HTTP module, even via an alias.
      if (
        ts.isStringLiteralLike(node) &&
        resolves(file, node.text) === PROXY_HTTP_MOVE.to
      ) {
        const owner = node.parent;
        if (
          !(file === PROXY_HTTP_MOVE.caller && ts.isImportDeclaration(owner))
        ) invalid = true;
      }
      if (
        ts.isImportDeclaration(node) && ts.isStringLiteral(node.moduleSpecifier)
      ) {
        const target = resolves(file, node.moduleSpecifier.text);
        const binds = node.importClause?.namedBindings;
        if (target === (moved ? PROXY_HTTP_MOVE.to : PROXY_HTTP_MOVE.from)) {
          if (
            target === PROXY_HTTP_MOVE.to &&
            (!binds || !ts.isNamedImports(binds))
          ) invalid = true;
          if (binds && ts.isNamedImports(binds)) {
            for (const specifier of binds.elements) {
              const imported = specifier.propertyName?.text ??
                specifier.name.text;
              if (
                !PROXY_HTTP_MOVE.declarations.some((identifier) =>
                  identifier === imported
                )
              ) continue;
              if (
                file !== PROXY_HTTP_MOVE.caller ||
                imported !== "proxyGatewayRequest" ||
                specifier.name.text !== imported || specifier.isTypeOnly ||
                node.importClause?.isTypeOnly || localName
              ) {
                invalid = true;
              }
              localName = specifier.name.text;
              imports++;
            }
            if (
              target === PROXY_HTTP_MOVE.to &&
              (binds.elements.length !== 1 || node.importClause?.name)
            ) invalid = true;
          }
        }
      }
      ts.forEachChild(node, walk);
    };
    walk(root);
    if (invalid) return undefined;
    if (localName) {
      const references = (node: ts.Node) => {
        if (ts.isIdentifier(node) && node.text === localName) {
          if (ts.isImportSpecifier(node.parent)) return;
          if (
            ts.isCallExpression(node.parent) && node.parent.expression === node
          ) {
            let enclosing: ts.Node = node.parent;
            while (enclosing.parent && !ts.isFunctionDeclaration(enclosing)) {
              enclosing = enclosing.parent;
            }
            if (
              !ts.isFunctionDeclaration(enclosing) ||
              enclosing.name?.text !== "gatewayHttp"
            ) invalid = true;
            else uses.push(enclosing.getText() + "\n" + node.parent.getText());
          } else invalid = true;
        }
        ts.forEachChild(node, references);
      };
      references(root);
      if (invalid) return undefined;
    }
  }
  return imports === 1 && uses.length > 0 ? uses : undefined;
}

function layout(sources: Sources, original: string[], moved: boolean): boolean {
  const source = sources.get(PROXY_HTTP_MOVE.from);
  if (source === undefined) return false;
  if (!moved) {
    return !sources.has(PROXY_HTTP_MOVE.to) &&
      equal(declarations(source), original);
  }
  const target = sources.get(PROXY_HTTP_MOVE.to);
  if (target === undefined) return false;
  // The old declarations must be gone. Ratchet separately requires zero HTTP
  // findings there, preventing a retained/renamed copy from doubling the debt.
  if (
    parse(PROXY_HTTP_MOVE.from, source).statements.some((node) =>
      PROXY_HTTP_MOVE.declarations.some((identifier) =>
        identifier === name(node)
      )
    )
  ) return false;
  const root = parse(PROXY_HTTP_MOVE.to, target);
  const imports = root.statements.filter(ts.isImportDeclaration).map((node) =>
    node.getText()
  );
  const bodies = root.statements.filter((node) => !ts.isImportDeclaration(node))
    .map((node) => node.getText());
  return equal(bodies, original) && equal(imports, [
    'import { commandOutput } from "./node-command-process.ts";',
    'import { type NodeProxy, proxyLaunch } from "./node-proxy.ts";',
  ]);
}

const row = (entries: Entry[], file: string) =>
  entries.find((entry) =>
    entry.file === file && entry.rule === PROXY_HTTP_MOVE.rule
  );
const exact = (entry: Entry | undefined) =>
  entry?.count === 1 && equal(entry.evidence, []);

/** The caller supplies immutable origin source and verifies Git ancestry. */
export function proveProxyHttpMove(
  origin: Sources,
  base: Sources,
  head: Sources,
  originBaseline: Baseline,
  prior: Baseline,
  baseEntries: Entry[],
  headEntries: Entry[],
): SourceMove | undefined {
  if (
    !head.has(PROXY_HTTP_MOVE.to) || origin.has(PROXY_HTTP_MOVE.to) ||
    !exact(row(originBaseline.entries, PROXY_HTTP_MOVE.from))
  ) return;
  const original = declarations(origin.get(PROXY_HTTP_MOVE.from) ?? "");
  const originalUses = callerUses(origin, false);
  if (
    !original || !originalUses || !layout(head, original, true) ||
    !equal(callerUses(head, true), originalUses)
  ) return;
  const alreadyMoved = base.has(PROXY_HTTP_MOVE.to);
  if (
    !layout(base, original, alreadyMoved) ||
    !equal(callerUses(base, alreadyMoved), originalUses)
  ) return;
  // Physical debt must still exist in the actual comparison base. An origin
  // commit cannot resurrect an allowance after the live debt has disappeared.
  const previousSite = alreadyMoved ? PROXY_HTTP_MOVE.to : PROXY_HTTP_MOVE.from;
  const otherSite = alreadyMoved ? PROXY_HTTP_MOVE.from : PROXY_HTTP_MOVE.to;
  if (
    !exact(row(baseEntries, previousSite)) || row(baseEntries, otherSite) ||
    !alreadyMoved && !exact(row(prior.entries, PROXY_HTTP_MOVE.from)) ||
    !exact(row(headEntries, PROXY_HTTP_MOVE.to)) ||
    row(headEntries, PROXY_HTTP_MOVE.from)
  ) return;
  return {
    origin: PROXY_HTTP_MOVE.origin,
    from: PROXY_HTTP_MOVE.from,
    to: PROXY_HTTP_MOVE.to,
    rule: PROXY_HTTP_MOVE.rule,
    count: 1,
    evidence: [],
  };
}

/** Virtual limits are derived per run; they are never written to the ledger. */
export function movedLimits(baseline: Baseline, moves: SourceMove[]): Baseline {
  return {
    ...baseline,
    entries: [
      ...baseline.entries.filter((entry) =>
        !moves.some((move) =>
          entry.rule === move.rule &&
          (entry.file === move.from || entry.file === move.to)
        )
      ),
      ...moves.map((move) => ({
        file: move.to,
        rule: move.rule,
        count: move.count,
        evidence: move.evidence,
      })),
    ],
  };
}

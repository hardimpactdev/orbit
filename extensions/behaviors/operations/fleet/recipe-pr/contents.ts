import { z } from "npm:zod@4.4.3";
import {
  getNodeValue,
  type Node,
  type ParseError,
  parseTree,
} from "npm:jsonc-parser@3.3.1";
import { requirePolicy } from "./policy.ts";

const Tool = z.object({
  source: z.enum(["ubuntu", "artifact", "internal"]),
  version: z.string().min(1),
}).passthrough();
const Recipe = z.object({
  schema_version: z.literal(1),
  agent: z.record(z.string(), z.string()).nullable(),
  ubuntu: z.object({ release: z.literal("26.04"), snapshot: z.string() })
    .strict(),
  architectures: z.tuple([z.literal("amd64")]),
  tools: z.record(z.string(), Tool),
  common: z.array(z.string()),
  base: z.array(z.string()),
  roles: z.record(z.string(), z.array(z.string())),
  php: z.object({
    shared: z.array(z.string()),
    versions: z.record(
      z.string(),
      z.object({ packages: z.array(z.string()) }).passthrough(),
    ),
  }).passthrough(),
  artifact_mirror: z.url(),
  git: z.object({ packages: z.array(z.string()), usn_since: z.string() })
    .strict(),
}).strict();
export type RecipeData = z.infer<typeof Recipe>;
function uniqueKeys(node: Node) {
  if (node.type === "object") {
    const seen = new Set<string>();
    for (const property of node.children ?? []) {
      const key = property.children![0].value as string;
      requirePolicy(!seen.has(key));
      seen.add(key);
    }
  }
  for (const child of node.children ?? []) uniqueKeys(child);
}
export function parseRecipe(content: string): RecipeData {
  requirePolicy(new TextEncoder().encode(content).length <= 2 * 1024 * 1024);
  const errors: ParseError[] = [];
  const tree = parseTree(content, errors, {
    disallowComments: true,
    allowTrailingComma: false,
  });
  requirePolicy(tree && errors.length === 0);
  uniqueKeys(tree);
  const recipe = Recipe.parse(getNodeValue(tree));
  snapshot(recipe.ubuntu.snapshot);
  return recipe;
}
export function snapshot(value: string) {
  const match = /^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/.exec(value);
  requirePolicy(match);
  const date = new Date(
    Date.UTC(
      +match[1],
      +match[2] - 1,
      +match[3],
      +match[4],
      +match[5],
      +match[6],
    ),
  );
  requirePolicy(
    date.toISOString().replace(/[-:]/g, "").replace(".000", "") === value,
  );
  return value;
}
export function same(left: unknown, right: unknown): boolean {
  const sort = (value: unknown): unknown =>
    Array.isArray(value)
      ? value.map(sort)
      : value && typeof value === "object"
      ? Object.fromEntries(
        Object.entries(value).sort(([a], [b]) => a.localeCompare(b)).map((
          [key, val],
        ) => [key, sort(val)]),
      )
      : value;
  return JSON.stringify(sort(left)) === JSON.stringify(sort(right));
}
export function isDeployed(content: string, deployed: string) {
  const main = parseRecipe(content), serving = parseRecipe(deployed);
  main.ubuntu.snapshot = serving.ubuntu.snapshot;
  return same(main, serving);
}
export function ubuntuPackages(recipe: RecipeData) {
  return Object.entries(recipe.tools).filter(([, tool]) =>
    tool.source === "ubuntu"
  ).map(([name]) => name);
}
export function rewriteSnapshot(
  content: string,
  next: string,
  versions: Record<string, string>,
) {
  const recipe = parseRecipe(content), old = recipe.ubuntu.snapshot;
  snapshot(next);
  let replacements = 0;
  let updated = content.replace(
    /("snapshot"\s*:\s*")[^"]+("\s*)/g,
    (match, first, last) => {
      replacements++;
      requirePolicy(match.includes(old));
      return first + next + last;
    },
  );
  requirePolicy(replacements === 1);
  const names = ubuntuPackages(recipe);
  requirePolicy(same(Object.keys(versions).sort(), names.sort()));
  recipe.ubuntu.snapshot = next;
  for (const [name, version] of Object.entries(versions)) {
    requirePolicy(
      /^[a-z0-9][a-z0-9+.-]*$/.test(name) &&
        !/["\\\p{Cc}]/u.test(version) && version.length > 0,
    );
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const pattern = new RegExp(
      '("' + escaped + '"\\s*:\\s*\\{[^{}]*?"version"\\s*:\\s*")[^"]+("\\s*)',
      "g",
    );
    replacements = 0;
    updated = updated.replace(pattern, (_match, first, last) => {
      replacements++;
      return first + version + last;
    });
    requirePolicy(replacements === 1);
    recipe.tools[name].version = version;
  }
  requirePolicy(same(parseRecipe(updated), recipe));
  return updated;
}
export function artifactNames(recipe: RecipeData) {
  return [
    ...new Set([
      "incus",
      "incus-base",
      "incus-client",
      ...recipe.php.shared,
      ...Object.values(recipe.php.versions).flatMap((version) =>
        version.packages
      ),
    ]),
  ].sort();
}
export function artifactPins(content: string) {
  const recipe = parseRecipe(content);
  const raw = z.record(z.string(), z.unknown()).parse(JSON.parse(content));
  const tools = z.record(z.string(), z.unknown()).parse(raw.tools);
  return Object.fromEntries(
    artifactNames(recipe).map((name) => {
      requirePolicy(recipe.tools[name]);
      return [name, tools[name]];
    }),
  );
}
export async function artifactFingerprint(content: string) {
  const bytes = new TextEncoder().encode(JSON.stringify(artifactPins(content)));
  return Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("").slice(0, 12);
}
export function validateArtifactChange(before: string, after: string) {
  const old = parseRecipe(before), next = parseRecipe(after);
  const names = artifactNames(old);
  for (const name of names) {
    const pin = old.tools[name], candidate = next.tools[name];
    requirePolicy(
      candidate && candidate.source === pin.source &&
        same(Object.keys(candidate), Object.keys(pin)),
    );
    next.tools[name] = pin;
  }
  requirePolicy(
    same(old, next) && !same(artifactPins(before), artifactPins(after)),
  );
}

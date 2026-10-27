import { z } from "npm:zod@4";
import {
  actions,
  compareText,
  ConfigKeys,
  diffConfig,
  diffText,
  type Item,
  routes,
} from "./surface.ts";
import { cleanEnv } from "../../../_lib/maintenance/environment.ts";

export const TagSchema = z.string().regex(
  /^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/,
);
const SurfaceSchema = z.enum(["route", "controller", "screen", "config"]);
export const ManifestSchema = z.object({
  synced_tag: TagSchema,
  covered: z.array(
    z.strictObject({
      kind: SurfaceSchema,
      upstream: z.string().min(1),
      covered_by: z.string().min(1),
    }),
  ).optional(),
});
export const CandidateSchema = z.discriminatedUnion("kind", [
  z.strictObject({
    kind: z.literal("release"),
    tag: TagSchema,
    surface: z.null(),
    change: z.null(),
    id: TagSchema,
  }),
  z.strictObject({
    kind: z.literal("feature"),
    tag: TagSchema,
    surface: SurfaceSchema,
    change: z.enum(["add", "modify", "delete"]),
    id: z.string().min(1),
  }),
]);
export const DetectionSchema = z.strictObject({
  schema: z.literal(1),
  synced_tag: TagSchema,
  tag: TagSchema.nullable(),
  candidates: z.array(CandidateSchema),
});
export type Candidate = z.infer<typeof CandidateSchema>;
export type Detection = z.infer<typeof DetectionSchema>;
export type Manifest = z.infer<typeof ManifestSchema>;
export const UPSTREAM = "https://github.com/laravel/horizon.git";

/** Keep the script's ledger validation, including rejection of the old surface field. */
export function manifest(text: string): Manifest {
  let data;
  try {
    data = JSON.parse(text);
  } catch {
    throw new Error("Cannot read horizon-sync.json");
  }
  if (data === null || typeof data !== "object" || Array.isArray(data)) {
    throw new Error("horizon-sync.json synced_tag must be a stable vX.Y.Z tag");
  }
  if (Object.hasOwn(data, "surface")) {
    throw new Error(
      "horizon-sync.json lists covered features under covered, not surface",
    );
  }
  if (!TagSchema.safeParse(data.synced_tag).success) {
    throw new Error("horizon-sync.json synced_tag must be a stable vX.Y.Z tag");
  }
  const parsed = ManifestSchema.safeParse(data);
  if (!parsed.success) {
    throw new Error(
      "Each covered feature needs kind, upstream, and covered_by",
    );
  }
  return parsed.data;
}
export function stableTags(text: string): string[] {
  const tags = new Set<string>();
  for (const line of text.trim().split(/\r?\n/)) {
    const tag = (line.split("\t")[1] ?? "").replace(/\^\{\}$/, "").replace(
      /^refs\/tags\//,
      "",
    );
    if (TagSchema.safeParse(tag).success) tags.add(tag);
  }
  return [...tags].sort((a, b) => {
    // Arbitrary-size decimal components, like PHP version_compare.
    const av = a.slice(1).split("."), bv = b.slice(1).split(".");
    for (let i = 0; i < 3; i++) {
      const order = av[i].length - bv[i].length || compareText(av[i], bv[i]);
      if (order) return order;
    }
    return 0;
  });
}
export type Git = (
  args: string[],
) => Promise<{ code: number; text: string; error: string }>;
export function gitCommand(signal?: AbortSignal): Git {
  return async (args) => {
    const result = await new Deno.Command("git", {
      args,
      stdin: "null",
      stdout: "piped",
      stderr: "piped",
      signal,
      clearEnv: true,
      env: cleanEnv((k) => Deno.env.get(k), { GIT_TERMINAL_PROMPT: "0" }),
    }).output();
    return {
      code: result.code,
      text: new TextDecoder().decode(result.stdout),
      error: new TextDecoder().decode(result.stderr),
    };
  };
}
async function checked(
  git: Git,
  args: string[],
  label: string,
): Promise<string> {
  const result = await git(args);
  if (result.code !== 0) throw new Error(`${label}: ${result.error.trim()}`);
  return result.text;
}
async function show(
  git: Git,
  repo: string,
  tag: string,
  path: string,
): Promise<string> {
  const r = await git(["-C", repo, "show", `${tag}:${path}`]);
  if (!r.code) return r.text;
  if (
    r.code === 128 && /does not exist|exists on disk, but not/.test(r.error)
  ) return "";
  throw new Error(`Could not read ${tag}:${path}: ${r.error.trim()}`);
}
async function candidates(
  git: Git,
  repo: string,
  from: string,
  to: string,
): Promise<Item[]> {
  const text = await checked(git, [
    "-C",
    repo,
    "diff",
    "--name-status",
    "--no-renames",
    from,
    to,
    "--",
    "routes/web.php",
    "config/horizon.php",
    "src/Http/Controllers",
    "resources/js/screens",
  ], "Could not diff Horizon tags");
  const files = new Map<string, string>();
  for (const line of text.trim().split(/\r?\n/)) {
    if (!line) continue;
    const [status, path] = line.split("\t");
    if (!["A", "M", "D"].includes(status) || !path) {
      throw new Error(`Unsupported Horizon diff status: ${line}`);
    }
    files.set(path, status);
  }
  const items: Item[] = [];
  if (files.has("routes/web.php")) {
    for (
      const row of diffText(
        routes(await show(git, repo, from, "routes/web.php")),
        routes(await show(git, repo, to, "routes/web.php")),
      )
    ) items.push({ surface: "route", ...row });
  }
  for (
    const [path, status] of [...files].sort(([a], [b]) => compareText(a, b))
  ) {
    if (
      !path.startsWith("src/Http/Controllers/") || !path.endsWith(".php")
    ) continue;
    const before = status === "A"
      ? {}
      : actions(await show(git, repo, from, path), path);
    const after = status === "D"
      ? {}
      : actions(await show(git, repo, to, path), path);
    for (const row of diffText(before, after)) {
      items.push({
        surface: "controller",
        ...row,
      });
    }
  }
  for (const [path, status] of files) {
    if (path.startsWith("resources/js/screens/") && path.endsWith(".vue")) {
      items.push({
        surface: "screen",
        change: status === "A" ? "add" : status === "D" ? "delete" : "modify",
        id: path,
      });
    }
  }
  if (files.has("config/horizon.php")) {
    const before = new ConfigKeys(
      await show(git, repo, from, "config/horizon.php"),
    ).all();
    const after = new ConfigKeys(
      await show(git, repo, to, "config/horizon.php"),
    ).all();
    for (const row of diffConfig(before, after)) {
      items.push({ surface: "config", ...row });
    }
  }
  const order = { route: 0, controller: 1, screen: 2, config: 3 };
  return items.sort((a, b) =>
    order[a.surface] - order[b.surface] || compareText(a.id, b.id)
  );
}
/** Only the ledger and Git are read. Temporary fetched tags are deleted after detection. */
export async function detect(
  cwd: string,
  remote = UPSTREAM,
  git: Git = gitCommand(),
  ledger = "horizon-sync.json",
): Promise<Detection> {
  let text;
  try {
    text = await Deno.readTextFile(`${cwd}/${ledger}`);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) {
      throw new Error(
        "Missing horizon-sync.json. Record a baseline before detection.",
      );
    }
    throw new Error("Cannot read horizon-sync.json");
  }
  const synced = manifest(text).synced_tag;
  const tags = stableTags(
    await checked(
      git,
      ["ls-remote", "--tags", remote],
      "Could not list Horizon tags",
    ),
  );
  const index = tags.indexOf(synced);
  if (index === -1) {
    throw new Error(`Synced tag ${synced} is not a Horizon tag`);
  }
  const next = tags[index + 1] ?? null;
  if (!next) {
    return { schema: 1, synced_tag: synced, tag: null, candidates: [] };
  }
  let repo: string;
  let temporary = false;
  try {
    if (!(await Deno.stat(remote)).isDirectory) throw new Error();
    repo = remote;
  } catch {
    repo = await Deno.makeTempDir({ prefix: "horizon-sync-" });
    temporary = true;
  }
  try {
    if (temporary) {
      await checked(git, ["init", repo], "Could not prepare Horizon checkout");
      for (const tag of [synced, next]) {
        await checked(git, [
          "-C",
          repo,
          "fetch",
          "--depth",
          "1",
          remote,
          `refs/tags/${tag}:refs/tags/${tag}`,
        ], `Could not fetch Horizon tag ${tag}`);
      }
    }
    const items = await candidates(git, repo, synced, next);
    const rows: Candidate[] = items.length
      ? items.map((item) => ({ kind: "feature", tag: next, ...item }))
      : [{ kind: "release", tag: next, surface: null, change: null, id: next }];
    return { schema: 1, synced_tag: synced, tag: next, candidates: rows };
  } finally {
    if (temporary) await Deno.remove(repo, { recursive: true });
  }
}

import { z } from "npm:zod@4.4.3";

export const Repository = "hardimpactdev/orbit";
export const RecipePath = "apps/gateway/resources/machine-software/recipe.json";
export const Sha = z.string().regex(/^[a-f0-9]{40}$/);
export const Branch = z.string().regex(
  /^nightly\/(?:recipe-[0-9]{8}|artifacts-[a-f0-9]{12})$/,
);
export const Pull = z.object({
  number: z.number().int().positive(),
  html_url: z.url(),
  state: z.string(),
  draft: z.boolean(),
  user: z.object({ login: z.string(), type: z.string() }),
  commits: z.number().int(),
  changed_files: z.number().int(),
  base: z.object({
    ref: z.string(),
    repo: z.object({ full_name: z.string() }),
  }),
  head: z.object({
    ref: z.string(),
    sha: Sha,
    repo: z.object({ full_name: z.string() }).nullable(),
  }),
});
export type PullData = z.infer<typeof Pull>;
const Commit = z.object({
  sha: Sha,
  author: z.object({ login: z.string(), type: z.string() }).nullable(),
  committer: z.object({ login: z.string(), type: z.string() }).nullable(),
  commit: z.object({
    verification: z.object({ verified: z.boolean(), reason: z.string() }),
  }),
  parents: z.array(z.object({ sha: Sha })),
});
export const Check = z.object({
  name: z.string(),
  head_sha: Sha,
  status: z.string(),
  conclusion: z.string().nullable(),
  app: z.object({ slug: z.string() }),
});

export function requirePolicy(condition: unknown): asserts condition {
  if (!condition) throw new Error("fleet.recipe_pr_policy_refused");
}
export function nightlySnapshot(branch: string) {
  const match = /^nightly\/recipe-([0-9]{8})$/.exec(branch);
  return match ? match[1] + "T000000Z" : null;
}
export function currentDate(now = new Date()) {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/Amsterdam",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(now);
  return ["year", "month", "day"].map((key) =>
    parts.find((part) => part.type === key)!.value
  ).join("");
}
export function appPull(
  raw: unknown,
  head: string,
  branch: string,
  author: string,
): PullData | null {
  const parsed = Pull.safeParse(raw);
  if (!parsed.success) return null;
  const pull = parsed.data;
  return pull.state === "open" && pull.base.ref === "main" &&
      pull.base.repo.full_name === Repository &&
      pull.head.repo?.full_name === Repository && pull.head.ref === branch &&
      pull.head.sha === head &&
      pull.user.login === author && pull.user.type === "Bot"
    ? pull
    : null;
}
export function mergeMetadata(
  raw: unknown,
  head: string,
  branch: string,
  author: string,
) {
  const pull = appPull(raw, head, branch, author);
  requirePolicy(
    pull && !pull.draft && pull.commits === 1 && pull.changed_files === 1,
  );
  return pull;
}
export function appParent(raw: unknown, head: string, author: string) {
  const commits = z.array(Commit).parse(raw);
  requirePolicy(commits.length === 1);
  const commit = commits[0];
  requirePolicy(
    commit.sha === head && commit.author?.login === author &&
      commit.author.type === "Bot" &&
      commit.committer?.login === "web-flow" &&
      commit.committer.type === "User" &&
      commit.commit.verification.verified &&
      commit.commit.verification.reason === "valid" &&
      commit.parents.length === 1,
  );
  return commit.parents[0].sha;
}
export function checkState(raw: unknown, head: string, name: string) {
  const required = z.array(Check).parse(raw).filter((check) =>
    check.name === name
  );
  if (required.length === 0) return "missing";
  if (
    required.some((check) =>
      check.app.slug !== "github-actions" || check.head_sha !== head
    )
  ) return "failed";
  if (required.some((check) => check.status !== "completed")) return "pending";
  return required.every((check) => check.conclusion === "success")
    ? "passed"
    : "failed";
}
export function trustedFailedChecks(raw: unknown, head: string, name: string) {
  const runs = z.array(Check).parse(raw);
  return runs.length > 0 &&
    runs.every((check) =>
      check.app.slug === "github-actions" && check.head_sha === head
    ) && checkState(runs, head, name) === "failed";
}
export function recipeOnly(raw: unknown) {
  const files = z.array(
    z.object({
      filename: z.string(),
      status: z.string(),
      patch: z.string().optional(),
      additions: z.number().int(),
      deletions: z.number().int(),
      changes: z.number().int(),
    }),
  ).parse(raw);
  requirePolicy(
    files.length === 1 && files[0].filename === RecipePath &&
      files[0].status === "modified",
  );
  return files[0];
}
export function snapshotPatch(
  raw: unknown,
  before: string,
  after: string,
  expected: string,
) {
  const file = recipeOnly(raw);
  requirePolicy(file.patch !== undefined && after === expected);
  const removed: string[] = [],
    added: string[] = [],
    expectedRemoved: string[] = [],
    expectedAdded: string[] = [];
  for (const line of file.patch.split("\n")) {
    if (line.startsWith("-")) removed.push(line.slice(1));
    else if (line.startsWith("+")) added.push(line.slice(1));
  }
  const oldLines = before.split("\n"), newLines = expected.split("\n");
  requirePolicy(oldLines.length === newLines.length);
  for (let index = 0; index < oldLines.length; index++) {
    if (oldLines[index] !== newLines[index]) {
      expectedRemoved.push(oldLines[index]);
      expectedAdded.push(newLines[index]);
    }
  }
  requirePolicy(
    JSON.stringify(removed) === JSON.stringify(expectedRemoved) &&
      JSON.stringify(added) === JSON.stringify(expectedAdded) &&
      file.additions === added.length && file.deletions === removed.length &&
      file.changes === added.length + removed.length,
  );
}
export function untouched(raw: unknown, author: string, head: string) {
  const events = z.array(
    z.object({
      event: z.string(),
      actor: z.object({ login: z.string(), type: z.string() }).nullable()
        .optional(),
      sha: z.string().optional(),
    }),
  ).parse(raw);
  return events.length < 100 &&
    events.every((event) =>
      !["head_ref_force_pushed", "closed", "reopened"].includes(event.event) &&
      (event.actor?.login === author && event.actor.type === "Bot" ||
        event.event === "cross-referenced" ||
        event.event === "committed" && event.sha === head)
    );
}

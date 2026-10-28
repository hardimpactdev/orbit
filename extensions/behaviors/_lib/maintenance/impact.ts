import { z } from "npm:zod@4";
import { command, MaintenanceFailure, type Place } from "./io.ts";
import type { Change } from "./summary.ts";

const oid = z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/);
const file = z.string().min(1).refine((p) =>
  !p.startsWith("/") && !p.includes("\\") &&
  p.split("/").every((s) => s !== "" && s !== "." && s !== "..")
);
export const MAX_REPORT_LENGTH = 16_384;
export const PlainTextSchema = z.string().min(1).max(500).refine(
  (text) =>
    /^[\p{L}0-9 .,;()'/-]+$/u.test(text) &&
    !/\bGH-\d/iu.test(text) &&
    !/\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{7,}\b/iu.test(text) &&
    !/www\./iu.test(text),
  "Effects must be plain text without commit references or www links",
).transform((text) => text.trim()).pipe(z.string().min(1));
const UpdateIdentitySchema = z.object({
  manager: z.enum(["composer", "js"]),
  path: z.string().min(1),
  package: z.string().min(1),
});
export const RepairReportSchema = z.strictObject({
  updates: z.array(z.preprocess(
    (value) => {
      if (
        value && typeof value === "object" && "codeChanged" in value &&
        value.codeChanged === false
      ) return { ...value, effect: "" };
      return value;
    },
    z.strictObject({
      manager: z.enum(["composer", "js"]),
      path: z.string().min(1),
      package: z.string().min(1),
      codeChanged: z.boolean(),
      effect: z.string(),
      label: PlainTextSchema.optional(),
      files: z.array(file),
    }).superRefine((u, c) => {
      if (
        u.codeChanged
          ? !PlainTextSchema.safeParse(u.effect).success || !u.files.length
          : u.files.length !== 0
      ) {
        c.addIssue({
          code: "custom",
          message:
            "Code changes need plain text and files; unchanged updates have no files",
        });
      }
    }),
  )),
});
export function repairReport(text: string) {
  if (text.length > MAX_REPORT_LENGTH) {
    throw new MaintenanceFailure(
      "Repair impact report exceeds its length limit",
    );
  }
  try {
    return RepairReportSchema.parse(JSON.parse(text));
  } catch {
    throw new MaintenanceFailure("Invalid repair impact report");
  }
}
export const ImpactContextSchema = z.strictObject({
  report: z.string().max(MAX_REPORT_LENGTH),
  inventory: z.array(UpdateIdentitySchema),
  scriptTree: oid,
  repairTree: oid,
  checkedTree: oid,
});
export type ImpactContext = z.infer<typeof ImpactContextSchema>;
const key = (u: { manager: string; path: string; package: string }) =>
  `${u.manager}\0${u.path}\0${u.package}`;

/** Report prose belongs to the repaired source; dependency-only reruns may change locks. */
export async function impacts(
  root: string,
  places: Place[],
  base: string,
  changes: Change[],
  context?: ImpactContext | null,
  signal?: AbortSignal,
) {
  const git = async (args: string[]) => {
    const r = await command(root, "git", args, signal);
    if (r.code !== 0) {
      throw new MaintenanceFailure("Cannot read dependency impact diff");
    }
    return r.stdout;
  };
  const files = (text: string) => text.split("\0").filter(Boolean);
  const dependencies = new Set(
    places.flatMap((p) =>
      [
        "composer.json",
        "composer.lock",
        "package.json",
        "package-lock.json",
        "bun.lock",
      ].map((f) => p.path === "." ? f : `${p.path}/${f}`)
    ),
  );
  const diff = async (...trees: string[]) =>
    files(
      await git([
        "diff",
        "--no-ext-diff",
        "--no-renames",
        "--name-only",
        "-z",
        ...trees,
        "--",
      ]),
    );
  const changed = new Set([
    ...await diff(base),
    ...files(await git(["ls-files", "--others", "--exclude-standard", "-z"])),
  ]);
  const required = new Set(
    [...changed].filter((p) => !dependencies.has(p)),
  );
  if (context) {
    const repaired = await diff(context.scriptTree, context.repairTree);
    for (const name of repaired) {
      if (
        changed.has(name) && dependencies.has(name) &&
        /(?:^|\/)(?:package|composer)\.json$/.test(name)
      ) required.add(name);
    }
  }
  const result = new Map<
    string,
    z.infer<typeof RepairReportSchema>["updates"][number]
  >();
  if (!context) {
    if (required.size) {
      throw new MaintenanceFailure(
        "Source changes require a repair impact report",
      );
    }
    return result;
  }
  if (
    (await git(["rev-parse", "HEAD^{tree}"])).trim() !== context.checkedTree
  ) {
    throw new MaintenanceFailure(
      "Impact report differs from the checked candidate",
    );
  }
  if (
    (await diff(context.repairTree)).some((p) =>
      !dependencies.has(p) || required.has(p)
    )
  ) {
    throw new MaintenanceFailure(
      "Source changed after the repair impact report",
    );
  }
  const report = repairReport(context.report);
  const rows = new Map(changes.map((c) => [key(c), c]));
  const expected = new Set(context.inventory.map(key));
  for (const u of report.updates) {
    const row = rows.get(key(u));
    if (
      !row || !expected.has(key(u)) || result.has(key(u)) ||
      u.files.some((f) => !changed.has(f)) ||
      (u.codeChanged && !u.files.some((f) => required.has(f)))
    ) {
      throw new MaintenanceFailure(
        "Unknown, duplicate or stale repair impact entry",
      );
    }
    result.set(key(u), u);
  }
  const covered = new Set(report.updates.flatMap((u) => u.files));
  if (
    [...expected].some((k) => !result.has(k)) ||
    [...required].some((p) => !covered.has(p))
  ) {
    throw new MaintenanceFailure(
      "Repair impact report omits updates or changed files",
    );
  }
  // The rerun may reach an ecosystem skipped by the failed first script.
  // Source drift is refused above, and every required file is already assigned
  // to a reported update. New dependency-only rows need no code explanation.
  for (const [k, row] of rows) {
    if (!result.has(k)) {
      result.set(k, {
        manager: row.manager,
        path: row.path,
        package: row.package,
        codeChanged: false,
        effect: "",
        files: [],
      });
    }
  }
  return result;
}
export const impactKey = key;

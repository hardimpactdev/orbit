import { z } from "npm:zod@4";
import { command, MaintenanceFailure, type Place } from "./io.ts";

const LinkSchema = z.strictObject({
  id: z.string(),
  url: z.string().nullable(),
});
export const ChangeSchema = z.strictObject({
  path: z.string(),
  package: z.string(),
  from: z.string(),
  to: z.string(),
  major: z.boolean(),
  advisories: z.array(LinkSchema),
});
export type Change = z.infer<typeof ChangeSchema>;

async function git(root: string, args: string[], signal?: AbortSignal) {
  return await command(root, "git", args, signal);
}

export async function resolveBase(
  root: string,
  base?: string,
  signal?: AbortSignal,
): Promise<string> {
  if (base === undefined) {
    const result = await git(
      root,
      ["merge-base", "HEAD", "origin/HEAD"],
      signal,
    );
    if (result.code !== 0) {
      throw new MaintenanceFailure(
        "Pass base. Cannot find a merge base with origin/HEAD.",
      );
    }
    base = result.stdout.trim();
  }
  if (
    !/^[A-Za-z0-9._/-]+$/.test(base) || base.startsWith("-") ||
    base.includes("..")
  ) {
    throw new MaintenanceFailure("Invalid base ref", 64);
  }
  const result = await git(root, [
    "rev-parse",
    "--verify",
    "--end-of-options",
    `${base}^{commit}`,
  ], signal);
  if (result.code !== 0) {
    throw new MaintenanceFailure(`Cannot resolve base ${base}.`);
  }
  return result.stdout.trim();
}

export async function show(
  root: string,
  place: Place,
  base: string,
  file: string,
  signal?: AbortSignal,
) {
  const relative = place.path === "." ? file : `${place.path}/${file}`;
  const result = await git(root, [
    "show",
    "--no-textconv",
    `${base}:${relative}`,
  ], signal);
  if (result.code === 0) return result.stdout;
  if (
    result.stderr.includes("does not exist") ||
    result.stderr.includes("exists on disk, but not in")
  ) return null;
  throw new MaintenanceFailure(`Cannot read ${relative} at the base commit.`);
}

export function majorChange(from: string, to: string): boolean {
  const a = /^v?(\d+)\.(\d+)\./.exec(from);
  const b = /^v?(\d+)\.(\d+)\./.exec(to);
  if (!a || !b) return false;
  return +b[1] !== +a[1] || (+a[1] === 0 && +a[2] !== +b[2]);
}

function links(row: Change): string {
  return row.advisories.map((a) =>
    a.url === null ? a.id : `[${a.id}](${a.url})`
  ).join(", ");
}

export function render(changes: Change[]) {
  const only = changes.length === 1 ? changes[0] : null;
  let title: string;
  let intro: string;
  if (only === null) {
    title = `Maintenance update for ${
      new Date().toLocaleString("en-US", {
        month: "long",
        year: "numeric",
        timeZone: "UTC",
      })
    }`;
    intro =
      "This is a routine maintenance update. The packages below were updated to their newest safe versions.";
  } else if (only.to === "—" && only.from !== "—") {
    title = `Remove ${only.package}`;
    intro = `This removes ${only.package} ${only.from}.`;
  } else if (only.from === "—" && only.to !== "—") {
    title = `Add ${only.package} ${only.to}`;
    intro = `This adds ${only.package} ${only.to}.`;
  } else {
    title = `Update ${only.package} to ${only.to}${
      only.advisories.length ? " (security fix)" : ""
    }`;
    intro = only.from === only.to
      ? `This updates ${only.package} to ${only.to}.`
      : `This updates ${only.package} from ${only.from} to ${only.to}.`;
  }
  const actions: string[] = [];
  const fixes: string[] = [];
  const table = ["| Package | From | To | note |", "| --- | --- | --- | --- |"];
  for (const row of changes) {
    const name = row.path === "."
      ? row.package
      : `${row.package} (${row.path})`;
    if (row.major) {
      actions.push(
        `${name} ${row.from} → ${row.to} is a major update. Check its upgrade notes before you deploy.`,
      );
    }
    if (row.advisories.length) fixes.push(`${name}: ${links(row)}`);
    const note = [
      row.major ? "major" : "",
      row.advisories.length ? `security fix ${links(row)}` : "",
    ].filter(Boolean).join("; ");
    table.push(
      `| ${
        [name, row.from, row.to, note].map((v) => v.replaceAll("|", "\\|"))
          .join(" | ")
      } |`,
    );
  }
  const parts = [intro];
  if (actions.length) parts.push(`Action needed\n\n${actions.join("\n")}`);
  if (fixes.length) parts.push(`Security fixes\n\n${fixes.join("\n")}`);
  parts.push(table.join("\n"));
  const body = parts.join("\n\n") + "\n";
  return { title, body, text: title + "\n\n" + body };
}

export function summaryResult(changes: Change[]) {
  if (!changes.length) {
    throw new MaintenanceFailure("No dependency changes to summarize.", 1);
  }
  return { schema: 1 as const, ...render(changes), changes };
}

import { z } from "npm:zod@4";
import { sha256 } from "./repository_analysis.ts";

const text = (max: number) =>
  z.string().min(1).refine((s) =>
    s.isWellFormed() && [...s].length <= max && !/[\p{Cc}]/u.test(s) &&
    !/(?:\bfile:\/\/|(?<![\p{L}\p{N}_./@-])\/(?:home|fast|etc|tmp|root|var|Users|srv|opt|private|mnt|media|usr|run|dev|proc|sys|Volumes|workspace|workspaces)\/|(?<![\p{L}\p{N}_])[a-z]:[\\/]|\\\\)/iu
      .test(s)
  );
export const CommitSchema = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
const positive = z.number().int().positive().max(Number.MAX_SAFE_INTEGER);
const time = z.string().datetime();
const code = text(128).regex(/^[a-z][a-z0-9_]*$/);
const run = text(256);
const path = text(1024).refine((s) =>
  s === "." ||
  (!s.startsWith("/") && !/^[A-Za-z]:/.test(s) && !s.includes("\\") &&
    s.split("/").every((p) => p && p !== "." && p !== ".."))
);
const repository = text(512).regex(/^github\.com\/[a-z0-9_.-]+\/[a-z0-9_.-]+$/);
export function repositoryIdentity(raw: string) {
  const match = /^(?:git@github\.com:|https:\/\/github\.com\/)([^?#]+)$/.exec(
    raw,
  );
  if (!match) throw new Error("unsupported_repository");
  return repository.parse(
    `github.com/${match[1].replace(/\.git$/, "").toLowerCase()}`,
  );
}
export const SourceIdentitySchema = z.strictObject({
  path,
  ecosystem: z.enum(["composer", "npm"]),
  package_manager: z.enum(["composer", "npm", "bun", "pnpm"]),
}).refine((s) =>
  (s.ecosystem === "composer") === (s.package_manager === "composer")
);
export type SourceIdentity = z.infer<typeof SourceIdentitySchema>;
export const sourceKey = (s: SourceIdentity) =>
  JSON.stringify([s.path, s.ecosystem, s.package_manager]);
export const indexName = async (id: number, ref: string) =>
  `dependencies-v1-project-${id}-${await sha256(ref)}`;
export const reportName = async (id: string, s: SourceIdentity) =>
  `dependencies-v1-report-${id}-${await sha256(sourceKey(s))}`;
export const AttemptSchema = z.strictObject({
  run_id: run,
  started_at: time,
  finished_at: time.nullable(),
  status: z.enum(["running", "succeeded", "partial", "failed"]),
  error_code: code.nullable(),
}).refine((a) =>
  a.status === "running"
    ? a.finished_at === null && a.error_code === null
    : a.finished_at !== null &&
      Date.parse(a.finished_at) >= Date.parse(a.started_at) &&
      (a.status === "succeeded" ? a.error_code === null : a.error_code !== null)
);
export type Attempt = z.infer<typeof AttemptSchema>;
export const PointerSchema = SourceIdentitySchema.safeExtend({
  modelIdOrName: z.literal("orbit-gateway"),
  dataName: text(256).regex(
    /^dependencies-v1-report-[a-f0-9-]{36}-[a-f0-9]{64}$/,
  ),
  version: positive,
  source_commit: CommitSchema,
  observed_at: time,
  publication_id: z.uuid(),
});
export const PackageSchema = z.strictObject({
  id: text(512),
  name: text(512).regex(
    /^(?:@[A-Za-z0-9_.-]+\/)?[A-Za-z0-9_][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_.-]+)?$/,
  ),
  version: text(256),
  direct: z.boolean(),
  scopes: z.array(z.enum(["regular", "development"])).min(1).max(2).refine((
    s,
  ) => new Set(s).size === s.length),
  origin: z.enum(["registry", "git", "path"]),
});
export type Resolution = z.infer<typeof PackageSchema>;
export const FindingSchema = z.strictObject({
  id: text(512),
  package: text(512),
  kind: z.enum(["major", "advisory"]),
  current_versions: z.array(text(256)).min(1).refine((s) =>
    new Set(s).size === s.length
  ),
  target_version: z.string().regex(/^\d+\.\d+\.\d+$/).nullable(),
  advisory_ids: z.array(text(512)),
  state: z.enum(["ready", "blocked", "in_batch"]),
  reason: z.strictObject({ code, text: text(1024) }),
  links: z.strictObject({ group_id: positive, run_id: run }).nullable(),
}).refine((f) =>
  (f.kind === "advisory") === (f.advisory_ids.length > 0) &&
  (f.target_version !== null || f.state === "blocked") &&
  (f.state !== "in_batch" || (f.target_version !== null && f.links !== null))
);
export type Finding = z.infer<typeof FindingSchema>;
const PrValue = z.strictObject({
  repository,
  number: positive,
  url: text(2048),
  head_commit: CommitSchema,
  group_id: positive,
  run_id: run,
}).refine((v) => v.url === `https://${v.repository}/pull/${v.number}`);
export const PrSchema = z.strictObject({
  status: z.enum(["open", "absent", "unknown"]),
  checked_at: time,
  last_success_at: time.nullable(),
  error_code: code.nullable(),
  value: PrValue.nullable(),
}).refine((p) =>
  p.status === "unknown"
    ? p.error_code !== null && (p.value === null || p.last_success_at !== null)
    : p.error_code === null && p.last_success_at === p.checked_at &&
      (p.status === "absent" ? p.value === null : p.value !== null)
);
export type Pr = z.infer<typeof PrSchema>;
const bytes = (value: unknown) =>
  new TextEncoder().encode(JSON.stringify(value)).length;
export const ReportSchema = SourceIdentitySchema.safeExtend({
  schema_version: z.literal(1),
  publication_id: z.uuid(),
  published_at: time,
  project_id: positive,
  repository,
  source_commit: CommitSchema,
  observed_at: time,
  run_id: run,
  basis: z.literal("locked"),
  observed_on: z.literal("repository"),
  instance_id: z.null(),
  packages: z.array(PackageSchema).max(5000),
  findings: z.array(FindingSchema).max(500),
}).refine((r) =>
  bytes(r) <= 1048576 &&
  Date.parse(r.published_at) >= Date.parse(r.observed_at) &&
  new Set(r.packages.map((p) => p.id)).size === r.packages.length &&
  new Set(r.findings.map((f) => f.id)).size === r.findings.length &&
  r.findings.every((f) => r.packages.some((p) => p.name === f.package))
);
export type Report = z.infer<typeof ReportSchema>;
export const IndexSchema = z.strictObject({
  schema_version: z.literal(1),
  publication_id: z.uuid(),
  project_id: positive,
  repository,
  source_ref: text(1024).startsWith("refs/heads/"),
  source_commit: CommitSchema.nullable(),
  generated_at: time,
  last_attempt: AttemptSchema,
  sources: z.array(SourceIdentitySchema.safeExtend({
    last_attempt: AttemptSchema.refine((a) => a.status !== "partial"),
    report: PointerSchema.nullable(),
  })).min(1).max(64),
  batch_pr: PrSchema,
}).refine((i) =>
  bytes(i) <= 65536 &&
  new Set(i.sources.map(sourceKey)).size === i.sources.length &&
  (i.last_attempt.status !== "succeeded" ||
    (i.source_commit !== null &&
      i.sources.every((s) =>
        s.last_attempt.status === "succeeded" &&
        s.report?.source_commit === i.source_commit
      ))) &&
  i.sources.every((s) =>
    s.report === null || sourceKey(s) === sourceKey(s.report)
  )
);
export type Index = z.infer<typeof IndexSchema>;

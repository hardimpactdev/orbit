import { z } from "npm:zod@4.4.3";
const id = z.number().int().positive();
const sha = z.string().regex(/^[0-9a-f]{40}$/);
export const Selection = z.object({
  deployment_id: id,
  commit: sha,
  release: z.string().min(1),
}).strict();
export const Observation = z.object({
  instance_id: id,
  project_id: id,
  repository_url: z.string(),
  production: z.boolean(),
  base_urls: z.array(z.string().url()),
  selected: Selection.nullable(),
  retained_releases: z.array(z.string()),
}).strict();
export const Request = Selection.extend({
  base_url: z.string().url(),
  workflow: z.string(),
  ref: z.string(),
});
export const Operation = z.object({
  id,
  kind: z.literal("instance.smoke"),
  target_type: z.literal("instance_smoke"),
  target_id: id,
  input: Request.extend({ caller_node_id: id.optional() }),
  status: z.enum([
    "queued",
    "running",
    "waiting",
    "succeeded",
    "failed",
    "cancelled",
  ]),
  error_code: z.string().nullable(),
}).passthrough();
export const Result = Request.extend({
  state: z.enum(["pending", "passed", "failed", "unknown"]),
  run_id: id.nullable(),
  run_attempt: id.nullable(),
  workflow_sha: sha.nullable(),
  run_url: z.string().url().nullable(),
  conclusion: z.string().nullable(),
  tests: z.number().int().nonnegative(),
  failures: z.number().int().nonnegative(),
  errors: z.number().int().nonnegative(),
  skipped: z.number().int().nonnegative(),
  output: z.string().refine((value) =>
    new TextEncoder().encode(value).length <= 65536
  ),
  artifacts: z.array(
    z.object({ name: z.string(), url: z.string().url() }).strict(),
  ),
});
export const Receipt = z.object({
  request_key: z.string(),
  found: z.boolean(),
  operation: Operation.nullable(),
  smoke: Result.nullable(),
}).strict();
export const Job = z.object({
  key: z.string(),
  config: z.string(),
  request: Request,
  started_at: z.string().datetime(),
  operation_id: id.nullable(),
  result: Result.nullable(),
  previous_good: Selection.nullable().default(null),
  resend_used: z.boolean().default(false),
  refusal_retry_used: z.boolean().default(false),
  refusal: z.object({ status: z.number().int(), code: z.string() }).nullable()
    .default(null),
});
export const State = z.object({
  status: z.enum([
    "disabled",
    "observed",
    "pending",
    "passed",
    "failed",
    "unknown",
    "timed_out",
    "superseded",
  ]),
  reason: z.string().nullable(),
  selected: Selection.nullable(),
  job: Job.nullable(),
  known_good: Selection.nullable(),
});
export const same = (a: unknown, b: unknown) =>
  JSON.stringify(a) === JSON.stringify(b);
export const requestOf = (
  { deployment_id, commit, release, base_url, workflow, ref }: z.infer<
    typeof Request
  >,
) => ({ deployment_id, commit, release, base_url, workflow, ref });
export const selectionOf = (
  { deployment_id, commit, release }: z.infer<typeof Selection>,
) => ({ deployment_id, commit, release });

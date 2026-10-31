import {
  boundedJson,
  canonical,
  packagistVersion,
  record,
  type RecordValue,
  validateContents,
} from "./contents.ts";
import { requirePolicy } from "./refusal.ts";

export type MergeInput = {
  repository: string;
  default_branch: string;
  smoke_workflow: string;
  pr_number: number;
  head_sha: string;
  group_id: number;
};
export type Credentials = {
  write_token: string;
  read_token: string;
  checks_token: string;
  actions_token: string;
};
const sha = (value: unknown): value is string =>
  typeof value === "string" && /^[a-f0-9]{40}$/.test(value);
const integer = (value: unknown): value is number =>
  typeof value === "number" && Number.isSafeInteger(value) && value > 0;
export class MergeGitHub {
  readonly path: string;
  constructor(
    readonly input: MergeInput,
    private credentials: Credentials,
    private request: typeof fetch = fetch,
    private signal?: AbortSignal,
  ) {
    this.path = `/repos/${input.repository}`;
  }
  async call(
    path: string,
    scope: keyof Credentials = "write_token",
    method = "GET",
    body?: RecordValue,
  ): Promise<Response> {
    requirePolicy(path.startsWith(`${this.path}/`) || path === this.path);
    return await this.request(`https://api.github.com${path}`, {
      method,
      redirect: "error",
      signal: this.signal
        ? AbortSignal.any([this.signal, AbortSignal.timeout(30000)])
        : AbortSignal.timeout(30000),
      headers: {
        Authorization: `Bearer ${this.credentials[scope]}`,
        Accept: "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "Content-Type": "application/json",
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  }
  async json(
    path: string,
    scope: keyof Credentials = "write_token",
  ): Promise<unknown> {
    const response = await this.call(path, scope);
    requirePolicy(response.ok, "dependency_merge.checks_unreadable");
    return await boundedJson(response);
  }
  async pr(): Promise<RecordValue> {
    const pr = record(
      await this.json(`${this.path}/pulls/${this.input.pr_number}`),
    );
    const head = record(pr.head), base = record(pr.base);
    requirePolicy(
      pr.number === this.input.pr_number && head.sha === this.input.head_sha &&
        head.ref === `task-${this.input.group_id}` &&
        record(head.repo).full_name === this.input.repository &&
        record(base.repo).full_name === this.input.repository &&
        base.ref === this.input.default_branch,
      "dependency_merge.pr_changed",
    );
    requirePolicy(
      typeof pr.merged === "boolean",
      "dependency_merge.pr_changed",
    );
    if (pr.merged) {
      requirePolicy(sha(pr.merge_commit_sha), "dependency_merge.pr_changed");
    }
    return pr;
  }
  async policy(pr: RecordValue): Promise<void> {
    const repo = record(await this.json(this.path));
    requirePolicy(
      repo.default_branch === this.input.default_branch &&
        repo.archived === false && repo.allow_squash_merge === true,
      "dependency_merge.default_branch_changed",
    );
    requirePolicy(
      pr.state === "open" && pr.draft === false && pr.mergeable === true &&
        ["clean", "has_hooks"].includes(String(pr.mergeable_state)),
      "dependency_merge.pr_not_ready",
    );
    const base = record(pr.base).sha;
    requirePolicy(sha(base), "dependency_merge.pr_changed");
    for (const path of await this.files(pr)) {
      const head = await this.content(path, this.input.head_sha),
        before = await this.content(path, base);
      const manifest = ["composer.json", "package.json"].includes(path)
        ? null
        : await this.content(
          path === "composer.lock" ? "composer.json" : "package.json",
          this.input.head_sha,
        );
      await validateContents(
        path,
        head,
        before,
        manifest,
        (name, version) =>
          packagistVersion(name, version, this.request, this.signal),
      );
    }
    await this.checks();
    const fresh = await this.pr();
    for (
      const field of [
        "head",
        "base",
        "state",
        "draft",
        "merged",
        "mergeable",
        "mergeable_state",
        "changed_files",
      ]
    ) {
      requirePolicy(
        canonical(fresh[field]) === canonical(pr[field]),
        "dependency_merge.pr_changed",
      );
    }
  }
  async files(pr: RecordValue): Promise<string[]> {
    const count = pr.changed_files;
    requirePolicy(
      integer(count) && count < 3000,
      "dependency_merge.files_unreadable",
    );
    const allowed = [
        "composer.json",
        "composer.lock",
        "package.json",
        "package-lock.json",
        "npm-shrinkwrap.json",
        "pnpm-lock.yaml",
        "yarn.lock",
        "bun.lock",
      ],
      seen = new Set<string>();
    for (let page = 1;; page++) {
      const rows = await this.json(
        `${this.path}/pulls/${this.input.pr_number}/files?per_page=100&page=${page}`,
      );
      const expected = Math.min(100, Math.max(0, count - seen.size));
      requirePolicy(
        Array.isArray(rows) && rows.length === expected,
        "dependency_merge.files_unreadable",
      );
      if (!expected) return [...seen];
      for (const raw of rows) {
        const row = record(raw);
        requirePolicy(
          typeof row.filename === "string" && !seen.has(row.filename),
          "dependency_merge.files_unreadable",
        );
        requirePolicy(
          allowed.includes(row.filename),
          "dependency_merge.files_forbidden",
        );
        requirePolicy(
          [
            "added",
            "modified",
            "removed",
            "renamed",
            "copied",
            "changed",
            "unchanged",
          ].includes(String(row.status)),
          "dependency_merge.files_unreadable",
        );
        requirePolicy(
          row.status !== "removed",
          "dependency_merge.lock_removed",
        );
        if (
          ["renamed", "copied"].includes(String(row.status)) ||
          Object.hasOwn(row, "previous_filename")
        ) {
          requirePolicy(
            typeof row.previous_filename === "string" &&
              allowed.includes(row.previous_filename),
            "dependency_merge.files_forbidden",
          );
        }
        seen.add(row.filename);
      }
    }
  }
  async content(path: string, commit: string): Promise<string> {
    const row = record(
      await this.json(
        `${this.path}/contents/${encodeURIComponent(path)}?ref=${commit}`,
        "read_token",
      ),
    );
    requirePolicy(
      row.type === "file" && row.path === path && row.encoding === "base64" &&
        typeof row.content === "string" && typeof row.size === "number" &&
        Number.isSafeInteger(row.size) && row.size >= 0,
      "dependency_merge.contents_unreadable",
    );
    try {
      const bytes = atob(row.content.replace(/[\r\n]/g, ""));
      requirePolicy(
        bytes.length === row.size &&
          btoa(bytes) === row.content.replace(/[\r\n]/g, ""),
        "dependency_merge.contents_unreadable",
      );
      return new TextDecoder("utf-8", { fatal: true }).decode(
        Uint8Array.from(bytes, (c) => c.charCodeAt(0)),
      );
    } catch {
      requirePolicy(false, "dependency_merge.contents_unreadable");
    }
  }
  async rows(
    path: string,
    field: string,
    scope: keyof Credentials,
  ): Promise<RecordValue[]> {
    const response = record(await this.json(path, scope)),
      rows = response[field];
    requirePolicy(
      Array.isArray(rows) && rows.length < 100 &&
        response.total_count === rows.length,
      "dependency_merge.checks_unreadable",
    );
    return rows.map(record);
  }
  async checks(): Promise<void> {
    const workflows = await this.rows(
      `${this.path}/actions/workflows?per_page=100`,
      "workflows",
      "actions_token",
    );
    const smoke: number[] = [];
    for (const row of workflows) {
      requirePolicy(integer(row.id), "dependency_merge.checks_unreadable");
      if (row.path === this.input.smoke_workflow && row.state === "active") {
        smoke.push(row.id);
      }
    }
    requirePolicy(smoke.length === 1, "dependency_merge.smoke_missing");
    const runs = await this.rows(
      `${this.path}/actions/runs?head_sha=${this.input.head_sha}&event=pull_request&per_page=100`,
      "workflow_runs",
      "actions_token",
    );
    const latest = new Map<number, RecordValue>();
    for (const row of runs) {
      requirePolicy(
        row.head_sha === this.input.head_sha && row.event === "pull_request" &&
          row.head_branch === `task-${this.input.group_id}` &&
          integer(row.id) && integer(row.workflow_id) &&
          integer(row.check_suite_id),
        "dependency_merge.checks_unreadable",
      );
      const previous = latest.get(row.workflow_id);
      if (!previous || Number(previous.id) < row.id) {
        latest.set(row.workflow_id, row);
      }
    }
    requirePolicy(
      latest.size > 0 &&
        [...latest.values()].every((row) =>
          row.status === "completed" && row.conclusion === "success"
        ),
      "dependency_merge.checks_not_passed",
    );
    const checks: RecordValue[] = [];
    let count: number | undefined;
    for (let page = 1;; page++) {
      const data = record(
        await this.json(
          `${this.path}/commits/${this.input.head_sha}/check-runs?per_page=100&page=${page}`,
          "checks_token",
        ),
      );
      requirePolicy(
        typeof data.total_count === "number" &&
          Number.isSafeInteger(data.total_count) && data.total_count >= 0 &&
          data.total_count < 3000 &&
          (count === undefined || count === data.total_count),
        "dependency_merge.checks_unreadable",
      );
      count = data.total_count;
      const rows = data.check_runs;
      requirePolicy(
        Array.isArray(rows) &&
          rows.length === Math.min(100, count - checks.length),
        "dependency_merge.checks_unreadable",
      );
      checks.push(...rows.map(record));
      if (checks.length === count) break;
    }
    requirePolicy(
      new Set(checks.map((row) => row.id)).size === checks.length &&
        checks.every((row) => integer(row.id)),
      "dependency_merge.checks_unreadable",
    );
    const suites = new Set(
      [...latest.values()].map((row) => row.check_suite_id),
    );
    const admitted = checks.filter((row) =>
      suites.has(record(row.check_suite).id)
    );
    const passed = (rows: RecordValue[], title: string) => {
      const selected = rows.filter((row) => row.name === title);
      return selected.length > 0 &&
        selected.every((row) =>
          record(row.app).slug === "github-actions" &&
          row.head_sha === this.input.head_sha && row.status === "completed" &&
          row.conclusion === "success"
        );
    };
    requirePolicy(
      passed(admitted, "Required checks"),
      "dependency_merge.checks_not_passed",
    );
    for (const id of smoke) {
      const run = latest.get(id);
      requirePolicy(run, "dependency_merge.smoke_missing");
      const suite = admitted.filter((row) =>
        record(row.check_suite).id === run.check_suite_id
      );
      requirePolicy(
        passed(suite, "smoke") || passed(suite, "Production smoke / smoke"),
        "dependency_merge.smoke_not_passed",
      );
    }
  }
}

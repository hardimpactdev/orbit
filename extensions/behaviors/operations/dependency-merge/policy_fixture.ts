import type { MergeInput } from "./policy.ts";
import type { RecordValue } from "./contents.ts";

export const head = "a".repeat(40), merge = "b".repeat(40);
export const fixtureTokens = {
  write_token: "fixture-write-token",
  read_token: "fixture-read-token",
  checks_token: "fixture-checks-token",
  actions_token: "fixture-actions-token",
};
export const fixtureInput: MergeInput = {
  repository: "acme/widgets",
  default_branch: "main",
  smoke_workflow: ".github/workflows/production-smoke.yml",
  pr_number: 3,
  head_sha: head,
  group_id: 91,
};
/** Public evidence fixtures only. Credentials are injected separately at the boundary. */
export function policyFixture(pr: RecordValue) {
  const rows: Record<string, unknown> = {
    "/repos/acme/widgets": {
      default_branch: "main",
      archived: false,
      allow_squash_merge: true,
    },
    "/repos/acme/widgets/pulls/3": pr,
    "/repos/acme/widgets/pulls/3/files?per_page=100&page=1": [{
      filename: "composer.lock",
      status: "modified",
    }],
    "/repos/acme/widgets/pulls/3/files?per_page=100&page=2": [],
    "/repos/acme/widgets/actions/workflows?per_page=100": {
      total_count: 2,
      workflows: [
        { id: 1, path: ".github/workflows/ci.yml", state: "active" },
        {
          id: 2,
          path: ".github/workflows/production-smoke.yml",
          state: "active",
        },
      ],
    },
    [`/repos/acme/widgets/actions/runs?head_sha=${head}&event=pull_request&per_page=100`]:
      {
        total_count: 2,
        workflow_runs: [1, 2].map((id) => ({
          id,
          workflow_id: id,
          check_suite_id: id,
          head_sha: head,
          head_branch: "task-91",
          event: "pull_request",
          status: "completed",
          conclusion: "success",
        })),
      },
    [`/repos/acme/widgets/commits/${head}/check-runs?per_page=100&page=1`]: {
      total_count: 2,
      check_runs: ["Required checks", "smoke"].map((title, i) => ({
        id: i + 1,
        name: title,
        head_sha: head,
        app: { slug: "github-actions" },
        check_suite: { id: i + 1 },
        status: "completed",
        conclusion: "success",
      })),
    },
  };
  const content = (file: string, value: string) => ({
    type: "file",
    path: file,
    encoding: "base64",
    size: new TextEncoder().encode(value).length,
    content: btoa(value),
  });
  for (const commit of [head, merge]) {
    rows[`/repos/acme/widgets/contents/composer.lock?ref=${commit}`] = content(
      "composer.lock",
      '{"packages":[],"packages-dev":[]}',
    );
    rows[`/repos/acme/widgets/contents/composer.json?ref=${commit}`] = content(
      "composer.json",
      "{}",
    );
  }
  return rows;
}
export function publicPr(): RecordValue {
  return {
    number: 3,
    state: "open",
    merged: false,
    merge_commit_sha: null,
    draft: false,
    mergeable: true,
    mergeable_state: "clean",
    changed_files: 1,
    head: { sha: head, ref: "task-91", repo: { full_name: "acme/widgets" } },
    base: { sha: merge, ref: "main", repo: { full_name: "acme/widgets" } },
  };
}

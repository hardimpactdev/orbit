import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { MergeGitHub } from "./policy.ts";
import { type RecordValue } from "./contents.ts";
import {
  fixtureInput,
  fixtureTokens,
  head,
  policyFixture,
  publicPr,
} from "./policy_fixture.ts";
class Fixture {
  pr = publicPr();
  rows = policyFixture(this.pr);
  calls: string[] = [];
  fresh: RecordValue | undefined;
  reads = 0;
  request: typeof fetch = async (url, init) => {
    await Promise.resolve();
    const path = String(url).replace("https://api.github.com", "");
    this.calls.push(path);
    const token = path.includes("/actions/")
      ? fixtureTokens.actions_token
      : path.includes("/check-runs")
      ? fixtureTokens.checks_token
      : path.includes("/contents/")
      ? fixtureTokens.read_token
      : fixtureTokens.write_token;
    assertEquals(
      (init?.headers as Record<string, string>).Authorization,
      `Bearer ${token}`,
    );
    assertEquals(init?.redirect, "error");
    if (
      path === "/repos/acme/widgets/pulls/3" && ++this.reads > 1 && this.fresh
    ) return Response.json(this.fresh);
    if (!(path in this.rows)) throw new Error("Unexpected endpoint");
    return Response.json(this.rows[path]);
  };
  async run() {
    const github = new MergeGitHub(fixtureInput, fixtureTokens, this.request);
    await github.policy(await github.pr());
  }
  workflows() {
    return (this.rows["/repos/acme/widgets/actions/workflows?per_page=100"] as {
      workflows: RecordValue[];
    }).workflows;
  }
  runs() {
    return (this
      .rows[
        `/repos/acme/widgets/actions/runs?head_sha=${head}&event=pull_request&per_page=100`
      ] as { workflow_runs: RecordValue[] }).workflow_runs;
  }
  checks() {
    return (this
      .rows[
        `/repos/acme/widgets/commits/${head}/check-runs?per_page=100&page=1`
      ] as { check_runs: RecordValue[] }).check_runs;
  }
}
Deno.test("fresh policy reads repository/content/default branch and exact workflow suites", async () => {
  const f = new Fixture();
  await f.run();
  assertEquals(f.reads, 2);
});
for (
  const change of [
    "draft",
    "notMergeable",
    "defaultBranch",
    "archived",
    "squashDisabled",
    "filesCount",
    "filesCapped",
    "filesMissingPage",
    "filesDuplicate",
    "foreignRename",
    "removed",
    "codeFile",
    "smokePath",
    "smokeInactive",
    "smokeSuite",
    "actionsPending",
    "actionsFailed",
    "actionsWrongHead",
    "checkApp",
    "checkHead",
    "checkFailed",
    "missingRequired",
    "checksCount",
    "checksDuplicate",
    "staleCheckSuite",
    "postEvidenceHead",
    "truncatedContent",
    "badBase64",
    "wrongContentPath",
    "duplicateManifest",
    "changedBuildScript",
  ]
) {
  Deno.test(`fresh operational policy refuses ${change}`, async () => {
    const f = new Fixture(),
      repo = f.rows["/repos/acme/widgets"] as RecordValue;
    if (change === "draft") f.pr.draft = true;
    if (change === "notMergeable") f.pr.mergeable = null;
    if (change === "defaultBranch") repo.default_branch = "other";
    if (change === "archived") repo.archived = true;
    if (change === "squashDisabled") repo.allow_squash_merge = false;
    if (change === "filesCount") f.pr.changed_files = 2;
    if (change === "filesCapped") f.pr.changed_files = 3000;
    if (change === "filesMissingPage") {
      delete f.rows["/repos/acme/widgets/pulls/3/files?per_page=100&page=2"];
    }
    if (change === "filesDuplicate") {
      f.pr.changed_files = 2;
      f.rows["/repos/acme/widgets/pulls/3/files?per_page=100&page=1"] = [{
        filename: "composer.lock",
        status: "modified",
      }, { filename: "composer.lock", status: "modified" }];
    }
    if (change === "foreignRename") {
      f.rows["/repos/acme/widgets/pulls/3/files?per_page=100&page=1"] = [{
        filename: "composer.lock",
        status: "renamed",
        previous_filename: ".github/workflows/ci.yml",
      }];
    }
    if (change === "removed") {
      f.rows["/repos/acme/widgets/pulls/3/files?per_page=100&page=1"] = [{
        filename: "composer.lock",
        status: "removed",
      }];
    }
    if (change === "codeFile") {
      f.rows["/repos/acme/widgets/pulls/3/files?per_page=100&page=1"] = [{
        filename: "src/main.ts",
        status: "modified",
      }];
    }
    if (change === "smokePath") {
      f.workflows()[1].path = ".github/workflows/unrelated.yml";
    }
    if (change === "smokeInactive") f.workflows()[1].state = "disabled";
    if (change === "smokeSuite") f.checks()[1].check_suite = { id: 1 };
    if (change === "actionsPending") f.runs()[0].status = "in_progress";
    if (change === "actionsFailed") f.runs()[0].conclusion = "failure";
    if (change === "actionsWrongHead") f.runs()[0].head_sha = "c".repeat(40);
    if (change === "checkApp") f.checks()[0].app = { slug: "foreign-app" };
    if (change === "checkHead") f.checks()[0].head_sha = "c".repeat(40);
    if (change === "checkFailed") f.checks()[0].conclusion = "failure";
    if (change === "missingRequired") f.checks()[0].name = "Unrelated";
    if (change === "checksCount") {
      (f.rows[
        `/repos/acme/widgets/commits/${head}/check-runs?per_page=100&page=1`
      ] as RecordValue).total_count = 3;
    }
    if (change === "checksDuplicate") f.checks()[1].id = f.checks()[0].id;
    if (change === "staleCheckSuite") f.checks()[0].check_suite = { id: 999 };
    if (change === "postEvidenceHead") {
      f.fresh = structuredClone(f.pr);
      (f.fresh.head as RecordValue).sha = "c".repeat(40);
    }
    const content = f
      .rows[
        `/repos/acme/widgets/contents/composer.lock?ref=${head}`
      ] as RecordValue;
    if (change === "truncatedContent") content.size = 999;
    if (change === "badBase64") content.content = "not base64!";
    if (change === "wrongContentPath") content.path = "secret.txt";
    if (change === "duplicateManifest" || change === "changedBuildScript") {
      const value = change === "duplicateManifest"
        ? '{"require":{},"require":{}}'
        : '{"scripts":{"post-install-cmd":"hostile"}}';
      f.rows["/repos/acme/widgets/pulls/3/files?per_page=100&page=1"] = [{
        filename: "composer.json",
        status: "modified",
      }];
      f.rows[`/repos/acme/widgets/contents/composer.json?ref=${head}`] = {
        type: "file",
        path: "composer.json",
        encoding: "base64",
        size: value.length,
        content: btoa(value),
      };
    }
    await assertRejects(() => f.run());
  });
}
Deno.test("only latest exact PR workflow runs count, an older failed suite is superseded", async () => {
  const f = new Fixture();
  f.runs().push({ ...f.runs()[0], id: 99, check_suite_id: 99 });
  f.runs()[0].conclusion = "failure";
  (f.rows[
    `/repos/acme/widgets/actions/runs?head_sha=${head}&event=pull_request&per_page=100`
  ] as RecordValue).total_count = 3;
  f.checks()[0].check_suite = { id: 99 };
  await f.run();
});

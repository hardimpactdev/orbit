import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
  appParent,
  appPull,
  checkState,
  currentDate,
  mergeMetadata,
  nightlySnapshot,
  recipeOnly,
  snapshotPatch,
  trustedFailedChecks,
  untouched,
} from "./policy.ts";
import {
  artifactFingerprint,
  isDeployed,
  parseRecipe,
  rewriteSnapshot,
  same,
  validateArtifactChange,
} from "./contents.ts";
import { compareVersions, releaseHashes, stanzas } from "./indexes.ts";
import {
  author,
  branch,
  check,
  commit,
  head,
  parent,
  pull,
  recipe,
  source,
} from "./fixture.ts";
Deno.test("recipe PR metadata binds App identity repository head branch and single recipe commit", () => {
  assertEquals(mergeMetadata(pull(), head, branch, author).number, 19);
  for (
    const changed of [
      { ...pull(), draft: true },
      { ...pull(), commits: 2 },
      { ...pull(), changed_files: 2 },
      { ...pull(), user: { login: "operator", type: "User" } },
      {
        ...pull(),
        head: { ...pull().head, repo: { full_name: "fork/orbit" } },
      },
    ]
  ) {
    assertThrows(() => mergeMetadata(changed, head, branch, author));
  }
  assertEquals(appPull(pull(), parent, branch, author), null);
});
Deno.test("only one verified App web-flow commit with a single parent proves the change", () => {
  assertEquals(appParent([commit()], head, author), parent);
  for (
    const altered of [{ ...commit(), author: null }, {
      ...commit(),
      committer: { login: author, type: "Bot" },
    }, {
      ...commit(),
      commit: { verification: { verified: false, reason: "unsigned" } },
    }, { ...commit(), parents: [{ sha: parent }, { sha: head }] }]
  ) assertThrows(() => appParent([altered], head, author));
  assertThrows(() => appParent([commit(), commit()], head, author));
});
Deno.test("every required check must be trusted green and on the exact head", () => {
  assertEquals(checkState([check], head, check.name), "passed");
  assertEquals(checkState([], head, check.name), "missing");
  assertEquals(
    checkState(
      [{ ...check, status: "in_progress", conclusion: null }],
      head,
      check.name,
    ),
    "pending",
  );
  for (
    const run of [
      { ...check, head_sha: parent },
      { ...check, app: { slug: "spoof" } },
      { ...check, conclusion: "skipped" },
      { ...check, conclusion: "failure" },
    ]
  ) assertEquals(checkState([check, run], head, check.name), "failed");
  assertEquals(
    trustedFailedChecks(
      [{ ...check, conclusion: "failure" }],
      head,
      check.name,
    ),
    true,
  );
  assertEquals(
    trustedFailedChecks(
      [{ ...check, app: { slug: "spoof" }, conclusion: "failure" }],
      head,
      check.name,
    ),
    false,
  );
});
Deno.test("refresh refuses truncated timeline human edits close/reopen and force pushes", () => {
  assertEquals(
    untouched(
      [{ event: "committed", sha: head }, {
        event: "cross-referenced",
        actor: { login: "operator", type: "User" },
      }],
      author,
      head,
    ),
    true,
  );
  for (const event of ["closed", "reopened", "head_ref_force_pushed"]) {
    assertEquals(
      untouched(
        [{ event, actor: { login: author, type: "Bot" } }],
        author,
        head,
      ),
      false,
    );
  }
  assertEquals(
    untouched(
      [{ event: "labeled", actor: { login: "operator", type: "User" } }],
      author,
      head,
    ),
    false,
  );
  assertEquals(
    untouched(
      Array.from({ length: 100 }, () => ({ event: "committed", sha: head })),
      author,
      head,
    ),
    false,
  );
});
Deno.test("snapshot rewrite changes only the authenticated Ubuntu pins and snapshot", () => {
  const before = source(),
    next = rewriteSnapshot(before, "20261030T000000Z", { curl: "8.1-1" });
  assertEquals(parseRecipe(next).tools.curl.version, "8.1-1");
  assertEquals(parseRecipe(next).tools.incus, parseRecipe(before).tools.incus);
  const invalidVersions: Record<string, string>[] = [{}, {
    curl: "8.1-1",
    incus: "7.0.3",
  }, { curl: "bad\nversion" }];
  for (const versions of invalidVersions) {
    assertThrows(() => rewriteSnapshot(before, "20261030T000000Z", versions));
  }
});
Deno.test("recipe parsing rejects duplicate keys invalid dates architecture and extra contracts", () => {
  const data = source();
  assertEquals(parseRecipe(data).architectures, ["amd64"]);
  for (
    const value of [
      data.replace(
        '"schema_version": 1',
        '"schema_version": 1, "schema_version": 1',
      ),
      data.replace("20261029T000000Z", "20260230T000000Z"),
      JSON.stringify({ ...recipe(), architectures: ["arm64"] }),
      JSON.stringify({ ...recipe(), script: "sh" }),
      data.replace(
        '"source": "ubuntu"',
        '"source": "ubuntu", "source": "artifact"',
      ),
    ]
  ) assertThrows(() => parseRecipe(value));
});
Deno.test("deployment gate ignores only snapshot and retains Ubuntu version protection", () => {
  const deployed = source();
  assertEquals(
    isDeployed(
      deployed.replace("20261029T000000Z", "20261030T000000Z"),
      deployed,
    ),
    true,
  );
  assertEquals(isDeployed(deployed.replace("8.0-1", "8.1-1"), deployed), false);
});
Deno.test("snapshot proof checks exact diff and recipe-only file statistics", () => {
  const before = "old\nkept\n",
    after = "new\nkept\n",
    file = {
      filename: "apps/gateway/resources/machine-software/recipe.json",
      status: "modified",
      patch: "@@\n-old\n+new\n kept",
      additions: 1,
      deletions: 1,
      changes: 2,
    };
  snapshotPatch([file], before, after, after);
  for (
    const altered of [{ ...file, additions: 2 }, {
      ...file,
      patch: "@@\n-old\n+different",
    }, { ...file, filename: "README.md" }]
  ) assertThrows(() => snapshotPatch([altered], before, after, after));
  assertThrows(() => recipeOnly([file, file]));
});
Deno.test("artifact proof changes only complete named pin fields and has a deterministic fingerprint", async () => {
  const before = source(),
    after = before.replace('"version": "7.0.2"', '"version": "7.0.3"');
  validateArtifactChange(before, after);
  assertThrows(() =>
    validateArtifactChange(before, after.replace("8.0-1", "8.1-1"))
  );
  assertThrows(() => validateArtifactChange(before, before));
  assertEquals((await artifactFingerprint(after)).length, 12);
  assertEquals(same({ b: 1, a: 2 }, { a: 2, b: 1 }), true);
});
Deno.test("Debian versions compare epochs tilde revisions digit runs like apt", () => {
  for (
    const [left, right] of [["2:1.0-1", "1:9.0-1"], ["1.0", "1.0~rc1"], [
      "1.0-10",
      "1.0-2",
    ], ["8.5.12-1+0~20261030", "8.5.11-9"]]
  ) {
    assertEquals(compareVersions(left, right), 1);
    assertEquals(compareVersions(right, left), -1);
  }
  assertEquals(compareVersions("1.01-01", "1.1-1"), 0);
});
Deno.test("signed metadata needs exact SHA256 entries and unique Debian stanza keys", () => {
  assertEquals(
    releaseHashes(
      "SHA256:\n " + "a".repeat(64) +
        " 123 main/binary-amd64/Packages.gz\nDate: today\n",
    ),
    { "main/binary-amd64/Packages.gz": { sha256: "a".repeat(64), size: 123 } },
  );
  assertThrows(() => releaseHashes("SHA256:\n invalid 123 path\n"));
  assertEquals(Array.from(stanzas("Package: curl\nVersion: 8.0\n\n")), [{
    Package: "curl",
    Version: "8.0",
  }]);
  assertThrows(() =>
    Array.from(stanzas("Package: curl\nVersion: 8.0\nVersion: 9.0\n"))
  );
});
Deno.test("nightly date follows Amsterdam and branches remain narrow", () => {
  assertEquals(currentDate(new Date("2026-10-30T23:30:00Z")), "20261031");
  assertEquals(nightlySnapshot(branch), "20261030T000000Z");
  assertEquals(nightlySnapshot("nightly/recipe-other"), null);
});

import { Repository } from "./policy.ts";
export const head = "a".repeat(40),
  parent = "b".repeat(40),
  author = "orbit-gateway[bot]";
export const branch = "nightly/recipe-20261030";
export function pull() {
  return {
    number: 19,
    html_url: "https://github.com/hardimpactdev/orbit/pull/19",
    state: "open",
    draft: false,
    user: { login: author, type: "Bot" },
    commits: 1,
    changed_files: 1,
    base: { ref: "main", repo: { full_name: Repository } },
    head: { ref: branch, sha: head, repo: { full_name: Repository } },
  };
}
export function commit() {
  return {
    sha: head,
    author: { login: author, type: "Bot" },
    committer: { login: "web-flow", type: "User" },
    commit: { verification: { verified: true, reason: "valid" } },
    parents: [{ sha: parent }],
  };
}
export const check = {
  name: "Required checks",
  head_sha: head,
  status: "completed",
  conclusion: "success",
  app: { slug: "github-actions" },
};
export function recipe() {
  return {
    schema_version: 1,
    agent: null,
    ubuntu: { release: "26.04", snapshot: "20261029T000000Z" },
    architectures: ["amd64"],
    tools: {
      curl: { source: "ubuntu", package: "curl", version: "8.0-1" },
      incus: {
        source: "artifact",
        version: "7.0.2",
        artifacts: {
          amd64: { url: "https://example.test/incus", sha256: "c".repeat(64) },
        },
      },
      "incus-base": { source: "artifact", version: "7.0.2" },
      "incus-client": { source: "artifact", version: "7.0.2" },
      "php8.5-common": { source: "artifact", version: "8.5.12-1" },
    },
    common: [],
    base: [],
    roles: {},
    php: { shared: [], versions: { "8.5": { packages: ["php8.5-common"] } } },
    artifact_mirror: "https://example.test/mirror",
    git: { packages: ["git", "git-man"], usn_since: "2026-08-11" },
  };
}
export function source() {
  return JSON.stringify(recipe(), null, 4) + "\n";
}

/** Disposable native preparation storage; no credential enters this resource. */
export function openPlanStore() {
  let saved: unknown = null;
  return {
    read: () => Promise.resolve(structuredClone(saved)),
    write: (plan: unknown) => {
      saved = structuredClone(plan);
      return Promise.resolve();
    },
  };
}

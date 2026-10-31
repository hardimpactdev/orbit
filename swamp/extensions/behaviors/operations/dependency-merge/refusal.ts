export const refusalCodes = [
  "dependency_merge.disabled",
  "dependency_merge.pr_changed",
  "dependency_merge.merge_refused",
  "dependency_merge.default_branch_changed",
  "dependency_merge.pr_not_ready",
  "dependency_merge.files_unreadable",
  "dependency_merge.files_forbidden",
  "dependency_merge.lock_removed",
  "dependency_merge.contents_unreadable",
  "dependency_merge.contents_forbidden",
  "dependency_merge.major_bump",
  "dependency_merge.smoke_missing",
  "dependency_merge.checks_unreadable",
  "dependency_merge.checks_not_passed",
  "dependency_merge.smoke_not_passed",
  "dependency_merge.packagist_unavailable",
] as const;
export type RefusalCode = typeof refusalCodes[number];
export class Refusal extends Error {
  constructor(readonly code: RefusalCode) {
    super(code);
  }
}
export function requirePolicy(
  condition: unknown,
  code: RefusalCode = "dependency_merge.contents_forbidden",
): asserts condition {
  if (!condition) throw new Refusal(code);
}

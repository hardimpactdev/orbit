/** Project-only choices live at this edge; the detector owns no deployment state. */
export const project = {
  projectId: 57,
  slug: "horizon-new-dawn",
  repository: "nckrtl/horizon-new-dawn",
  defaultBranch: "main",
  paths: ["."],
  ledger: "horizon-sync.json",
  upstream: "https://github.com/laravel/horizon.git",
  checkCommand: "composer format:test && composer analyse && composer test",
  compatibilityCommand: "composer test:compatibility",
  mergeApproval: "nick",
} as const;

import { type DependencyProject } from "../../extensions/behaviors/_lib/dependency_projects.ts";

export const WEBSITE_PROJECT: DependencyProject = {
  dependencyAutoMerge: false,
  exceptions: { schema: 1, exceptions: [] },
  projectId: 20,
  repository: "git@github.com:nckrtl/website.git",
  worker: { composerPaths: ["."], jsPaths: ["."], policy: {} },
  check: "composer check",
  defaultBranch: "main",
};

// Synthetic project: different check, repository and default branch.
export const SECOND_PROJECT: DependencyProject = {
  dependencyAutoMerge: false,
  exceptions: { schema: 1, exceptions: [] },
  projectId: 99,
  repository: "git@github.com:fixture/second-laravel.git",
  worker: { composerPaths: ["."], jsPaths: ["."], policy: {} },
  check: "composer verify",
  defaultBranch: "trunk",
};

import { type DependencyProject } from "../../extensions/behaviors/_lib/dependency_projects.ts";

export const WEBSITE_PROJECT: DependencyProject = {
  projectId: 20,
  repository: "git@github.com:nckrtl/website.git",
  script: "scripts/dependencies",
  check: "composer check",
  defaultBranch: "main",
};

// Synthetic project: different script, check, repository and default branch.
export const SECOND_PROJECT: DependencyProject = {
  projectId: 99,
  repository: "git@github.com:fixture/second-laravel.git",
  script: "bin/update-dependencies",
  check: "composer verify",
  defaultBranch: "trunk",
};

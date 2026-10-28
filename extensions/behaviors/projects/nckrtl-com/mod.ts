import { projectConfig } from "../../_lib/project_extension.ts";

/** nckrtl.com project config; package operations live in the ecosystem extensions. */
export const behavior = projectConfig("@nckrtl/project-nckrtl-com", {
  projectId: 20,
  repository: "git@github.com:nckrtl/website.git",
  worker: {
    composerPaths: ["."],
    jsPaths: ["."],
    jsPackageManagers: { ".": "npm" },
    policy: { ".": { minimumReleaseAgeDays: 7 } },
  },
  check: "composer check",
  defaultBranch: "main",
});

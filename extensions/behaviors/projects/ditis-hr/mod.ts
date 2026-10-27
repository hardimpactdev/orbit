import { projectConfig } from "../../_lib/project_extension.ts";

/** ditis-hr project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-ditis-hr", {
  "projectId": 13,
  "repository": "https://github.com/hardimpactdev/ditis-hr.git",
  "script": "scripts/dependencies",
  "worker": {
    "composerPaths": [
      ".",
    ],
    "jsPaths": [
      ".",
    ],
    "policy": {
      ".": {
        "minimumReleaseAgeDays": 7,
      },
    },
  },
  "check": "composer check",
  "defaultBranch": "main",
});

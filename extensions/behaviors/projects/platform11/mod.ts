import { projectConfig } from "../../_lib/project_extension.ts";

/** platform11 project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-platform11", {
  "projectId": 22,
  "repository": "https://github.com/hardimpactdev/platform11.git",
  "worker": {
    "composerPaths": [
      ".",
    ],
    "jsPaths": [
      ".",
    ],
    "jsPackageManagers": { ".": "bun" },
    "policy": {
      ".": {
        "minimumReleaseAgeDays": 7,
      },
    },
  },
  "check": "composer check",
  "defaultBranch": "main",
});

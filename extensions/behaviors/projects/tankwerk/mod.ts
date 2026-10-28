import { projectConfig } from "../../_lib/project_extension.ts";

/** tankwerk project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-tankwerk", {
  "projectId": 26,
  "repository": "https://github.com/hardimpactdev/tankwerk.git",
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

import { projectConfig } from "../../_lib/project_extension.ts";

/** lindaretel project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-lindaretel", {
  "projectId": 18,
  "repository": "https://github.com/hardimpactdev/lindaretel.git",
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

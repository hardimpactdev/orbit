import { projectConfig } from "../../_lib/project_extension.ts";

/** house-italy project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-house-italy", {
  "projectId": 17,
  "repository": "https://github.com/nckrtl/house-italy.git",
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

import { projectConfig } from "../../_lib/project_extension.ts";

/** fitta project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-fitta", {
  "projectId": 15,
  "repository": "https://github.com/nckrtl/fitta.git",
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

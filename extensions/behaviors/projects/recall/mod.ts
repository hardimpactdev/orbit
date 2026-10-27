import { projectConfig } from "../../_lib/project_extension.ts";

/** recall project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-recall", {
  "projectId": 49,
  "repository": "https://github.com/nckrtl/recall.git",
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

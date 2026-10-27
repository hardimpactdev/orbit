import { projectConfig } from "../../_lib/project_extension.ts";

/** mealou project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-mealou", {
  "projectId": 19,
  "repository": "https://github.com/hardimpactdev/mealou.git",
  "worker": {
    "composerPaths": [
      ".",
    ],
    "jsPaths": [
      ".",
      "mobile",
    ],
    "policy": {
      ".": {
        "minimumReleaseAgeDays": 7,
      },
      "mobile": {
        "minimumReleaseAgeDays": 7,
      },
    },
  },
  "check": "composer check",
  "defaultBranch": "main",
});

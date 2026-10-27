import { projectConfig } from "../../_lib/project_extension.ts";

/** launch project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-launch", {
  "projectId": 32,
  "repository": "https://github.com/nckrtl/launch.git",
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

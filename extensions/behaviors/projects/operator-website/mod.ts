import { projectConfig } from "../../_lib/project_extension.ts";

/** operator-website project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-operator-website", {
  "projectId": 47,
  "repository": "https://github.com/nckrtl/operator-website.git",
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

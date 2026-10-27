import { projectConfig } from "../../_lib/project_extension.ts";

/** operator-website project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-operator-website", {
  "exceptions": {
    "schema": 1,
    "exceptions": [
      {
        "path": ".",
        "manager": "composer",
        "package": "hardimpactdev/maintainer",
        "recorded_on": "2026-10-26",
        "days": 7,
      },
    ],
  },
  "projectId": 47,
  "repository": "https://github.com/nckrtl/operator-website.git",
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

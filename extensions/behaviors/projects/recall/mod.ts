import { projectConfig } from "../../_lib/project_extension.ts";

/** recall project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-recall", {
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
  "projectId": 49,
  "repository": "https://github.com/nckrtl/recall.git",
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

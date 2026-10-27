import { projectConfig } from "../../_lib/project_extension.ts";

/** operator project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-operator", {
  "exceptions": {
    "schema": 1,
    "exceptions": [
      {
        "path": "server/web",
        "manager": "composer",
        "package": "hardimpactdev/maintainer",
        "recorded_on": "2026-10-26",
        "days": 7,
      },
    ],
  },
  "projectId": 48,
  "repository": "https://github.com/nckrtl/operator.git",
  "worker": {
    "composerPaths": [
      "server/web",
    ],
    "jsPaths": [
      "server/web",
    ],
    "policy": {
      "server/web": {
        "minimumReleaseAgeDays": 7,
      },
    },
  },
  "check": "composer --working-dir=server/web check",
  "defaultBranch": "main",
});

import { projectConfig } from "../../_lib/project_extension.ts";

/** laravel-toolbar-site project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-laravel-toolbar-site", {
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
  "projectId": 28,
  "repository": "https://github.com/hardimpactdev/laravel-toolbar-site.git",
  "worker": {
    "composerPaths": [
      ".",
    ],
    "jsPaths": [
      ".",
    ],
    "jsPackageManagers": { ".": "npm" },
    "policy": {
      ".": {
        "minimumReleaseAgeDays": 7,
      },
    },
  },
  "check": "composer check",
  "defaultBranch": "main",
});

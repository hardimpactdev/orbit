import { projectConfig } from "../../_lib/project_extension.ts";

/** laravel-toolbar-site project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-laravel-toolbar-site", {
  "projectId": 28,
  "repository": "https://github.com/hardimpactdev/laravel-toolbar-site.git",
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

import { projectConfig } from "../../_lib/project_extension.ts";

/** treadmill-website project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-treadmill-website", {
  "projectId": 54,
  "repository": "https://github.com/nckrtl/treadmill-website.git",
  "script": "scripts/dependencies",
  "worker": {
    "composerPaths": [
      ".",
    ],
    "jsPaths": [
      ".",
    ],
    "policy": {},
  },
  "check": "composer test && composer analyse && vp check",
  "defaultBranch": "main",
});

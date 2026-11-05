import { projectConfig } from "../../_lib/project_extension.ts";

/** orbit project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-orbit", {
    "projectId": 46,
    "repository": "https://github.com/hardimpactdev/orbit.git",
    "worker": {
        "composerPaths": [
            "apps/gateway",
        ],
        "jsPaths": [],
        "policy": {
            "apps/gateway": {
                "minimumReleaseAgeDays": 7,
            },
        },
    },
    "check": "composer check",
    "defaultBranch": "main",
});

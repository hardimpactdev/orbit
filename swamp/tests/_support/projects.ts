import projects from "../../extensions/behaviors/operations/project-maintenance/projects.json" with {
    type: "json",
};
import {
    type DependencyProject,
    DependencyProjectSchema,
} from "../../extensions/behaviors/_lib/dependency_project_schema.ts";

export { projects };

/** The source-owned configuration of one Project, as the maintenance input reads it. */
export const projectById = (id: number): DependencyProject =>
    DependencyProjectSchema.parse(projects.find((p) => p.projectId === id));

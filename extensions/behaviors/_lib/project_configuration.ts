import { z } from "npm:zod@4";
import { DependencyProjectSchema } from "./dependency_project_schema.ts";

/** Unversioned resources written before maintenance script retirement are v1. */
const ConfigurationV1Schema = DependencyProjectSchema.extend({
  script: z.string().regex(
    /^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_.-]+)*$/,
  ).refine((value) => !value.split("/").some((p) => p === "." || p === "..")),
});
export const ConfigurationV2Schema = DependencyProjectSchema.extend({
  schema: z.literal(2),
});

/** Validate the old format explicitly; only v1 may contain the retired key. */
export function readProjectConfiguration(value: unknown) {
  const version = value && typeof value === "object"
    ? (value as Record<string, unknown>).schema
    : null;
  if (version === undefined) {
    const { script: _script, ...project } = ConfigurationV1Schema.parse(value);
    return DependencyProjectSchema.parse(project);
  }
  const { schema: _schema, ...project } = ConfigurationV2Schema.parse(value);
  return DependencyProjectSchema.parse(project);
}

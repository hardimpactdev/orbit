import { z } from "npm:zod@4";
import { DependencyProjectSchema } from "./dependency_project_schema.ts";

/** Unversioned resources written before maintenance script retirement are v1. */
const PreviousProjectSchema = DependencyProjectSchema.omit({
  exceptions: true,
});
const ConfigurationV1Schema = PreviousProjectSchema.extend({
  script: z.string().regex(
    /^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_.-]+)*$/,
  ).refine((value) => !value.split("/").some((p) => p === "." || p === "..")),
});
export const ConfigurationV2Schema = PreviousProjectSchema.extend({
  schema: z.literal(2),
});

export const ConfigurationV3Schema = DependencyProjectSchema.extend({
  schema: z.literal(3),
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
  const { schema: _schema, ...project } = version === 2
    ? ConfigurationV2Schema.parse(value)
    : ConfigurationV3Schema.parse(value);
  return DependencyProjectSchema.parse(project);
}

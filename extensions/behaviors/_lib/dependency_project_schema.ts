import { z } from "npm:zod@4";
import { emptyExceptions, ExceptionsSchema } from "./maintenance/exceptions.ts";
import { PolicySchema } from "./maintenance/policy_schema.ts";

const MaintenancePath = z.string().min(1).refine(
  (p) =>
    p === "." ||
    (!p.startsWith("/") && !/^[A-Za-z]:/.test(p) && !p.includes("\\") &&
      !p.split("/").some((part) => ["", ".", ".."].includes(part))),
  "maintenance path must stay inside the repository",
);
const MaintenancePaths = z.array(MaintenancePath).refine(
  (paths) => new Set(paths).size === paths.length,
  "maintenance paths must be unique",
);

export const ProductionSmokeSchema = z.object({
  instance: z.number().int().positive(),
  baseUrl: z.string().url().refine((value) => {
    const url = new URL(value);
    return url.protocol === "https:" && url.origin === value;
  }, "smoke URL must be an HTTPS origin"),
  workflow: z.string().regex(/^\.github\/workflows\/[A-Za-z0-9_-]+\.ya?ml$/),
  ref: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_.\/-]*$/),
  timeoutSeconds: z.number().int().min(30).max(600).default(600),
}).strict();

export const DependencyProjectSchema = z.object({
  exceptions: ExceptionsSchema.default(emptyExceptions),
  productionSmoke: ProductionSmokeSchema.optional(),
  projectId: z.number().int().positive(),
  repository: z.string().min(1),
  worker: z.object({
    composerPaths: MaintenancePaths,
    jsPaths: MaintenancePaths,
    jsPackageManagers: z.record(MaintenancePath, z.enum(["npm", "bun", "pnpm"]))
      .optional(),
    policy: PolicySchema,
  }).strict().refine(
    (v) => v.composerPaths.length + v.jsPaths.length > 0,
    "worker needs maintenance paths",
  ).refine(
    (v) =>
      Object.keys(v.jsPackageManagers ?? {}).every((p) =>
        v.jsPaths.includes(p)
      ),
    "JavaScript manager needs a configured path",
  ).refine(
    (v) =>
      Object.keys(v.policy).every((p) =>
        [...v.composerPaths, ...v.jsPaths].includes(p)
      ),
    "worker policy needs a configured path",
  ),
  check: z.string().min(1),
  defaultBranch: z.string().min(1),
}).strict();
export type DependencyProject = z.infer<typeof DependencyProjectSchema>;
export const DependencyProjectsSchema = z.array(DependencyProjectSchema)
  .superRefine((entries, ctx) => {
    if (new Set(entries.map((p) => p.projectId)).size !== entries.length) {
      ctx.addIssue({
        code: "custom",
        message: "dependency Project IDs must be unique",
      });
    }
  });

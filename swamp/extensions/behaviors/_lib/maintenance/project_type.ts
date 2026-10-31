import { z } from "npm:zod@4";

export const ProjectTypeSchema = z.enum([
  "laravel-app",
  "symfony-app",
  "monorepo",
  "laravel-package",
  "node-package",
]);
export type ProjectType = z.infer<typeof ProjectTypeSchema>;
export const isPackage = (type: ProjectType) =>
  type === "laravel-package" || type === "node-package";

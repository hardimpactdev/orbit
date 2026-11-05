import { z } from "npm:zod@4";

export const ProjectTypeSchema = z.enum([
    "laravel-app",
    "symfony-app",
    "monorepo",
    "laravel-package",
    "node-package",
]);

import { z } from "npm:zod@4.4.3";

export const Container = z.object({
    image: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._/:@-]*$/),
    command: z.array(z.string().max(65536)).min(1).max(4096),
    restart: z.enum(["no", "on-failure", "always", "unless-stopped"]),
    working_directory: z.string().startsWith("/").max(4096).nullable(),
    ports: z.array(z.string()).max(4096),
    mounts: z.array(
        z.object({
            source: z.string().min(1).max(4096),
            target: z.string().startsWith("/"),
            read_only: z.boolean(),
        }).strict(),
    ).max(4096),
    environment: z.record(z.string(), z.string()),
}).strict();

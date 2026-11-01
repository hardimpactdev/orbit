import { z } from "npm:zod@4.4.3";

export const Domain = z.string().regex(/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/)
    .max(253);

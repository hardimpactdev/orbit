import { z } from "npm:zod@4.4.3";

/** Public pointer only. Gateway-owned resolvers hydrate it inside the named transport. */
export const SecretReference = z.object({
  $secret: z.string().regex(/^[a-z][a-z0-9_.-]+$/),
  id: z.number().int().positive(),
}).strict();

export function secretReference(source: string, id: number) {
  return SecretReference.parse({ $secret: source, id });
}

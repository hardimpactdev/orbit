import { z } from "npm:zod@4.4.3";
import { Seed } from "./cleanup_contract.ts";
import { runSqliteLocal, type SqliteInvoke } from "./sqlite_local.ts";

/** Cleanup is the fixed source action; only safe metadata can leave it. */
export function seedCleaner(invoke: SqliteInvoke = runSqliteLocal) {
  return async (
    raw: z.infer<typeof Seed>,
  ): Promise<Record<string, unknown>> => {
    const { target_instance_id: _, variant: __, ...input } = Seed.parse(raw);
    const result = await invoke({
      action: "seedSource",
      input: { ...input, mode: "cleanup" },
    });
    if (result.error_code !== undefined) {
      return {
        error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(
          result.error_code,
        ),
      };
    }
    z.object({}).strict().parse(result);
    return { backend: "absent" };
  };
}
export const cleanupSeed = seedCleaner();

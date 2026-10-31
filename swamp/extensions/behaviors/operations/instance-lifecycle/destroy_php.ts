import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";
import { publishPhp } from "../routes/php.ts";
import { destroySystemRead } from "./destroy_system.ts";

/** Runs inside the canonical Route publication model's native lock. */
export async function publishDestroyPhp(
  nodeId: number,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  signal?: AbortSignal,
  account = destroySystemRead,
  publish = publishPhp,
) {
  let result: Awaited<ReturnType<typeof publishPhp>> | undefined;
  await gateway.withPrivateMaterial(async (material) => {
    if (result !== undefined) return;
    const input = z.object({
      node_id: z.literal(nodeId),
      user: z.string(),
      configurations: z.record(z.string(), z.string()),
    }).parse(material.php);
    const owner = z.object({
      user: z.literal(input.user),
      group: z.string(),
      home: z.string().startsWith("/"),
    }).parse(
      await account({
        name: "account.lookup",
        input: { user: input.user },
        sudo: false,
        mutates: false,
        deadlineMs: 10000,
      }, signal),
    );
    const replacements: Record<string, string> = {
      __ORBIT_ACCOUNT_USER__: owner.user,
      __ORBIT_ACCOUNT_GROUP__: owner.group,
      __ORBIT_ACCOUNT_HOME__: owner.home,
    };
    result = await publish(
      {
        node_id: nodeId,
        configurations: Object.fromEntries(
          Object.entries(input.configurations).map((
            [key, value],
          ) => [
            key,
            value.replace(
              /__ORBIT_ACCOUNT_(?:USER|GROUP|HOME)__/g,
              (token) => replacements[token],
            ),
          ]),
        ),
      },
      undefined,
      signal,
    );
  });
  if (!result) throw new Error("app-dev.php_publication_failed");
  if ("error_code" in result) throw new Error(result.error_code);
  return result;
}

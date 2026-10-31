import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";

export const AccountIntent = z.object({
  origin: z.string().url().refine((raw) => {
    const url = new URL(raw);
    return ["http:", "https:"].includes(url.protocol) &&
      url.username === "" && url.password === "" && url.pathname === "/" &&
      url.search === "" && url.hash === "";
  }),
  account: z.string().max(255).regex(/^[A-Za-z0-9._-]+$/),
  disabled: z.boolean(),
}).strict();
const Material = z.object({
  router: z.object({ management_key: z.string().min(1).max(8192) }).strict(),
});
const Files = z.object({
  files: z.array(z.object({
    name: z.string().min(1).max(4096),
    auth_index: z.string().optional(),
    disabled: z.boolean(),
  })).max(4096),
});
const failed = () => new Error("ai_router.account_update_failed");

/** The caller's native behavior lock encloses this complete read/update/verify. */
export async function updateAiRouterAccount(
  raw: z.infer<typeof AccountIntent>,
  material: Pick<GatewayOperation, "withPrivateMaterial">,
  beforeWrite: () => Promise<void>,
  signal?: AbortSignal,
  request: typeof fetch = fetch,
): Promise<{ account: string; disabled: boolean }> {
  const input = AccountIntent.parse(raw);
  await material.withPrivateMaterial(async (raw) => {
    try {
      const { management_key: key } = Material.parse(raw).router;
      const call = async (path: string, body?: unknown) => {
        const response = await request(new URL(path, input.origin).href, {
          method: body === undefined ? "GET" : "PATCH",
          headers: {
            Authorization: `Bearer ${key}`,
            Accept: "application/json",
            ...(body === undefined
              ? {}
              : { "Content-Type": "application/json" }),
          },
          ...(body === undefined ? {} : { body: JSON.stringify(body) }),
          redirect: "error",
          signal: signal
            ? AbortSignal.any([signal, AbortSignal.timeout(10000)])
            : AbortSignal.timeout(10000),
        });
        if (response.status !== 200) {
          await response.body?.cancel();
          throw failed();
        }
        return response;
      };
      const read = async () => {
        const response = await call("/v0/management/auth-files");
        const reader = response.body?.getReader();
        if (!reader) throw failed();
        const chunks: Uint8Array[] = [];
        let size = 0;
        try {
          for (;;) {
            const { done, value } = await reader.read();
            if (done) break;
            size += value.byteLength;
            if (size > 1024 * 1024) throw failed();
            chunks.push(value);
          }
        } finally {
          await reader.cancel();
        }
        const bytes = new Uint8Array(size);
        let offset = 0;
        for (const chunk of chunks) {
          bytes.set(chunk, offset);
          offset += chunk.length;
        }
        const { files } = Files.parse(
          JSON.parse(new TextDecoder().decode(bytes)),
        );
        const matches = files.filter((file) =>
          (file.auth_index || file.name) === input.account
        );
        if (matches.length !== 1) throw failed();
        return matches[0];
      };
      signal?.throwIfAborted();
      const before = await read();
      if (before.disabled !== input.disabled) {
        await beforeWrite();
        signal?.throwIfAborted();
        const changed = await call("/v0/management/auth-files/status", {
          name: before.name,
          disabled: input.disabled,
        });
        await changed.body?.cancel();
        const after = await read();
        if (after.name !== before.name || after.disabled !== input.disabled) {
          throw failed();
        }
      }
    } catch {
      // A response, transport or validation diagnostic may contain the management key.
      throw failed();
    }
  });
  return { account: input.account, disabled: input.disabled };
}

import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";

export const ModelKeyInput = z.object({
  origin: z.string().url().refine((value) => {
    const url = new URL(value);
    return ["https:", "http:"].includes(url.protocol) &&
      url.username === "" && url.password === "" && url.search === "" &&
      url.hash === "" && url.pathname === "/";
  }),
}).strict();
export type ModelKeyIntent = z.infer<typeof ModelKeyInput>;
type Material = Pick<GatewayOperation, "withPrivateMaterial">;
const Key = z.string().regex(/^[a-f0-9]{64}$/);
const Private = z.object({
  model_key: Key,
  model_router: z.object({
    management_key: z.string().min(1).max(8192),
    anchor_key: Key,
  }),
}).refine((input) => input.model_key !== input.model_router.anchor_key);
const failed = () => new Error("task_vm.model_key_failed");

/** Keys stay inside the private callback. The owning native step supplies retry policy. */
async function modelKey(
  raw: ModelKeyIntent,
  action: "issue" | "revoke",
  guard: () => Promise<void>,
  privateMaterial: Material,
  signal: AbortSignal | undefined,
  fetcher: typeof fetch,
): Promise<void> {
  try {
    const input = ModelKeyInput.parse(raw);
    const origin = new URL(input.origin).origin;
    signal?.throwIfAborted();
    await guard();
    await privateMaterial.withPrivateMaterial(async (raw) => {
      const { model_key: key, model_router: router } = Private.parse(raw);
      const request = async (
        path: "/v0/management/api-keys" | "/v1/models",
        token: string,
        body?: { old: string; new: string } | string[],
      ) => {
        signal?.throwIfAborted();
        await guard();
        return await fetcher(origin + path, {
          method: body === undefined
            ? "GET"
            : Array.isArray(body)
            ? "PUT"
            : "PATCH",
          headers: {
            Accept: "application/json",
            ...(token === "" ? {} : { Authorization: `Bearer ${token}` }),
            ...(body ? { "Content-Type": "application/json" } : {}),
          },
          ...(body ? { body: JSON.stringify(body) } : {}),
          redirect: "error",
          signal: signal
            ? AbortSignal.any([signal, AbortSignal.timeout(5000)])
            : AbortSignal.timeout(5000),
        });
      };
      const keys = async () => {
        const response = await request(
          "/v0/management/api-keys",
          router.management_key,
        );
        if (response.status !== 200) {
          await response.body?.cancel();
          throw failed();
        }
        const reader = response.body?.getReader();
        let body = "";
        if (reader) {
          const decoder = new TextDecoder("utf-8", { fatal: true });
          let size = 0;
          try {
            for (;;) {
              const { value, done } = await reader.read();
              if (done) break;
              size += value.length;
              if (size > 1024 * 1024) throw failed();
              body += decoder.decode(value, { stream: true });
            }
            body += decoder.decode();
          } finally {
            await reader.cancel();
          }
        }
        return z.object({ "api-keys": z.array(z.string()).max(4096) })
          .parse(JSON.parse(body))["api-keys"];
      };
      const patch = async (old: string, replacement: string) => {
        // The permanent anchor can only be added, never removed or replaced.
        if (old === router.anchor_key && replacement !== router.anchor_key) {
          throw failed();
        }
        const response = await request(
          "/v0/management/api-keys",
          router.management_key,
          { old, new: replacement },
        );
        await response.body?.cancel();
        if (response.status !== 200) throw failed();
      };
      const status = async (token: string, expected: 200 | 401) => {
        const response = await request("/v1/models", token);
        await response.body?.cancel();
        if (response.status !== expected) throw failed();
      };
      const initial = await keys();
      if (!initial.includes(router.anchor_key)) {
        await patch(router.anchor_key, router.anchor_key);
      }
      const anchored = await keys();
      const expectedAnchor = initial.includes(router.anchor_key)
        ? initial
        : [...initial, router.anchor_key];
      if (JSON.stringify(anchored) !== JSON.stringify(expectedAnchor)) {
        throw failed();
      }
      await status(router.anchor_key, 200);
      await status("", 401);
      const before = await keys();
      if (!before.includes(router.anchor_key)) throw failed();
      const present = before.includes(key);
      if (action === "issue" && !present) await patch(key, key);
      const expected = action === "issue"
        ? present ? before : [...before, key]
        : before.filter((value) => value !== key);
      if (action === "revoke" && present) {
        // The parent serializes router-key steps. PUT removes duplicates without URL secrets or empty PATCH tombstones.
        const response = await request(
          "/v0/management/api-keys",
          router.management_key,
          expected,
        );
        await response.body?.cancel();
        if (response.status !== 200) throw failed();
      }
      const final = await keys();
      if (
        !final.includes(router.anchor_key) ||
        JSON.stringify(final) !== JSON.stringify(expected)
      ) throw failed();
      await status(key, action === "issue" ? 200 : 401);
      await status(router.anchor_key, 200);
      await status("", 401);
    });
  } catch {
    // Neither upstream response bodies nor validation errors can expose private keys.
    throw failed();
  }
}

export async function issueTaskVmModelKey(
  input: ModelKeyIntent,
  guard: () => Promise<void>,
  privateMaterial: Material,
  signal?: AbortSignal,
  request: typeof fetch = fetch,
): Promise<{ issued: true }> {
  await modelKey(input, "issue", guard, privateMaterial, signal, request);
  return { issued: true };
}

export async function revokeTaskVmModelKey(
  input: ModelKeyIntent,
  guard: () => Promise<void>,
  privateMaterial: Material,
  signal?: AbortSignal,
  request: typeof fetch = fetch,
): Promise<{ revoked: true }> {
  await modelKey(input, "revoke", guard, privateMaterial, signal, request);
  return { revoked: true };
}

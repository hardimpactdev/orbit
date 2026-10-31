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
type Clock = {
  now: () => number;
  pause: (milliseconds: number) => Promise<void>;
};
const clock: Clock = {
  now: () => performance.now(),
  pause: (milliseconds) =>
    new Promise((resolve) => setTimeout(resolve, milliseconds)),
};

/** Keys stay inside the private callback. The owning native step supplies retry policy. */
async function modelKey(
  raw: ModelKeyIntent,
  action: "issue" | "revoke",
  guard: () => Promise<void>,
  privateMaterial: Material,
  signal: AbortSignal | undefined,
  fetcher: typeof fetch,
  time: Clock,
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
        deadline?: number,
      ) => {
        signal?.throwIfAborted();
        await guard();
        const remaining = deadline === undefined
          ? 5000
          : Math.min(5000, deadline - time.now());
        if (remaining <= 0) throw failed();
        const timeoutMs = Math.max(1, Math.floor(remaining));
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
            ? AbortSignal.any([signal, AbortSignal.timeout(timeoutMs)])
            : AbortSignal.timeout(timeoutMs),
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
      const status = async (
        token: string,
        expected: 200 | 401,
        settle = false,
      ) => {
        const deadline = time.now() + 2000;
        for (;;) {
          const remaining = settle ? deadline - time.now() : 5000;
          if (remaining <= 0) throw failed();
          const response = await request(
            "/v1/models",
            token,
            undefined,
            settle ? deadline : undefined,
          );
          await response.body?.cancel();
          if (response.status === expected) return;
          // Only the old access-provider answer may settle after a successful list update.
          if (!settle || response.status !== (expected === 200 ? 401 : 200)) {
            throw failed();
          }
          signal?.throwIfAborted();
          const left = deadline - time.now();
          if (left <= 0) throw failed();
          await time.pause(Math.min(100, left));
        }
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
      await status(
        router.anchor_key,
        200,
        !initial.includes(router.anchor_key),
      );
      await status("", 401);
      const before = await keys();
      if (!before.includes(router.anchor_key)) throw failed();
      const present = before.includes(key);
      if (action === "issue" && !present) await patch(key, key);
      const expected = action === "issue"
        ? present ? before : [...before, key]
        : before.filter((value) => value !== key);
      if (action === "revoke" && present) {
        // The shared behavior lock serializes router keys. PUT removes duplicates without URL secrets or empty PATCH tombstones.
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
      await status(key, action === "issue" ? 200 : 401, true);
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
  time: Clock = clock,
): Promise<{ issued: true }> {
  await modelKey(input, "issue", guard, privateMaterial, signal, request, time);
  return { issued: true };
}

export async function revokeTaskVmModelKey(
  input: ModelKeyIntent,
  guard: () => Promise<void>,
  privateMaterial: Material,
  signal?: AbortSignal,
  request: typeof fetch = fetch,
  time: Clock = clock,
): Promise<{ revoked: true }> {
  await modelKey(
    input,
    "revoke",
    guard,
    privateMaterial,
    signal,
    request,
    time,
  );
  return { revoked: true };
}

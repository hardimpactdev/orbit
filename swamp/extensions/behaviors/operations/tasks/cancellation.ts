import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";

function delay(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve) => {
    const done = () => {
      clearTimeout(timer);
      signal.removeEventListener("abort", done);
      resolve();
    };
    const timer = setTimeout(done, ms);
    signal.addEventListener("abort", done, { once: true });
    if (signal.aborted) done();
  });
}
/** Observe cancellation intent while the native method owns its effect. Never dispatch or retry work. */
export async function withTaskCancellation<T>(
  gateway: Pick<GatewayOperation, "read">,
  action: (signal: AbortSignal) => Promise<T>,
  signal?: AbortSignal,
  pause = delay,
): Promise<T> {
  const controller = new AbortController();
  const ended = new AbortController();
  const effectSignal = signal
    ? AbortSignal.any([signal, controller.signal])
    : controller.signal;
  let unreadable = false;
  const watching = (async () => {
    while (!ended.signal.aborted && !effectSignal.aborted) {
      await pause(2000, ended.signal);
      if (ended.signal.aborted) return;
      try {
        const current = z.object({ cancel_requested: z.boolean() }).parse(
          await gateway.read(),
        );
        if (current.cancel_requested) controller.abort();
      } catch {
        unreadable = true;
        controller.abort();
      }
    }
  })();
  try {
    const result = await action(effectSignal);
    return result;
  } catch (error) {
    if (unreadable) {
      throw new Error("Task cancellation observation unavailable");
    }
    throw error;
  } finally {
    ended.abort();
    await watching;
  }
}

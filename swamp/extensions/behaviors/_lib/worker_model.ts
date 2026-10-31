import {
  invokeNativeModel,
  type NativeModelRequest,
} from "../native_host_method.ts";

export type ModelInvocation = (request: NativeModelRequest) => Promise<{
  ok: boolean;
  error?: { message: string };
}>;

export interface WorkerModelContext {
  signal?: AbortSignal;
  /** Fixture dependency; Swamp's production context does not provide this. */
  invokeModel?: unknown;
}

export async function callWorkerModel(
  context: WorkerModelContext,
  request: NativeModelRequest,
): ReturnType<ModelInvocation> {
  if (context.invokeModel !== undefined) {
    if (typeof context.invokeModel !== "function") {
      throw new TypeError("invalid native model fixture");
    }
    return await (context.invokeModel as ModelInvocation)(request);
  }
  return await invokeNativeModel(request, context.signal);
}

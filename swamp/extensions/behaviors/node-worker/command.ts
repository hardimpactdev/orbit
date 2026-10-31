/** Code-owned command runner supplied by the native parent. No transport selection or admission. */
export type NodeCommand = (
  command: readonly string[],
  stdin: Uint8Array | undefined,
  as_user: string,
  options?: {
    signal?: AbortSignal;
    deadlineMs?: number;
    maxOutputBytes?: number;
  },
) => Promise<{ success: boolean; code: number; stdout: Uint8Array }>;

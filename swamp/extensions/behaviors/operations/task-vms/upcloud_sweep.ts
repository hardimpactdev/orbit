import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";

// This provider client remains only for the unported UpCloud image builder.
export type CloudRequest = (
    method: "GET" | "POST" | "PUT" | "DELETE",
    path: string,
    body?: Record<string, unknown>,
    missing?: boolean,
) => Promise<Record<string, unknown> | null>;
const Origin = "https://api.upcloud.com/1.3/";

/** Fixed provider origin; private material never enters resources, errors, argv or request URLs. */
export function cloudRequest(
    gateway: Pick<GatewayOperation, "withPrivateMaterial">,
    signal?: AbortSignal,
    fetcher = fetch,
): CloudRequest {
    return async (method, path, body, missing = false) => {
        let refused = false;
        let answer: Record<string, unknown> | null | undefined;
        await gateway.withPrivateMaterial(async (material) => {
            const token = z.string().regex(/^ucat_[A-Za-z0-9_-]+$/).parse(
                material.upcloud_token,
            );
            const response = await fetcher(Origin + path, {
                method,
                headers: {
                    Authorization: `Bearer ${token}`,
                    Accept: "application/json",
                    ...(body ? { "Content-Type": "application/json" } : {}),
                },
                ...(body ? { body: JSON.stringify(body) } : {}),
                redirect: "error",
                signal: signal
                    ? AbortSignal.any([signal, AbortSignal.timeout(60000)])
                    : AbortSignal.timeout(60000),
            });
            if (missing && response.status === 404) {
                await response.body?.cancel();
                answer = null;
                return;
            }
            if (!response.ok) {
                await response.body?.cancel();
                refused = true;
                return;
            }
            const reader = response.body?.getReader();
            const parts: Uint8Array[] = [];
            let size = 0;
            if (reader) {
                try {
                    for (;;) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        size += value.length;
                        if (size > 4 * 1024 * 1024) {
                            throw new Error("task_vm.host_command_failed");
                        }
                        parts.push(value);
                    }
                } finally {
                    await reader.cancel();
                }
            }
            const bytes = new Uint8Array(size);
            let offset = 0;
            for (const part of parts) {
                bytes.set(part, offset);
                offset += part.length;
            }
            const text = new TextDecoder("utf-8", { fatal: true }).decode(
                bytes,
            );
            answer = text.trim() === ""
                ? {}
                : z.record(z.string(), z.unknown()).parse(JSON.parse(text));
        });
        if (refused || answer === undefined) {
            throw new Error("task_vm.host_command_failed");
        }
        return answer;
    };
}

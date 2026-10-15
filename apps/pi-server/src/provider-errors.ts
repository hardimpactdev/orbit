import type { StreamFn } from "@earendil-works/pi-agent-core";
import { createAssistantMessageEventStream } from "@earendil-works/pi-ai";
import { classifyProviderFailure, type ProviderError } from "./state.ts";

const MAX_ERROR_BYTES = 64 * 1024;

/** Preserve structured Responses API evidence before SDK error-message serialization. */
export function withProviderErrors(stream: StreamFn): StreamFn {
    return async (model, context, options) => {
        if (model.api !== "openai-codex-responses" && model.api !== "openai-responses")
            return stream(model, context, options);

        // Scoped to this model request, not a session or global fetch: concurrent turns cannot mix errors.
        let category: ProviderError | null | undefined;
        const fetch = options?.fetch ?? globalThis.fetch;
        const source = await stream(model, context, {
            ...options,
            // Codex WebSocket errors have no public status/code hook in the pinned SDK.
            // The regular Responses adapter already uses SSE; preserve its options.
            ...(model.api === "openai-codex-responses" ? { transport: "sse" as const } : {}),
            fetch: async (input, init) => {
                const response = await fetch(input, init);
                category = undefined;
                if (!response.ok) {
                    const body = await errorBody(response.clone());
                    category = classifyProviderFailure({
                        status: response.status,
                        ...errorRecord(parseJson(body)),
                    });
                    return response;
                }
                if (response.body === null) return response;
                const observe = sseErrors((error) => {
                    // The SDK stops at the first terminal error, even if this chunk has more records.
                    if (category === undefined)
                        category = classifyProviderFailure(errorRecord(error));
                });
                return new Response(response.body.pipeThrough(observe), {
                    status: response.status,
                    statusText: response.statusText,
                    headers: response.headers,
                });
            },
        });
        const output = createAssistantMessageEventStream();
        void (async () => {
            for await (const event of source) {
                if (
                    event.type === "error" &&
                    event.error.stopReason === "error" &&
                    category !== undefined
                ) {
                    // SessionManager persists this sanitized field with the assistant message.
                    Object.assign(event.error, { orbitProviderError: category });
                }
                output.push(event);
            }
            output.end();
        })();
        return output;
    };
}

function parseJson(text: string): unknown {
    try {
        return JSON.parse(text);
    } catch {
        return undefined;
    }
}

function record(value: unknown): Record<string, unknown> {
    return typeof value === "object" && value !== null ? (value as Record<string, unknown>) : {};
}

function errorRecord(value: unknown): { code: unknown; message: string } {
    const root = record(value);
    const error = root.error === undefined ? root : record(root.error);
    return {
        code: error.code ?? error.type ?? error.status,
        message: typeof error.message === "string" ? error.message : "",
    };
}

/** Bound inspection without consuming the SDK's copy or waiting for clone cancellation. */
async function errorBody(response: Response): Promise<string> {
    const reader = response.body?.getReader();
    if (reader === undefined) return "";
    const decoder = new TextDecoder();
    let text = "";
    let bytes = 0;
    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done) return text + decoder.decode();
            bytes += value.byteLength;
            if (bytes > MAX_ERROR_BYTES) return "";
            text += decoder.decode(value, { stream: true });
        }
    } finally {
        void reader.cancel().catch(() => {});
    }
}

/** Inspect only protocol error records; forward every byte unchanged, including tool/text deltas. */
function sseErrors(onError: (error: unknown) => void): TransformStream<Uint8Array, Uint8Array> {
    const decoder = new TextDecoder();
    let pending = "";
    let oversized = false;
    const inspect = (frame: string) => {
        if (frame.length > MAX_ERROR_BYTES) return;
        const data = frame
            .split(/\r?\n/)
            .filter((line) => line.startsWith("data:"))
            .map((line) => line.slice(5).trimStart())
            .join("\n");
        const event = record(parseJson(data));
        if (event.type === "error") onError(event);
        if (event.type === "response.failed") onError(record(event.response));
    };
    return new TransformStream({
        transform(chunk, controller) {
            pending += decoder.decode(chunk, { stream: true });
            let boundary: RegExpExecArray | null;
            while ((boundary = /\r?\n\r?\n/.exec(pending)) !== null) {
                const frame = pending.slice(0, boundary.index);
                pending = pending.slice(boundary.index + boundary[0].length);
                if (!oversized) inspect(frame);
                oversized = false;
            }
            if (pending.length > MAX_ERROR_BYTES) {
                pending = pending.slice(-3);
                oversized = true;
            }
            controller.enqueue(chunk);
        },
        flush() {
            // The pinned SDK treats EOF as the end of a residual SSE record.
            if (!oversized) inspect(pending + decoder.decode());
        },
    });
}

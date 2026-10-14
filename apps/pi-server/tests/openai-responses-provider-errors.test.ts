import { describe, expect, it, vi } from "vite-plus/test";
import { type Model, normalizeContext } from "@earendil-works/pi-ai";
import { stream } from "@earendil-works/pi-ai/api/openai-responses";
import { withProviderErrors } from "../src/provider-errors.ts";
import { deriveState } from "../src/state.ts";

// Match TaskVmRuntime's deployed API/provider, not the separate Codex adapter.
const model: Model<"openai-responses"> = {
    id: "fixture-model",
    name: "fixture",
    provider: "orbit-task-vm",
    api: "openai-responses",
    baseUrl: "https://fixture.invalid/v1",
    reasoning: false,
    input: ["text"],
    cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0 },
    contextWindow: 128000,
    maxTokens: 1000,
};
const context = normalizeContext({
    messages: [{ role: "user", content: "fixture", timestamp: 0 }],
});
const cases = [
    { code: "server_error", message: "Servers overloaded", category: "server_error" },
    { code: "server_overloaded", message: "Servers overloaded", category: "overloaded" },
    { code: "rate_limit_exceeded", message: "Too many requests", category: "rate_limited" },
    { code: "insufficient_quota", message: "Servers overloaded", category: null },
    { code: "invalid_api_key", message: "Servers overloaded", category: null },
    { code: "billing_error", message: "Servers overloaded", category: null },
    { code: "content_filter", message: "Servers overloaded", category: null },
];

describe.each(["error", "response.failed"])("task-VM openai-responses %s", (type) => {
    it.each(cases)("preserves $code evidence through the real pinned SDK", async (fixture) => {
        const error = { code: fixture.code, message: fixture.message };
        const event =
            type === "error" ? { type, ...error } : { type, response: { status: "failed", error } };
        const fetch = vi.fn(
            async () =>
                new Response(`data: ${JSON.stringify(event)}\n\n`, {
                    headers: { "content-type": "text/event-stream" },
                }),
        );
        const wrapped = withProviderErrors((_model, transcript, options) => {
            expect(options?.transport).toBe("auto");
            return stream(model, transcript, options);
        });
        const events = await wrapped(model, context, {
            apiKey: "fixture-key",
            transport: "auto",
            maxRetries: 0,
            fetch,
        });
        const message = await events.result();
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(message.stopReason).toBe("error");
        // Assert the actual SDK formatting that defeated the text-only classifier.
        expect(message.errorMessage).toBe(
            type === "error"
                ? `Error Code ${fixture.code}: ${fixture.message}`
                : `${fixture.code}: ${fixture.message}`,
        );
        const persisted = JSON.parse(JSON.stringify(message));
        expect(persisted.orbitProviderError).toBe(fixture.category);
        expect(
            deriveState({ working: false, interruptedByRestart: false, lastAssistant: persisted })
                .providerError,
        ).toBe(fixture.category ?? undefined);
    });
});

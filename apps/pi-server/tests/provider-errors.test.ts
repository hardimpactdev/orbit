import { describe, expect, it, vi } from "vite-plus/test";
import { type Model, normalizeContext } from "@earendil-works/pi-ai";
import { stream } from "@earendil-works/pi-ai/api/openai-codex-responses";
import { withProviderErrors } from "../src/provider-errors.ts";
import { deriveState } from "../src/state.ts";

const model: Model<"openai-codex-responses"> = {
    id: "gpt-5.4",
    name: "fixture",
    provider: "openai-codex",
    api: "openai-codex-responses",
    baseUrl: "https://fixture.invalid/backend-api",
    reasoning: false,
    input: ["text"],
    cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0 },
    contextWindow: 128000,
    maxTokens: 1000,
};
const token = `fixture.${btoa(JSON.stringify({ "https://api.openai.com/auth": { chatgpt_account_id: "fixture" } }))}.fixture`;
const context = normalizeContext({
    messages: [{ role: "user", content: "fixture", timestamp: 0 }],
});
const body = (code: string, message: string) => JSON.stringify({ error: { code, message } });
const sse = (event: unknown) => `data: ${JSON.stringify(event)}\n\n`;

// These responses exercise the installed SDK, including its lossy friendly-message conversion.
const cases = [
    {
        name: "HTTP 502 text",
        status: 502,
        body: "Bad Gateway",
        category: "server_error",
        message: "Bad Gateway",
    },
    {
        name: "HTTP 503 overload",
        status: 503,
        body: body("server_overloaded", "Capacity temporarily unavailable. Try again later."),
        category: "overloaded",
        message: "Capacity temporarily unavailable. Try again later.",
    },
    {
        name: "HTTP 429 temporary rate limit",
        status: 429,
        body: body("rate_limit_exceeded", "Too many requests"),
        category: "rate_limited",
        message: "You have hit your ChatGPT usage limit.",
    },
    {
        name: "SSE error",
        status: 200,
        body: sse({ type: "error", code: "server_overloaded", message: "Servers overloaded" }),
        category: "overloaded",
        message: "Codex error: Servers overloaded",
    },
    {
        name: "SSE response.failed",
        status: 200,
        body: sse({
            type: "response.failed",
            response: { error: { code: "server_overloaded", message: "Try again later." } },
        }),
        category: "overloaded",
        message: "Try again later.",
    },
    {
        name: "SSE nested error",
        status: 200,
        body: sse({
            type: "error",
            error: { code: "server_overloaded", message: "Capacity gone" },
        }),
        category: "overloaded",
        message: "Codex error: Capacity gone",
    },
    {
        name: "SSE error terminated by EOF",
        status: 200,
        body: `data: ${JSON.stringify({ type: "error", code: "server_overloaded", message: "Capacity gone" })}`,
        category: "overloaded",
        message: "Codex error: Capacity gone",
    },
    {
        name: "SSE first terminal error wins",
        status: 200,
        body:
            sse({ type: "error", code: "content_filter", message: "Refused" }) +
            sse({ type: "error", code: "server_overloaded", message: "Capacity gone" }),
        category: null,
        message: "Codex error: Refused",
    },
    {
        name: "HTTP exhausted quota",
        status: 429,
        body: body("insufficient_quota", "Quota exhausted"),
        category: null,
    },
    {
        name: "HTTP subscription usage limit",
        status: 429,
        body: body("usage_limit_reached", "Too many requests"),
        category: null,
    },
    {
        name: "HTTP usage not included",
        status: 429,
        body: body("usage_not_included", "Too many requests"),
        category: null,
    },
    {
        name: "HTTP auth with misleading overload text",
        status: 401,
        body: body("invalid_api_key", "Servers overloaded"),
        category: null,
    },
    {
        name: "HTTP billing on 503",
        status: 503,
        body: body("billing_error", "Servers overloaded"),
        category: null,
    },
    {
        name: "SSE refusal with misleading overload text",
        status: 200,
        body: sse({ type: "error", code: "content_filter", message: "Servers overloaded" }),
        category: null,
    },
];

describe("Codex provider-boundary errors", () => {
    it.each(cases)("preserves $name through the pinned SDK", async (fixture) => {
        const fetch = vi.fn(
            async () =>
                new Response(fixture.body, {
                    status: fixture.status,
                    headers: {
                        "content-type": fixture.status === 200 ? "text/event-stream" : "text/plain",
                    },
                }),
        );
        const wrapped = withProviderErrors((_model, transcript, options) =>
            stream(model, transcript, options),
        );
        const events = await wrapped(model, context, { apiKey: token, maxRetries: 0, fetch });
        const message = await events.result();
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(message.stopReason).toBe("error");
        if (fixture.message !== undefined) expect(message.errorMessage).toBe(fixture.message);
        const persisted = JSON.parse(JSON.stringify(message));
        expect(persisted.orbitProviderError).toBe(fixture.category);
        expect(
            deriveState({ working: false, interruptedByRestart: false, lastAssistant: persisted })
                .providerError,
        ).toBe(fixture.category ?? undefined);
    });

    it("handles SSE records split across byte chunks and forces the observable transport", async () => {
        const bytes = new TextEncoder().encode(
            sse({ type: "error", code: "server_overloaded", message: "Capacity gone" }),
        );
        const wrapped = withProviderErrors((_model, transcript, options) => {
            expect(options?.transport).toBe("sse");
            return stream(model, transcript, options);
        });
        const events = await wrapped(model, context, {
            apiKey: token,
            maxRetries: 0,
            transport: "websocket",
            fetch: async () =>
                new Response(
                    new ReadableStream({
                        start(controller) {
                            for (let offset = 0; offset < bytes.length; offset += 7)
                                controller.enqueue(bytes.slice(offset, offset + 7));
                            controller.close();
                        },
                    }),
                    { headers: { "content-type": "text/event-stream" } },
                ),
        });
        expect(
            deriveState({
                working: false,
                interruptedByRestart: false,
                lastAssistant: await events.result(),
            }).providerError,
        ).toBe("overloaded");
    });
});

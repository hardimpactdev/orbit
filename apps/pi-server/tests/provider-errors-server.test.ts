import { afterEach, expect, it, vi } from "vite-plus/test";
import { openaiCodexProvider } from "@earendil-works/pi-ai/providers/openai-codex";
import { writeFileSync } from "node:fs";
import { join } from "node:path";
import { type Harness, startHarness, waitFor } from "./support.ts";

let harness: Harness | undefined;
afterEach(async () => {
    vi.restoreAllMocks();
    await harness?.close();
    harness = undefined;
});

it("publishes and reloads real Codex provider evidence rather than the SDK's friendly message", async () => {
    harness = await startHarness({ nativeProviders: [openaiCodexProvider()] });
    const h = harness;
    const token = `fixture.${btoa(JSON.stringify({ "https://api.openai.com/auth": { chatgpt_account_id: "fixture" } }))}.fixture`;
    writeFileSync(
        join(h.root, "agent", "auth.json"),
        JSON.stringify({
            "openai-codex": {
                type: "oauth",
                access: token,
                refresh: "fixture",
                expires: Date.now() + 86_400_000,
            },
        }),
    );
    await h.modelRuntime.refresh({ allowNetwork: false });
    const model = h.modelRuntime.getModel("openai-codex", "gpt-5.5");
    expect(model).toBeDefined();
    model!.baseUrl = "https://fixture.invalid/backend-api";

    const actualFetch = globalThis.fetch;
    const providerFetch = vi.fn(
        async () =>
            new Response(
                JSON.stringify({
                    error: {
                        code: "server_overloaded",
                        message: "Capacity temporarily unavailable. Try again later.",
                    },
                }),
                { status: 503 },
            ),
    );
    vi.spyOn(globalThis, "fetch").mockImplementation((input, init) => {
        const url = input instanceof Request ? input.url : String(input);
        if (url.startsWith((harness?.url ?? h.url) + "/")) return actualFetch(input, init);
        expect(url).toContain("fixture.invalid");
        return providerFetch();
    });
    const stream = h.modelRuntime.streamSimple.bind(h.modelRuntime);
    vi.spyOn(h.modelRuntime, "streamSimple").mockImplementation((model, context, options) =>
        stream(model, context, { ...options, maxRetries: 0 }),
    );

    expect(
        (
            await h.request("POST", "/sessions", {
                id: "codex-error",
                cwd: h.workspace,
                model: "openai-codex/gpt-5.5",
                thinkingLevel: "off",
                appendSystemPrompt: null,
            })
        ).status,
    ).toBe(201);
    const streamed = h.stream("codex-error", (events) =>
        events.some((event) => event.state === "failed"),
    );
    expect(
        (await h.request("POST", "/sessions/codex-error/messages", { key: "k1", text: "go" }))
            .status,
    ).toBe(202);
    const events = await streamed;
    expect(events.find((event) => event.state === "failed")?.providerError).toBe("overloaded");
    const snapshot = await waitFor(
        async () => (await h.request("GET", "/sessions/codex-error")).body,
        (snapshot) => snapshot.state === "failed",
    );
    expect(snapshot.providerError).toBe("overloaded");
    expect(snapshot.error).toBe("Capacity temporarily unavailable. Try again later.");
    expect(providerFetch).toHaveBeenCalledTimes(1);

    harness = await h.restart();
    const reloaded = (await harness.request("GET", "/sessions/codex-error")).body;
    expect(reloaded.state).toBe("failed");
    expect(reloaded.providerError).toBe("overloaded");
    expect(reloaded.turnId).toBe("k1");
});

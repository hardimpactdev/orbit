import { SettingsManager } from "@earendil-works/pi-coding-agent";
import { afterEach, expect, it, vi } from "vite-plus/test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { type Harness, startHarness, waitFor } from "./support.ts";

let harness: Harness | undefined;
let root: string | undefined;
afterEach(async () => {
    vi.restoreAllMocks();
    await harness?.close();
    if (root !== undefined) rmSync(root, { recursive: true, force: true });
    harness = undefined;
    root = undefined;
});

it("publishes and reloads streamed task-VM provider failures using the deployed models.json API", async () => {
    root = mkdtempSync(join(tmpdir(), "pi-task-vm-errors-"));
    mkdirSync(join(root, "agent"));
    const modelsPath = join(root, "agent", "models.json");
    // Same provider configuration shape as TaskVmRuntime::models().
    writeFileSync(
        modelsPath,
        JSON.stringify({
            providers: {
                "orbit-task-vm": {
                    baseUrl: "https://fixture.invalid/v1",
                    api: "openai-responses",
                    apiKey: "fixture-key",
                    models: [{ id: "fixture-model", reasoning: true }],
                },
            },
        }),
    );
    const inMemory = SettingsManager.inMemory.bind(SettingsManager);
    vi.spyOn(SettingsManager, "inMemory").mockImplementation((settings) =>
        inMemory({ ...settings, retry: { enabled: false } }),
    );
    harness = await startHarness({
        root,
        modelsPath,
        allowApiKeys: false,
        allowedProviders: ["orbit-task-vm"],
    });
    const h = harness;
    const actualFetch = globalThis.fetch;
    const providerFetch = vi.fn(
        async () =>
            new Response(
                `data: ${JSON.stringify({
                    type: "response.failed",
                    response: {
                        status: "failed",
                        error: { code: "server_error", message: "Servers overloaded" },
                    },
                })}\n\n`,
                { headers: { "content-type": "text/event-stream" } },
            ),
    );
    vi.spyOn(globalThis, "fetch").mockImplementation((input, init) => {
        const url = input instanceof Request ? input.url : String(input);
        if (url.startsWith((harness?.url ?? h.url) + "/")) return actualFetch(input, init);
        expect(url).toBe("https://fixture.invalid/v1/responses");
        return providerFetch();
    });
    const stream = h.modelRuntime.streamSimple.bind(h.modelRuntime);
    vi.spyOn(h.modelRuntime, "streamSimple").mockImplementation((model, context, options) =>
        stream(model, context, { ...options, maxRetries: 0 }),
    );
    expect(
        (
            await h.request("POST", "/sessions", {
                id: "task-vm-error",
                cwd: h.workspace,
                model: "orbit-task-vm/fixture-model",
                thinkingLevel: "off",
                appendSystemPrompt: null,
            })
        ).status,
    ).toBe(201);
    const streamed = h.stream("task-vm-error", (events) =>
        events.some((event) => event.state === "failed"),
    );
    expect(
        (await h.request("POST", "/sessions/task-vm-error/messages", { key: "k1", text: "go" }))
            .status,
    ).toBe(202);
    const events = await streamed;
    expect(events.find((event) => event.state === "failed")?.providerError).toBe("server_error");
    const snapshot = await waitFor(
        async () => (await h.request("GET", "/sessions/task-vm-error")).body,
        (snapshot) => snapshot.state === "failed",
    );
    expect(snapshot.providerError).toBe("server_error");
    expect(snapshot.error).toBe("server_error: Servers overloaded");
    expect(providerFetch).toHaveBeenCalledTimes(1);
    harness = await h.restart();
    const reloaded = (await harness.request("GET", "/sessions/task-vm-error")).body;
    expect(reloaded.state).toBe("failed");
    expect(reloaded.providerError).toBe("server_error");
    expect(reloaded.turnId).toBe("k1");
});

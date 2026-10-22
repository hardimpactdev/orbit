import { afterEach, expect, it, vi } from "vite-plus/test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { PROVIDER_RETRY_POLICY } from "../src/registry.ts";
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

async function taskVmHarness(retry = false, compaction = false) {
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
                    models: [
                        {
                            id: "fixture-model",
                            reasoning: true,
                            ...(compaction ? { contextWindow: 1000 } : {}),
                        },
                    ],
                },
            },
        }),
    );
    harness = await startHarness({
        root,
        modelsPath,
        allowApiKeys: false,
        allowedProviders: ["orbit-task-vm"],
        harnessSettings: {
            retry: { ...PROVIDER_RETRY_POLICY, enabled: retry, baseDelayMs: 5 },
            ...(compaction
                ? {
                      compaction: {
                          enabled: true,
                          reserveTokens: 100,
                          keepRecentTokens: 50,
                          backgroundTokens: 0,
                      },
                  }
                : {}),
        },
    });
    return harness;
}

function provider(h: Harness, responses: unknown[]) {
    const actualFetch = globalThis.fetch;
    let request = 0;
    const providerFetch = vi.fn(async () => {
        const response = responses[Math.min(request++, responses.length - 1)];
        const events = Array.isArray(response) ? response : [response];
        return new Response(events.map((event) => `data: ${JSON.stringify(event)}\n\n`).join(""), {
            headers: { "content-type": "text/event-stream" },
        });
    });
    vi.spyOn(globalThis, "fetch").mockImplementation((input, init) => {
        const url = input instanceof Request ? input.url : String(input);
        if (url.startsWith((harness?.url ?? h.url) + "/")) return actualFetch(input, init);
        expect(url).toBe("https://fixture.invalid/v1/responses");
        return providerFetch();
    });
    return providerFetch;
}

function failure(code: string, message = "Servers overloaded") {
    return { type: "response.failed", response: { status: "failed", error: { code, message } } };
}

const completed = {
    type: "response.completed",
    response: {
        status: "completed",
        output: [
            {
                type: "message",
                role: "assistant",
                id: "msg_fixture",
                content: [{ type: "output_text", text: "recovered", annotations: [] }],
            },
        ],
        usage: { input_tokens: 1, output_tokens: 1, total_tokens: 2 },
    },
};

const success = [
    { type: "response.output_item.done", output_index: 0, item: completed.response.output[0] },
    completed,
];

async function create(h: Harness) {
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
}

async function send(h: Harness) {
    expect(
        (await h.request("POST", "/sessions/task-vm-error/messages", { key: "k1", text: "go" }))
            .status,
    ).toBe(202);
}

it("publishes and reloads streamed task-VM provider failures using the deployed models.json API", async () => {
    const h = await taskVmHarness();
    const providerFetch = provider(h, [failure("server_error")]);
    await create(h);
    const streamed = h.stream("task-vm-error", (events) =>
        events.some((event) => event.state === "failed"),
    );
    await send(h);
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

it.each([
    "invalid_api_key",
    "content_filter",
    "insufficient_quota",
    "refusal",
    "permission_denied",
])("does not retry structured %s despite misleading overload text", async (code) => {
    const h = await taskVmHarness(true);
    const providerFetch = provider(h, [failure(code)]);
    await create(h);
    const streamed = h.stream("task-vm-error", (events) =>
        events.some((event) => event.state === "failed"),
    );
    await send(h);
    const snapshot = await waitFor(
        async () => (await h.request("GET", "/sessions/task-vm-error")).body,
        (snapshot) => snapshot.state !== "working",
    );
    expect(snapshot).toMatchObject({ state: "failed", error: `${code}: Servers overloaded` });
    expect(snapshot.providerError).toBeUndefined();
    expect(providerFetch).toHaveBeenCalledTimes(1);
    const events = await streamed;
    expect(events.at(-1)).toMatchObject({ state: "failed", error: `${code}: Servers overloaded` });
    harness = await h.restart();
    expect((await harness.request("GET", "/sessions/task-vm-error")).body).toMatchObject({
        state: "failed",
        error: `${code}: Servers overloaded`,
        turnId: "k1",
    });
});

it.each(["server_error", "server_overloaded", "rate_limit_exceeded"])(
    "retries structured %s and recovers without relying on its message",
    async (code) => {
        const h = await taskVmHarness(true);
        const providerFetch = provider(h, [failure(code, "Please wait"), success]);
        await create(h);
        await send(h);
        const snapshot = await waitFor(
            async () => (await h.request("GET", "/sessions/task-vm-error")).body,
            (snapshot) => snapshot.state !== "working",
        );
        expect(snapshot).toMatchObject({ state: "done", error: null, turnId: "k1" });
        expect(providerFetch).toHaveBeenCalledTimes(2);
        expect(snapshot.entries.at(-1).message.content[0].text).toBe("recovered");
    },
);

it.each(["invalid_api_key", "content_filter"])(
    "does not retry structured %s during blocking compaction and reloads its failure",
    async (code) => {
        const h = await taskVmHarness(true, true);
        const providerFetch = provider(h, [success, failure(code)]);
        await create(h);
        await send(h);
        await waitFor(
            async () => (await h.request("GET", "/sessions/task-vm-error")).body,
            (snapshot) => snapshot.state !== "working",
        );
        expect(providerFetch).toHaveBeenCalledTimes(1);
        await h.request("POST", "/sessions/task-vm-error/messages", {
            key: "k2",
            text: "x".repeat(16000),
        });
        const snapshot = await waitFor(
            async () => (await h.request("GET", "/sessions/task-vm-error")).body,
            (snapshot) => snapshot.state !== "working",
        );
        expect(snapshot).toMatchObject({ state: "failed", turnId: "k2" });
        expect(snapshot.error).toBe(
            `Compaction failed: Summarization failed: ${code}: Servers overloaded`,
        );
        expect(providerFetch).toHaveBeenCalledTimes(2);
        harness = await h.restart();
        expect((await harness.request("GET", "/sessions/task-vm-error")).body).toMatchObject({
            state: "failed",
            turnId: "k2",
            error: snapshot.error,
        });
    },
);

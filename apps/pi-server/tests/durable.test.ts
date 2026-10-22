import { execFileSync, spawn, type ChildProcess } from "node:child_process";
import { once } from "node:events";
import { existsSync, mkdtempSync, mkdirSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { fauxAssistantMessage, fauxToolCall, fauxProvider } from "@earendil-works/pi-ai";
import { afterAll, afterEach, beforeAll, describe, expect, it } from "vite-plus/test";
import { PROVIDER_RETRY_POLICY } from "../src/registry.ts";
import { retryDelayMs } from "@earendil-works/pi-ai/utils/retry";
import { type Harness, startHarness, TOKEN, waitFor } from "./support.ts";

let h: Harness | undefined;
afterEach(async () => {
    await h?.close();
    h = undefined;
});
const create = async (h: Harness) =>
    h.request("POST", "/sessions", {
        id: "durable",
        cwd: h.workspace,
        model: "faux/model-a",
        thinkingLevel: "low",
    });
const settled = (h: Harness) =>
    waitFor(
        async () => (await h.request("GET", "/sessions/durable")).body,
        (body) => body.state !== "working",
    );

describe("Durable admissions and lifecycle", () => {
    it("serializes concurrent creates and keyed sends, and preserves keys on idle unload", async () => {
        h = await startHarness();
        const creates = await Promise.all([create(h), create(h), create(h)]);
        expect(creates.map((r) => r.status).sort((a, b) => a - b)).toEqual([200, 200, 201]);
        h.faux.setResponses([fauxAssistantMessage("one answer")]);
        const sends = await Promise.all(
            Array.from({ length: 6 }, () =>
                h!.request("POST", "/sessions/durable/messages", { key: "one", text: "go" }),
            ),
        );
        expect(sends.filter((r) => r.status === 202)).toHaveLength(1);
        expect(sends.filter((r) => r.status === 200 && r.body.duplicate)).toHaveLength(5);
        const before = await settled(h);
        h.registry.unloadIdle(Date.now() + 60_001);
        const after = (await h.request("GET", "/sessions/durable")).body;
        expect(after.run).not.toBe(before.run);
        expect(after.entries).toEqual(before.entries);
        expect(after.usage).toEqual(before.usage);
        expect(
            (await h.request("POST", "/sessions/durable/messages", { key: "one", text: "go" })).body
                .duplicate,
        ).toBe(true);
    });

    it("retries transient failures within Durable when Pi retry settings enable it", async () => {
        h = await startHarness({
            harnessSettings: { retry: { enabled: true, maxRetries: 1, baseDelayMs: 1 } },
        });
        await create(h);
        let requests = 0;
        h.faux.setResponses([
            () => {
                requests++;
                return fauxAssistantMessage("", {
                    stopReason: "error",
                    errorMessage: "503 Service unavailable",
                });
            },
            () => {
                requests++;
                return fauxAssistantMessage("recovered after retry");
            },
        ]);
        await h.request("POST", "/sessions/durable/messages", { key: "retry", text: "go" });
        expect((await settled(h)).state).toBe("done");
        expect(requests).toBe(2);
    });

    it("accepts every ADR 0229 send-key character through 128 bytes and deduplicates after restart", async () => {
        h = await startHarness();
        await create(h);
        const key = "bump-apps-gateway.handoff.c2:Retry_1-A".padEnd(128, "z");
        h.faux.setResponses([fauxAssistantMessage("answer")]);
        expect(
            (await h.request("POST", "/sessions/durable/messages", { key, text: "go" })).status,
        ).toBe(202);
        expect((await settled(h)).turnId).toBe(key);
        h = await h.restart();
        expect(
            (await h.request("POST", "/sessions/durable/messages", { key, text: "go" })).body
                .duplicate,
        ).toBe(true);
        expect(
            (await h.request("GET", "/sessions/durable")).body.entries.filter(
                (entry: any) => entry.message.role === "user",
            ),
        ).toHaveLength(1);
    });

    it("uses Orbit's retry budget, exposes the wait, and fails with the category on exhaustion", async () => {
        expect([1, 2, 3, 4].map((attempt) => retryDelayMs(PROVIDER_RETRY_POLICY, attempt))).toEqual(
            [60_000, 120_000, 240_000, 480_000],
        );
        h = await startHarness({
            harnessSettings: { retry: { ...PROVIDER_RETRY_POLICY, baseDelayMs: 100 } },
        });
        await create(h);
        let requests = 0;
        h.faux.setResponses(
            Array.from({ length: 5 }, () => () => {
                requests++;
                return fauxAssistantMessage("", {
                    stopReason: "error",
                    errorMessage: "503 Service unavailable",
                });
            }),
        );
        await h.request("POST", "/sessions/durable/messages", { key: "budget", text: "go" });
        const waiting = await waitFor(
            async () => (await h!.request("GET", "/sessions/durable?view=state")).body,
            (value) => value.error?.startsWith("Waiting for provider retry"),
        );
        expect(waiting).toMatchObject({
            state: "working",
            turnId: "budget",
            providerError: "server_error",
        });
        expect(waiting.error).toMatch(/retry 1\/4.* at .*Z\./);
        const result = await settled(h);
        expect(result).toMatchObject({
            state: "failed",
            providerError: "server_error",
            turnId: "budget",
        });
        expect(requests).toBe(5);
    });

    it("refuses Claude on direct creates and omits it from capabilities", async () => {
        const claude = fauxProvider({ provider: "fixture", models: [{ id: "claude-probe" }] });
        h = await startHarness({ nativeProviders: [claude.provider] });
        await h.modelRuntime.setRuntimeApiKey("fixture", "fixture");
        const result = await h.request("POST", "/sessions", {
            id: "claude",
            cwd: h.workspace,
            model: "fixture/claude-probe",
            thinkingLevel: "low",
        });
        expect(result.status).toBe(422);
        expect(result.body.error.code).toBe("model.unavailable");
        expect((await h.request("GET", "/capabilities")).body.models).not.toContain(
            "fixture/claude-probe",
        );
    });

    it("gives Durable bash the session environment and agent TMPDIR", async () => {
        h = await startHarness();
        await create(h);
        h.faux.setResponses([
            fauxAssistantMessage(
                [
                    fauxToolCall("bash", {
                        command:
                            'printf "%s\\n" "$PI_SESSION_ID" "$PI_PROVIDER" "$PI_MODEL" "$PI_REASONING_LEVEL" "$PI_SESSION_FILE" "$TMPDIR"',
                    }),
                ],
                { stopReason: "toolUse" },
            ),
            fauxAssistantMessage("done"),
        ]);
        await h.request("POST", "/sessions/durable/messages", { key: "env", text: "go" });
        const snapshot = await settled(h);
        const text = snapshot.entries.find((e: any) => e.message.role === "toolResult").message
            .content[0].text;
        expect(text.split("\n").slice(0, 4)).toEqual(["durable", "faux", "model-a", "low"]);
        expect(text).toContain(join(h.root, "agent", "durable", "durable.sqlite"));
        expect(text).toContain(join(h.workspace, ".orbit", "tmp", `agent-${process.getuid?.()}`));
    });

    it("retains the offloaded bash abort notice in HTTP history and across restart", async () => {
        h = await startHarness();
        mkdirSync(join(h.workspace, ".git"));
        await create(h);
        h.faux.setResponses([
            fauxAssistantMessage(
                [
                    fauxToolCall("bash", {
                        command:
                            "head -c 9000 /dev/zero | tr '\\0' x; touch output-ready; sleep 20",
                    }),
                ],
                { stopReason: "toolUse" },
            ),
        ]);
        await h.request("POST", "/sessions/durable/messages", { key: "abort", text: "go" });
        await waitFor(async () => existsSync(join(h!.workspace, "output-ready")), Boolean);
        expect((await h.request("POST", "/sessions/durable/interrupt")).status).toBe(202);
        const snapshot = await settled(h);
        expect(snapshot.state).toBe("failed");
        const result = snapshot.entries.find((e: any) => e.message.role === "toolResult").message;
        expect(result.isError).toBe(true);
        expect(result.content[0].text).toMatch(/^9000 bytes, 1 lines, saved to /);
        expect(result.content[0].text).toMatch(/Command aborted$/);
        h = await h.restart();
        const reloaded = (await h.request("GET", "/sessions/durable")).body;
        expect(reloaded.state).toBe("failed");
        expect(reloaded.entries.find((e: any) => e.message.role === "toolResult").message).toEqual(
            result,
        );
        let modelHistory = "";
        h.faux.setResponses([
            (context) => {
                modelHistory = JSON.stringify(context);
                return fauxAssistantMessage("continued");
            },
        ]);
        await h.request("POST", "/sessions/durable/messages", {
            key: "after-abort",
            text: "continue",
        });
        await settled(h);
        expect(modelHistory).toContain(result.content[0].text.replaceAll("\n", "\\n"));
    });
});

describe("compiled Bun crash recovery with no model network", () => {
    let root: string;
    let binary: string;
    const processes = new Set<ChildProcess>();
    beforeAll(() => {
        root = mkdtempSync(join(tmpdir(), "pi-durable-binary-"));
        binary = join(root, "worker");
        execFileSync(
            "bun",
            ["build", "tests/fixtures/durable-worker.ts", "--compile", "--outfile", binary],
            { cwd: resolve(import.meta.dirname, ".."), stdio: "pipe" },
        );
    });
    afterEach(async () => {
        for (const child of processes) {
            if (child.exitCode === null && child.signalCode === null) {
                const exit = once(child, "exit");
                child.kill("SIGKILL");
                await exit;
            }
        }
        processes.clear();
    });
    afterAll(() => rmSync(root, { recursive: true, force: true }));

    async function launch(dir: string, mode: string) {
        const child = spawn(binary, [dir, mode], { stdio: ["ignore", "pipe", "pipe"] });
        processes.add(child);
        let output = "";
        let errors = "";
        child.stderr!.on("data", (chunk) => {
            errors += String(chunk);
        });
        const url = await new Promise<string>((resolvePromise, reject) => {
            const timer = setTimeout(
                () => reject(new Error(`Worker startup timed out: ${errors}`)),
                5000,
            );
            child.once("exit", () => {
                clearTimeout(timer);
                reject(new Error(`Worker exited: ${errors}`));
            });
            child.stdout!.on("data", (chunk) => {
                output += String(chunk);
                if (output.includes("\n")) {
                    clearTimeout(timer);
                    resolvePromise(JSON.parse(output.split("\n")[0]!).url);
                }
            });
        });
        return {
            child,
            request: async (method: string, path: string, body?: unknown) => {
                const response = await fetch(url + path, {
                    method,
                    headers: {
                        Authorization: `Bearer ${TOKEN}`,
                        "Content-Type": "application/json",
                    },
                    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
                });
                return { status: response.status, body: (await response.json()) as any };
            },
        };
    }

    it.each(["hold", "tool"])(
        "recovers %s after SIGKILL, with no second input or unsafe tool effect",
        async (mode) => {
            const dir = join(root, mode);
            const worker = await launch(dir, mode);
            await worker.request("POST", "/sessions", {
                id: "crash",
                cwd: join(dir, "workspace"),
                model: "faux/model-a",
                thinkingLevel: "low",
            });
            await worker.request("POST", "/sessions/crash/messages", {
                key: "crash-key",
                text: "go",
            });
            const marker =
                mode === "hold"
                    ? join(dir, "request-started")
                    : join(dir, "workspace", "tool-started");
            expect(await waitFor(async () => existsSync(marker), Boolean)).toBe(true);
            const before = (await worker.request("GET", "/sessions/crash")).body;
            const killed = once(worker.child, "exit");
            worker.child.kill("SIGKILL");
            await killed;
            // The host cgroup owns orphan cleanup in production. Stop our fixture shell by its PID.
            if (mode === "tool") {
                const shellPid = Number(
                    readFileSync(join(dir, "workspace", "shell.pid"), "utf8").trim(),
                );
                try {
                    process.kill(-shellPid, "SIGKILL");
                } catch {
                    /* Shell already exited. */
                }
            }
            const recovered = await launch(dir, "answer");
            const snapshot = await waitFor(
                async () => (await recovered.request("GET", "/sessions/crash")).body,
                (value) => value.state === "done",
            );
            expect(snapshot).toMatchObject({ state: "done", error: null, turnId: "crash-key" });
            expect(snapshot.run).not.toBe(before.run);
            expect(
                snapshot.entries.filter((entry: any) => entry.message.role === "user"),
            ).toHaveLength(1);
            expect(
                (
                    await recovered.request("POST", "/sessions/crash/messages", {
                        key: "crash-key",
                        text: "go",
                    })
                ).body.duplicate,
            ).toBe(true);
            if (mode === "tool") {
                expect(readFileSync(join(dir, "workspace", "effects"), "utf8")).toBe("once");
                const result = snapshot.entries.find(
                    (entry: any) => entry.message.role === "toolResult",
                ).message;
                expect(result.isError).toBe(true);
                expect(JSON.stringify(result)).toContain("interrupted");
            }
        },
    );
    it("reports a parked crash-loop task as failed, including after another reopen", async () => {
        const dir = join(root, "park");
        const worker = await launch(dir, "park");
        await worker.request("POST", "/sessions", {
            id: "crash",
            cwd: join(dir, "workspace"),
            model: "faux/model-a",
            thinkingLevel: "low",
        });
        await worker.request("POST", "/sessions/crash/messages", { key: "park-key", text: "go" });
        await waitFor(async () => existsSync(join(dir, "request-started")), Boolean);
        const killed = once(worker.child, "exit");
        worker.child.kill("SIGKILL");
        await killed;
        for (let attempt = 0; attempt < 2; attempt++) {
            const recovered = await launch(dir, "park-reopen");
            const snapshot = await waitFor(
                async () => (await recovered.request("GET", "/sessions/crash")).body,
                (value) => value.state === "failed",
            );
            expect(snapshot).toMatchObject({ state: "failed", turnId: "park-key" });
            expect(snapshot.error).toMatch(/parked.*restart limit.*assistance/);
            const exit = once(recovered.child, "exit");
            recovered.child.kill("SIGKILL");
            await exit;
        }
        const resumed = await launch(dir, "park-reopen");
        await resumed.request("GET", "/sessions/crash");
        await resumed.request("POST", "/sessions/crash/interrupt");
        expect(
            (
                await resumed.request("POST", "/sessions/crash/messages", {
                    key: "after-park",
                    text: "continue",
                })
            ).status,
        ).toBe(202);
        const continued = await waitFor(
            async () => (await resumed.request("GET", "/sessions/crash")).body,
            (value) => value.state === "done",
        );
        expect(continued.turnId).toBe("after-park");
    });
});

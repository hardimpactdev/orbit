import { describe, expect, it } from "vite-plus/test";
import { deriveState } from "../src/state.ts";

const settled = (stopReason: string, errorMessage?: string) => ({
    working: false,
    interruptedByRestart: false,
    lastAssistant: { stopReason, errorMessage },
});

describe("deriveState", () => {
    it("reports idle before the first completed turn", () => {
        expect(
            deriveState({ working: false, interruptedByRestart: false, lastAssistant: undefined }),
        ).toEqual({
            state: "idle",
            error: null,
        });
    });

    it("reports working while a turn is active, even after an earlier failure", () => {
        expect(deriveState({ ...settled("error", "boom"), working: true })).toEqual({
            state: "working",
            error: null,
        });
    });

    it("reports done only for a normal stop", () => {
        expect(deriveState(settled("stop"))).toEqual({ state: "done", error: null });
    });

    it.each([
        ["error", "provider exploded", "provider exploded"],
        ["aborted", undefined, "The turn was interrupted."],
        ["length", undefined, "The model reached its output limit."],
        ["toolUse", undefined, 'The turn ended with stop reason "toolUse".'],
    ])("reports failed for stop reason %s", (reason, message, error) => {
        expect(deriveState(settled(reason, message))).toEqual({ state: "failed", error });
    });

    it.each([
        ['{"error":{"code":"server_overloaded","message":"Try later"}}', "overloaded"],
        ['OpenAI API error (503): {"error":{"type":"overloaded_error"}}', "overloaded"],
        ['429 {"error":{"code":"rate_limit_exceeded","message":"Slow down"}}', "rate_limited"],
        ["OpenAI API error (502): Bad gateway", "server_error"],
        ['{"error":{"code":503,"message":"Unavailable"}}', "server_error"],
        ["Servers overloaded", "overloaded"],
        ["The server is overloaded or not ready yet.", "overloaded"],
        ["Too many requests", "rate_limited"],
        [
            '429 {"error":{"code":"rate_limit_exceeded","message":"Per-minute quota reached"}}',
            "rate_limited",
        ],
        ['429 {"error":{"code":"insufficient_quota","message":"Quota exceeded"}}', undefined],
        ['503 {"error":{"code":"invalid_api_key","message":"Denied"}}', undefined],
        ["401 Servers overloaded", undefined],
        ["OpenAI API error (429): Billing limit exceeded", undefined],
        ["Check failed: servers overloaded", undefined],
        ["Provider finish_reason: content_filter", undefined],
        ['503 {"error":', "server_error"],
        ['503 {"error":{"code":"service_unavailable"}}', "server_error"],
        ['{"error":', undefined],
        ["Unknown failure", undefined],
    ])("classifies only transient provider errors: %s", (message, category) => {
        expect(deriveState(settled("error", message)).providerError).toBe(category);
        expect(deriveState(settled("stop", message)).providerError).toBeUndefined();
        expect(deriveState(settled("aborted", message)).providerError).toBeUndefined();
    });

    it("reports a restarted active turn as working while Durable resumes it", () => {
        expect(deriveState({ ...settled("stop"), interruptedByRestart: true })).toEqual({
            state: "working",
            error: null,
        });
    });
});

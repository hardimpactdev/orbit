import { afterEach, beforeEach, expect, it, vi } from "vite-plus/test";
import { mergeOperation, type Operation, type OperationStep } from "../api/operations";
import {
    addLine,
    LIVE_LINES,
    LIVE_READ_SECONDS,
    OperationFollow,
    type OperationOutput,
    operationChannel,
} from "./operation";
import type { RealtimeSocket } from "./socket";

const step = (
    name: string,
    position: number,
    status: string,
    sequence: number,
    output: string | null = null,
): OperationStep => ({
    name,
    position,
    status,
    resumable: true,
    sequence,
    started_at: null,
    finished_at: null,
    error_code: null,
    output,
    output_truncated: false,
});

const operation = (overrides: Partial<Operation> = {}): Operation => ({
    id: 42,
    kind: "instance.deploy",
    target_type: "instance",
    target_id: 17,
    input: {},
    status: "running",
    sequence: 3,
    current_step: null,
    failed_step: null,
    error_code: null,
    result: null,
    cancel_requested: false,
    assistance: null,
    attempts: 1,
    triggered_by: "laptop",
    caller_node_id: null,
    caller_node_name: null,
    created_at: null,
    started_at: null,
    finished_at: null,
    poll_after_seconds: 2,
    steps: [],
    ...overrides,
});

function fakeSocket() {
    const handlers = new Map<string, (data: unknown) => void>();
    const subscribed: string[] = [];
    const unsubscribed: string[] = [];
    const socket: RealtimeSocket = {
        socketId: "1.1",
        subscribe: (channel) => {
            subscribed.push(channel);

            return {
                bind: (event, callback) => handlers.set(event, callback),
                unbind_all: () => handlers.clear(),
            };
        },
        unsubscribe: (channel) => unsubscribed.push(channel),
    };

    return { socket, handlers, subscribed, unsubscribed };
}

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

it("keeps the steps a delta read did not return and ignores a read older than the page", () => {
    const first = operation({ steps: [step("a", 1, "running", 3), step("b", 2, "pending", 2)] });
    const delta = operation({ sequence: 5, steps: [step("a", 1, "succeeded", 5, "done\n")] });

    const merged = mergeOperation(first, delta);

    expect(merged.sequence).toBe(5);
    expect(merged.steps?.map((s) => `${s.name}:${s.status}`)).toEqual(["a:succeeded", "b:pending"]);
    expect(mergeOperation(merged, operation({ sequence: 4, status: "queued" }))).toBe(merged);
});

it("subscribes before it reads, reads again for a newer event, drops an older one, and stops once terminal", async () => {
    const { socket, handlers, subscribed, unsubscribed } = fakeSocket();
    const reads: Array<number | null> = [];
    const answers = [
        operation({ sequence: 3, steps: [step("a", 1, "running", 3)] }),
        operation({
            sequence: 6,
            status: "succeeded",
            poll_after_seconds: null,
            steps: [step("a", 1, "succeeded", 6)],
        }),
    ];
    const seen: Operation[] = [];
    const follow = new OperationFollow(
        42,
        (next) => seen.push(next),
        socket,
        async (_id, after) => {
            reads.push(after);

            return answers.shift() ?? operation({ sequence: 6, status: "succeeded" });
        },
    );

    follow.start();
    expect(subscribed).toEqual([operationChannel(42)]);
    await vi.waitFor(() => expect(seen).toHaveLength(1));

    handlers.get("operation.updated")?.({ data: { sequence: 2 } });
    await Promise.resolve();
    expect(reads).toEqual([null]);

    handlers.get("operation.updated")?.({ data: { sequence: 6 } });
    await vi.waitFor(() => expect(seen).toHaveLength(2));

    expect(reads).toEqual([null, 3]);
    expect(seen[1]?.status).toBe("succeeded");
    expect(seen[1]?.steps?.[0]?.status).toBe("succeeded");
    expect(unsubscribed).toEqual([operationChannel(42)]);
});

it("reads on its own schedule when no event arrives: every 15 seconds live, every poll interval without a socket", async () => {
    const reads: Array<number | null> = [];
    const read = async (_id: number, after: number | null) => {
        reads.push(after);

        return operation({ sequence: 3, poll_after_seconds: 4 });
    };

    const live = new OperationFollow(42, () => {}, fakeSocket().socket, read);
    live.start();
    await vi.waitFor(() => expect(reads).toHaveLength(1));
    await vi.advanceTimersByTimeAsync((LIVE_READ_SECONDS - 1) * 1000);
    expect(reads).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1000);
    expect(reads).toHaveLength(2);
    live.stop();

    reads.length = 0;
    const polling = new OperationFollow(42, () => {}, null, read);
    polling.start();
    await vi.waitFor(() => expect(reads).toHaveLength(1));
    await vi.advanceTimersByTimeAsync(4000);
    expect(reads).toHaveLength(2);
    polling.stop();
});

it("hands each live output line of the channel to onOutput and keeps the newest lines per step", async () => {
    const { socket, handlers } = fakeSocket();
    const outputs: OperationOutput[] = [];
    const follow = new OperationFollow(
        42,
        () => {},
        socket,
        async () => operation(),
        (output) => outputs.push(output),
    );

    follow.start();
    handlers.get("client-output")?.({
        step: "migrate",
        stream: "stdout",
        sequence: 1,
        line: "Migrating",
    });
    handlers.get("client-output")?.({ line: "no step" });

    expect(outputs).toEqual([
        { step: "migrate", stream: "stdout", sequence: 1, line: "Migrating" },
    ]);
    follow.stop();

    let lines: Record<string, string[]> = {};
    for (let n = 0; n <= LIVE_LINES; n++) {
        lines = addLine(lines, {
            step: "migrate",
            stream: "stdout",
            sequence: n,
            line: `line ${n}`,
        });
    }

    expect(lines.migrate).toHaveLength(LIVE_LINES);
    expect(lines.migrate?.[0]).toBe("line 1");
});

import { afterEach, beforeEach, expect, it, vi } from "vite-plus/test";
import { mergeOperation, type Operation } from "../api/operations";
import { LIVE_READ_SECONDS, OperationFollow, operationChannel } from "./operation";
import type { RealtimeSocket } from "./socket";

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
    error: null,
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

it("keeps the newest parent record and ignores an older read", () => {
    const first = operation();
    const next = operation({ sequence: 5, status: "succeeded", result: { deployed: true } });
    expect(mergeOperation(first, next)).toBe(next);
    expect(mergeOperation(next, operation({ sequence: 4, status: "queued" }))).toBe(next);
});

it("subscribes before it reads, reads again for a newer event, drops an older one, and stops once terminal", async () => {
    const { socket, handlers, subscribed, unsubscribed } = fakeSocket();
    const reads: number[] = [];
    const answers = [
        operation({ sequence: 3 }),
        operation({
            sequence: 6,
            status: "succeeded",
            poll_after_seconds: null,
        }),
    ];
    const seen: Operation[] = [];
    const follow = new OperationFollow(
        42,
        (next) => seen.push(next),
        socket,
        async (id) => {
            reads.push(id);

            return answers.shift() ?? operation({ sequence: 6, status: "succeeded" });
        },
    );

    follow.start();
    expect(subscribed).toEqual([operationChannel(42)]);
    await vi.waitFor(() => expect(seen).toHaveLength(1));

    handlers.get("operation.updated")?.({ data: { sequence: 2 } });
    await Promise.resolve();
    expect(reads).toEqual([42]);

    handlers.get("operation.updated")?.({ data: { sequence: 6 } });
    await vi.waitFor(() => expect(seen).toHaveLength(2));

    expect(reads).toEqual([42, 42]);
    expect(seen[1]?.status).toBe("succeeded");
    expect(unsubscribed).toEqual([operationChannel(42)]);
});

it("reads on its own schedule when no event arrives: every 15 seconds live, every poll interval without a socket", async () => {
    const reads: number[] = [];
    const read = async (id: number) => {
        reads.push(id);

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

it("stops following an interrupted native Operation", async () => {
    const { socket, unsubscribed } = fakeSocket();
    const read = vi.fn(async () => operation({ status: "interrupted", poll_after_seconds: null }));
    const seen: Operation[] = [];
    new OperationFollow(42, (next) => seen.push(next), socket, read).start();
    await vi.waitFor(() => expect(seen).toHaveLength(1));
    await vi.advanceTimersByTimeAsync(60_000);
    expect(read).toHaveBeenCalledTimes(1);
    expect(seen[0]?.status).toBe("interrupted");
    expect(unsubscribed).toEqual([operationChannel(42)]);
});

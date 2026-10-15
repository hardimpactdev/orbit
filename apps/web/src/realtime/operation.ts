import { useEffect, useState } from "react";
import { isTerminal, mergeOperation, readOperation, type Operation } from "../api/operations";
import { useRealtimeSocket, type RealtimeChannel, type RealtimeSocket } from "./socket";

/** Seconds between two reads while the channel is subscribed and no event arrives, in case one was lost. */
export const LIVE_READ_SECONDS = 15;

/** Seconds between two reads when the Gateway names no poll interval. */
export const POLL_SECONDS = 2;

export const operationChannel = (id: number): string => `private-operation.${id}`;

type Read = (id: number, afterSequence: number | null) => Promise<Operation>;

/**
 * Follows one operation (docs/reference/operations.md#follow-an-operation-over-reverb): it subscribes to the
 * operation's channel first, then reads the operation, then reads again with `after_sequence` for each event whose
 * sequence is newer than what it has. Without a socket it reads every `poll_after_seconds`. It stops once the
 * operation ends. `onChange` receives the merged operation after each read.
 */
export class OperationFollow {
    private operation: Operation | null = null;
    private channel: RealtimeChannel | null = null;
    private timer: ReturnType<typeof setTimeout> | undefined;
    private reading = false;
    private again = false;
    private stopped = false;

    private readonly id: number;
    private readonly onChange: (operation: Operation) => void;
    private readonly socket: RealtimeSocket | null;
    private readonly read: Read;

    constructor(
        id: number,
        onChange: (operation: Operation) => void,
        socket: RealtimeSocket | null,
        read: Read = readOperation,
    ) {
        this.id = id;
        this.onChange = onChange;
        this.socket = socket;
        this.read = read;
    }

    start(): void {
        if (this.socket !== null) {
            this.channel = this.socket.subscribe(operationChannel(this.id));
            this.channel.bind("operation.updated", (payload: unknown) => this.event(payload));
        }

        void this.refresh();
    }

    stop(): void {
        this.stopped = true;
        clearTimeout(this.timer);

        if (this.channel !== null && this.socket !== null) {
            this.channel.unbind_all();
            this.socket.unsubscribe(operationChannel(this.id));
            this.channel = null;
        }
    }

    /** Drops an event whose sequence is not newer than the page's; reads for a newer one. */
    event(payload: unknown): void {
        const data = (payload as { data?: { sequence?: unknown } } | null)?.data;
        const sequence = typeof data?.sequence === "number" ? data.sequence : null;

        if (sequence !== null && this.operation !== null && sequence <= this.operation.sequence) {
            return;
        }

        void this.refresh();
    }

    private async refresh(): Promise<void> {
        if (this.stopped) {
            return;
        }

        if (this.reading) {
            this.again = true;

            return;
        }

        this.reading = true;
        clearTimeout(this.timer);

        try {
            const read = await this.read(this.id, this.operation?.sequence ?? null);
            this.operation = mergeOperation(this.operation, read);

            if (!this.stopped) {
                this.onChange(this.operation);
            }
        } catch {
            // A failed read only costs time: the next timer or event reads again.
        } finally {
            this.reading = false;
        }

        if (this.stopped) {
            return;
        }

        if (this.operation !== null && isTerminal(this.operation)) {
            this.stop();

            return;
        }

        if (this.again) {
            this.again = false;
            void this.refresh();

            return;
        }

        const seconds =
            this.socket !== null
                ? LIVE_READ_SECONDS
                : (this.operation?.poll_after_seconds ?? POLL_SECONDS);
        this.timer = setTimeout(() => void this.refresh(), seconds * 1000);
    }
}

/** The followed state of one operation, or null before the first read. A new socket starts a new follow. */
export function useOperation(id: number | null): Operation | null {
    const socket = useRealtimeSocket();
    const [operation, setOperation] = useState<Operation | null>(null);

    useEffect(() => {
        if (id === null) {
            setOperation(null);

            return;
        }

        const follow = new OperationFollow(id, setOperation, socket);
        follow.start();

        return () => follow.stop();
    }, [id, socket]);

    return id === null || operation?.id !== id ? null : operation;
}

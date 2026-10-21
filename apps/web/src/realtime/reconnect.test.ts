import { QueryClient } from "@tanstack/react-query";
import Pusher from "pusher-js";
import { expect, it, vi } from "vite-plus/test";
import { setTransport } from "../api/client";
import { agentState, clearAllAgentPresence } from "./agent-presence";
import { connectRealtime } from "./connect";

it("re-authorizes and re-subscribes Node presence on the same Pusher client after reconnect", async () => {
    // Use the installed pusher-js, including its real channel registry and connected handlers.
    // Only replace the network boundary; this test never opens a fleet socket.
    const ready = Pusher.isReady;
    Pusher.isReady = false;
    const controller = new AbortController();
    const client = new QueryClient();
    client.setQueryData(["nodes"], [{ id: 12, status: "active" }]);
    vi.stubEnv("DEV", true);
    vi.stubGlobal("window", { location: { origin: "http://localhost:5173" } });
    const auth = vi.fn<typeof fetch>(
        async () =>
            new Response(
                JSON.stringify({ auth: "signed", channel_data: '{"user_id":"viewer.1"}' }),
            ),
    );
    vi.stubGlobal("fetch", auth);
    setTransport(async () => ({
        status: 200,
        payload: { data: { url: "wss://reverb.orbit", key: "test", channel: "orbit" } },
    }));
    try {
        await connectRealtime(client, controller.signal);
        const pusher = Pusher.instances.at(-1)!;
        const sent = vi.spyOn(pusher.connection, "send_event").mockReturnValue(true);
        const presence = pusher.channel("presence-node.12");
        const connected = async (socketId: string) => {
            pusher.connection.state = "connected";
            pusher.connection.socket_id = socketId;
            pusher.connection.emit("connected", { socket_id: socketId });
            await vi.waitFor(() => {
                expect(sent).toHaveBeenCalledWith(
                    "pusher:subscribe",
                    expect.objectContaining({ channel: "presence-node.12", auth: "signed" }),
                    undefined,
                );
            });
        };
        const subscribed = () => {
            pusher.connection.emit("message", {
                event: "pusher_internal:subscription_succeeded",
                channel: "presence-node.12",
                data: { presence: { ids: ["agent.12"], hash: { "agent.12": {} }, count: 1 } },
            });
        };
        const heartbeat = () => {
            pusher.connection.emit("message", {
                event: "client-heartbeat",
                channel: "presence-node.12",
                user_id: "agent.12",
                data: { sequence: 1 },
            });
        };
        await connected("1.2");
        subscribed();
        heartbeat();
        expect(agentState(12)).toBe("online");

        pusher.connection.state = "connecting";
        pusher.connection.emit("connecting");
        pusher.connection.emit("state_change", { current: "connecting" });
        sent.mockClear();
        await connected("2.2");
        expect(pusher.channel("presence-node.12")).toBe(presence);
        expect(auth.mock.calls.map((call) => call[1]?.body)).toEqual(
            expect.arrayContaining([
                "socket_id=1.2&channel_name=presence-node.12",
                "socket_id=2.2&channel_name=presence-node.12",
            ]),
        );
        subscribed();
        expect(agentState(12)).toBe("lost");
        heartbeat();
        expect(agentState(12)).toBe("online");
    } finally {
        controller.abort();
        client.clear();
        clearAllAgentPresence();
        setTransport(null);
        Pusher.isReady = ready;
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        vi.unstubAllEnvs();
    }
});

import { afterEach, describe, expect, it } from "vite-plus/test";
import { createDemoGateway } from "../demo/gateway";
import { actionsFor } from "./actions";
import { get, setTransport } from "./client";
import type { Node } from "./types";

const doctor = (node: Node) => {
    const action = actionsFor("nodes", node).find((candidate) => candidate.label === "doctor");

    if (action?.run === undefined) {
        throw new Error("The Node menu has no doctor action.");
    }

    return action.run;
};

afterEach(() => setTransport(null));

describe("the Node doctor action", () => {
    it("reads the report the Gateway answers at once, starting no Operation", async () => {
        const gateway = createDemoGateway();
        setTransport(gateway.transport, "demo fleet");
        const [node] = await get<Node[]>("/api/v1/nodes");

        if (node === undefined) {
            throw new Error("The demo fleet has no Node.");
        }

        expect(await doctor(node)()).toBe(`Node [${node.name}] has no drift.`);
        expect(gateway.requests.map(({ method, path }) => `${method} ${path}`)).not.toEqual(
            expect.arrayContaining([expect.stringMatching(/^GET \/api\/v1\/operations\//)]),
        );
    });

    it("says the findings may be outdated while the agent is silent", async () => {
        const node = { id: 3, name: "beast" } as Node;
        setTransport(async () => ({
            status: 200,
            payload: {
                data: {
                    healthy: false,
                    nodes: [
                        {
                            node_id: 3,
                            node_name: "beast",
                            healthy: false,
                            families: [],
                            agent: { state: "offline", since: "2026-11-03T02:00:00Z" },
                        },
                    ],
                    summary: {
                        nodes: 1,
                        families: 1,
                        checks: 1,
                        drift: 1,
                        unverifiable: 0,
                        informational: 0,
                    },
                },
                meta: { request_id: "r" },
            },
        }));

        expect(await doctor(node)()).toBe(
            "Node [beast] has 1 drifted check(s); see doctor --node=3. Possibly outdated: the agent is silent since 2026-11-03T02:00:00Z.",
        );
    });
});

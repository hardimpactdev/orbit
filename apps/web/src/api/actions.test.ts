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
    it("follows the started Doctor Operation and reads the report from its result", async () => {
        const gateway = createDemoGateway();
        setTransport(gateway.transport, "demo fleet");
        const [node] = await get<Node[]>("/api/v1/nodes");

        if (node === undefined) {
            throw new Error("The demo fleet has no Node.");
        }

        expect(await doctor(node)()).toBe(`Node [${node.name}] has no drift.`);
        expect(gateway.requests.map(({ method, path }) => `${method} ${path}`)).toEqual(
            expect.arrayContaining([
                "POST /api/v1/doctor",
                expect.stringMatching(/^GET \/api\/v1\/operations\/\d+$/),
            ]),
        );
    });

    it("reports a Doctor Operation that ends without a report", async () => {
        const node = { id: 3, name: "beast" } as Node;
        const operation = {
            id: 9,
            kind: "doctor.inspect",
            status: "queued",
            sequence: 1,
            error_code: null,
            result: null,
            poll_after_seconds: 0,
        };
        setTransport(async (method) =>
            method === "POST"
                ? { status: 202, payload: { data: operation, meta: { request_id: "r" } } }
                : {
                      status: 200,
                      payload: {
                          data: {
                              ...operation,
                              status: "failed",
                              sequence: 2,
                              error_code: "doctor.unreachable",
                          },
                          meta: { request_id: "r" },
                      },
                  },
        );

        await expect(doctor(node)()).rejects.toThrow(
            "Doctor for node [beast] ended failed (doctor.unreachable) without a report.",
        );
    });
});

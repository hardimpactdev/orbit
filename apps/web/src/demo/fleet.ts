import { get, setTransport } from "../api/client";
import type { Fleet } from "../api/queries";
import type { FirewallRule, Node } from "../api/types";
import { createDemoGateway } from "./gateway";

/** The fixture fleet as the pages see it, read through the demo Gateway. For tests of fleet logic. */
export async function demoFleet(): Promise<Fleet> {
    setTransport(createDemoGateway().transport, "demo fleet");
    const nodes = await get<Node[]>("/api/v1/nodes");
    const firewall = await get<Array<{ rules: FirewallRule[] }>>("/api/v1/firewall-rules");

    return {
        nodes,
        projects: await get("/api/v1/projects"),
        instances: await get("/api/v1/instances"),
        processes: await get("/api/v1/processes"),
        schedules: await get("/api/v1/schedules"),
        databases: await get("/api/v1/database-connections"),
        firewall: firewall.flatMap((node) => node.rules),
        processesLoaded: true,
        loading: false,
        error: null,
    };
}

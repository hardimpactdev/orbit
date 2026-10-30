import { useFleet } from "../api/queries";
import { nodeHealthy } from "../fleet/fleet";
import { useGo } from "../ui/go";
import { PageHeader } from "../ui/PageHeader";
import { Pane } from "../ui/Pane";
import { useNodeTables } from "./nodeTables";

/** Nodes with desired active exporters and their meters, followed by other Nodes. */
export function NodesList() {
    const go = useGo();
    const fleet = useFleet();
    const { workers, clients, workerColumns, clientColumns, offline } = useNodeTables(true);
    const empty = fleet.loading ? "Loading…" : "None.";

    return (
        <div
            data-testid="section-list"
            className="flex flex-col gap-y-[var(--panel-gap)] md:grid md:h-full md:grid-rows-[auto_minmax(0,1fr)]"
        >
            <PageHeader trail={[{ label: "Nodes" }]}>
                <span
                    data-testid="nodes-create"
                    className="cursor-pointer text-dim hover:text-fg"
                    onClick={() => go.create()}
                >
                    + create
                </span>
            </PageHeader>
            <Pane
                name="list"
                order={1}
                title="Tracked nodes"
                // As tall as its rows, up to most of the page; the other nodes take what is left.
                className="w-full max-h-[50vh] md:max-h-[60vh]"
                columns={workerColumns}
                rows={workers}
                rowId={(n) => String(n.id)}
                warn={(n) => !nodeHealthy(n) || offline(n)}
                target={(row) => ({ kind: "nodes", row })}
                empty={empty}
            />
            <Pane
                name="clients"
                order={2}
                title="Other nodes"
                className="w-full max-h-[40vh] md:max-h-none"
                columns={clientColumns}
                rows={clients}
                rowId={(n) => String(n.id)}
                warn={(n) => !nodeHealthy(n)}
                target={(row) => ({ kind: "nodes", row })}
                empty={empty}
            />
        </div>
    );
}

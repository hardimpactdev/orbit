import { useQuery } from "@tanstack/react-query";
import {
    instanceDependenciesQuery,
    type DependencyInventory,
    type DependencyResolution,
} from "../api/dependencies";
import { useFallbackPoll } from "../realtime/liveness";
import { Frame, Note } from "../ui/Frame";
import { Pane, type Column } from "../ui/Pane";

const columns: Column<DependencyResolution>[] = [
    {
        header: "Package",
        width: 3,
        value: (dependency) => dependency.name,
        cell: (dependency) => (
            <span className="whitespace-normal break-all">{dependency.name}</span>
        ),
    },
    {
        header: "Installed version",
        width: 2,
        value: (dependency) => dependency.version,
        cell: (dependency) => (
            <span className="whitespace-normal break-all">{dependency.version}</span>
        ),
    },
    {
        header: "Scope",
        width: 1,
        hideOnMobile: true,
        value: (dependency) =>
            dependency.regular && dependency.development
                ? "Both"
                : dependency.development
                  ? "Dev"
                  : "Runtime",
    },
];

function Inventory({
    title,
    inventory,
    order,
}: {
    title: string;
    inventory: DependencyInventory;
    order: number;
}) {
    const rows = inventory.snapshot?.graph?.resolutions ?? [];
    const failed = inventory.succeeded === false || inventory.error_code !== null;
    const status = failed
        ? `Scan failed${inventory.error_code === null ? "" : ` · ${inventory.error_code}`}`
        : inventory.state === "stale"
          ? "Saved scan is stale."
          : inventory.attempted_at === null
            ? "Not scanned yet."
            : inventory.state === "absent"
              ? "No dependency manifest found."
              : inventory.snapshot === null || inventory.snapshot.graph === null
                ? "No installed versions available in the saved scan."
                : "Saved scan";

    return (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col gap-y-[4px]">
            <p
                className={`whitespace-normal break-words ${failed || inventory.state === "stale" ? "text-red" : "text-dim"}`}
            >
                {title}: {status}
                {inventory.snapshot !== null && <> · Observed {inventory.snapshot.observed_at}</>}
            </p>
            <Pane
                name={`dependencies-${inventory.ecosystem}`}
                order={order}
                title={title}
                testId={`instance-dependencies-${inventory.ecosystem}`}
                columns={columns}
                rows={rows}
                rowId={(dependency) => dependency.id}
                className="min-h-[160px]! min-w-0 flex-1"
                empty={
                    rows.length === 0 && status !== "Saved scan"
                        ? status
                        : "No packages in the saved scan."
                }
                topRight={`${rows.length} packages`}
            />
        </div>
    );
}

export function InstanceDependenciesPage({ instanceId }: { instanceId: number }) {
    const inventory = useQuery({
        ...instanceDependenciesQuery(instanceId),
        refetchInterval: useFallbackPoll(),
    });
    if (inventory.isPending)
        return (
            <Frame title="Dependencies">
                <Note>Loading saved dependencies…</Note>
            </Frame>
        );
    if (inventory.isError)
        return (
            <Frame title="Dependencies">
                <p role="alert" className="whitespace-normal text-red">
                    Could not load dependencies: {inventory.error.message}
                </p>
            </Frame>
        );
    return (
        <div className="flex h-full min-h-0 min-w-0 flex-col gap-y-[var(--panel-gap)]">
            <Inventory title="Composer" inventory={inventory.data.composer} order={1} />
            <Inventory title="npm" inventory={inventory.data.javascript} order={2} />
        </div>
    );
}

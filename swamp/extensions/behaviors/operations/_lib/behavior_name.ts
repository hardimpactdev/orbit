// A behavior is named after its target, never its run (docs/flows/README.md):
// `<family>-<SSH address or cfgmgmt nodeHost>`, the fan-out index when items share that target,
// and a fixed `<family>` for a local step.
export function behaviorName(
    family: string,
    globalArgs: unknown,
    each = false,
    extra: string[] = [],
): string {
    const args = globalArgs as
        | { hosts?: { address?: unknown }[]; nodeHost?: unknown }
        | undefined;
    const address = String(args?.hosts?.[0]?.address ?? args?.nodeHost ?? "");
    const target = /^\$\{\{\s*(.*?)\s*\}\}$/s.exec(address)?.[1];
    const parts = [
        ...(target ? [`string(${target})`] : []),
        ...extra,
        ...(each && !target?.includes("self.") ? ["string(self._index)"] : []),
    ];
    return "${{ " +
        (parts.length
            ? JSON.stringify(family + "-") + " + " + parts.join(' + "-" + ')
            : JSON.stringify(family)) +
        " }}";
}

// A behavior is named after its target, never its run (docs/flows/README.md):
// `<family>-<SSH address or cfgmgmt nodeHost>`, the fan-out index when items share that target,
// and a fixed `<family>` for a local step.
/** Swamp names allow no dots, so an address becomes `10-44-0-2`. */
export const dotless = (name: string) => `(${name}).split(".").join("-")`;

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
    if (!parts.length) return "${{ " + JSON.stringify(family) + " }}";
    const name = JSON.stringify(family + "-") + " + " + parts.join(' + "-" + ');
    return parts.every((part) => part === "string(self._index)")
        ? "${{ " + name + " }}"
        : "${{ " + dotless(name) + " }}";
}

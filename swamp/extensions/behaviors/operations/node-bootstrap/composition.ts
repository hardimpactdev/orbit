type Fields = Record<string, unknown>;
const expr = (source: string) => "${{ " + source + " }}";

/** Select optional intent retained by a preceding job; preserve native inner fanout. */
export function selectEnrollmentSteps(
    steps: Fields[],
    selection: string,
    predicate?: string,
): Fields[] {
    const scalarNames = steps.filter((step) => !step.forEach).map((step) =>
        String(step.name)
    );
    const bind = (value: unknown, bareExpression = false): unknown => {
        if (typeof value === "string") {
            if (!bareExpression && !value.startsWith("${{ ")) return value;
            let result = value;
            for (const name of scalarNames) {
                const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
                result = result.replace(
                    new RegExp(`steps\\.${escaped}\\b`, "g"),
                    `steps["${name}-0"]`,
                )
                    .replaceAll(`steps["${name}"]`, `steps["${name}-0"]`)
                    .replaceAll(`"${name}" in steps`, `"${name}-0" in steps`);
            }
            return result;
        }
        if (Array.isArray(value)) return value.map((item) => bind(item));
        if (value !== null && typeof value === "object") {
            return Object.fromEntries(
                Object.entries(value).map(([key, item]) => [
                    key,
                    bind(item, key === "expr"),
                ]),
            );
        }
        return value;
    };
    for (const step of steps) {
        step.task = bind(step.task);
        if (predicate) {
            step.guard = expr(
                "!(" + predicate + ")" +
                    (step.guard
                        ? " || (" + String(step.guard).slice(4, -3) + ")"
                        : ""),
            );
        }
        if (step.guard) step.guard = bind(step.guard);
        if (step.forEach) {
            const each = step.forEach as Fields;
            each.in = bind(
                expr(
                    selection + " ? (" + String(each.in).slice(4, -3) +
                        ") : []",
                ),
            );
        } else {
            step.forEach = {
                item: "enrollment",
                in: expr(selection + " ? [{}] : []"),
            };
        }
    }
    return steps;
}

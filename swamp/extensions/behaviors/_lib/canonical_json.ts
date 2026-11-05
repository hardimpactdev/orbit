const encoder = new TextEncoder();

export async function sha256(value: string): Promise<string> {
    return Array.from(
        new Uint8Array(
            await crypto.subtle.digest("SHA-256", encoder.encode(value)),
        ),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("");
}

/** Closed-schema canonical JSON: ASCII keys, safe integers, well-formed strings. */
export function canonical(value: unknown): string {
    if (Array.isArray(value)) return `[${value.map(canonical).join(",")}]`;
    if (value !== null && typeof value === "object") {
        const r = value as Record<string, unknown>;
        return `{${
            Object.keys(r).sort().map((k) =>
                `${JSON.stringify(k)}:${canonical(r[k])}`
            ).join(",")
        }}`;
    }
    if (typeof value === "number" && !Number.isSafeInteger(value)) {
        throw new Error("unsafe integer");
    }
    if (typeof value === "string" && !value.isWellFormed()) {
        throw new Error("invalid Unicode");
    }
    return JSON.stringify(value);
}

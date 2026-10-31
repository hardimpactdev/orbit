/** Pure shared Metrics validation and typed errors. */
export class LocalMetricsError extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}
export function ipv4(value: unknown): value is string {
  return typeof value === "string" &&
    /^(0|[1-9]\d{0,2})(\.(0|[1-9]\d{0,2})){3}$/.test(value) &&
    value.split(".").every((part) => Number(part) < 256);
}

export function sameState(a: unknown, b: unknown): boolean {
  if (a === b) return true;
  if (!a || !b || typeof a !== "object" || typeof b !== "object") return false;
  const entries = Object.entries(a);
  return entries.length === Object.keys(b).length &&
    entries.every(([key, value]) =>
      key in b && sameState(value, (b as Record<string, unknown>)[key])
    );
}

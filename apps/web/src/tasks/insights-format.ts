import { formatCompactCount } from "../api/tasks";

/** Whether a higher value is an improvement. Tokens, cost, time, and waste are better when lower. */
export type Better = "lower" | "higher";
export type Change = { label: string; tone: "good" | "bad" | "flat" };

/**
 * The change against the previous period. Shares change in percentage points, everything else in
 * percent. Null when either period has no value, or the previous value is zero.
 */
export function change(
    current: number | null,
    previous: number | null,
    better: Better,
    share = false,
): Change | null {
    if (current === null || previous === null) return null;
    const delta = share
        ? (current - previous) * 100
        : previous === 0
          ? null
          : ((current - previous) / previous) * 100;
    if (delta === null) return null;
    const rounded = Math.round(delta);
    if (rounded === 0) return { label: share ? "±0pt" : "±0%", tone: "flat" };
    const improved = better === "lower" ? rounded < 0 : rounded > 0;

    return {
        label: `${rounded > 0 ? "▲" : "▼"}${Math.abs(rounded)}${share ? "pt" : "%"}`,
        tone: improved ? "good" : "bad",
    };
}

export function formatShare(value: number | null): string {
    return value === null ? "—" : `${Math.round(value * 100)}%`;
}

export function formatUsd(value: number | null): string {
    if (value === null) return "—";

    return value >= 100 ? `$${Math.round(value)}` : `$${value.toFixed(2)}`;
}

export function formatCount(value: number | null): string {
    return formatCompactCount(value) ?? "—";
}

export function formatRatio(value: number | null): string {
    return value === null ? "—" : value.toFixed(1);
}

/** A duration in its two largest units: "45m", "4h 12m", "2d 3h". */
export function formatSpan(ms: number | null): string {
    if (ms === null) return "—";
    const minutes = Math.round(ms / 60_000);
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return minutes % 60 === 0 ? `${hours}h` : `${hours}h ${minutes % 60}m`;
    const days = Math.floor(hours / 24);

    return hours % 24 === 0 ? `${days}d` : `${days}d ${hours % 24}h`;
}

const causeLabels: Record<string, string> = {
    brief_unclear: "brief unclear",
    contract_gap: "contract gap",
    scope: "scope",
    environment: "environment",
    missed_contract: "missed contract",
};

/** "scope 3 · environment 1", largest first. */
export function formatCauses(causes: Record<string, number>): string {
    const entries = Object.entries(causes)
        .filter(([, count]) => count > 0)
        .sort(([, a], [, b]) => b - a);
    if (entries.length === 0) return "No questions";

    return entries.map(([cause, count]) => `${causeLabels[cause] ?? cause} ${count}`).join(" · ");
}

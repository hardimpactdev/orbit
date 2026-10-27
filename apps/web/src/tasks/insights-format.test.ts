import { describe, expect, it } from "vite-plus/test";
import { change, formatCauses, formatShare, formatSpan, formatUsd } from "./insights-format";

describe("change", () => {
    it("colours a fall green when lower is better and red when higher is better", () => {
        expect(change(88, 100, "lower")).toEqual({ label: "▼12%", tone: "good" });
        expect(change(88, 100, "higher")).toEqual({ label: "▼12%", tone: "bad" });
    });

    it("measures shares in percentage points", () => {
        expect(change(0.71, 0.67, "higher", true)).toEqual({ label: "▲4pt", tone: "good" });
        expect(change(0.18, 0.16, "lower", true)).toEqual({ label: "▲2pt", tone: "bad" });
        expect(change(0.5, 0.5, "lower", true)).toEqual({ label: "±0pt", tone: "flat" });
    });

    it("shows no change without both values or from zero", () => {
        expect(change(null, 10, "lower")).toBeNull();
        expect(change(10, null, "lower")).toBeNull();
        expect(change(10, 0, "lower")).toBeNull();
    });
});

it("formats spans, shares, cost, and question causes", () => {
    expect([45, 60, 252, 1_440, 1_620].map((minutes) => formatSpan(minutes * 60_000))).toEqual([
        "45m",
        "1h",
        "4h 12m",
        "1d",
        "1d 3h",
    ]);
    expect(formatSpan(null)).toBe("—");
    expect(formatShare(0.705)).toBe("71%");
    expect(formatUsd(31.2727)).toBe("$31.27");
    expect(formatUsd(688.4)).toBe("$688");
    expect(formatUsd(null)).toBe("—");
    expect(formatCauses({ contract_gap: 0, environment: 4, scope: 7 })).toBe(
        "scope 7 · environment 4",
    );
    expect(formatCauses({ scope: 0 })).toBe("No questions");
});

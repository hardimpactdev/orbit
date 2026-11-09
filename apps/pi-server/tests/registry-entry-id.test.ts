import { describe, expect, it } from "vite-plus/test";
import { durableEntryId } from "../src/registry.ts";

describe("durableEntryId", () => {
    it("reads the Durable entry of a later message in the same entry", () => {
        expect(durableEntryId("12")).toBe(12);
        expect(durableEntryId("12:1")).toBe(12);
        expect(durableEntryId("12:1")).toBeGreaterThan(11);
    });
});

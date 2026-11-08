import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import { describe, expect, it } from "vite-plus/test";

// Use the JSON Schema validator already installed by the API type generator.
const apiRequire = createRequire(new URL("../tools/api-types/package.json", import.meta.url));
const openapiRequire = createRequire(apiRequire.resolve("openapi-typescript"));
const redoclyRequire = createRequire(openapiRequire.resolve("@redocly/openapi-core"));
const Ajv = redoclyRequire("@redocly/ajv").default as new (options: { strict: boolean }) => {
    compile(schema: object): (input: unknown) => boolean;
};
const manifest = JSON.parse(
    readFileSync(new URL("../../gateway/resources/mcp/tools.json", import.meta.url), "utf8"),
) as { tools: { name: string; input_schema: object }[] };
const schema = manifest.tools.find((tool) => tool.name === "task-update")!.input_schema;
const validate = new Ajv({ strict: false }).compile(schema);

describe("generated task-update MCP schema", () => {
    it.each([
        { task: "123", title: "Edited" },
        { task: "123", preview: true, status: "backlog" },
        { task: "124", title: "Edited child", position: 2, status: "todo" },
    ])("accepts a task selector with parent or child update fields: %j", (input) => {
        expect(validate(input)).toBe(true);
    });

    it.each([
        { title: "Missing selector" },
        { task: "123", preview: true, position: 2 },
        { task: "124", position: 2, status: "backlog" },
        { task: "124", position: 0 },
        { task: "123", unknown: true },
    ])("rejects missing selectors, mixed levels, and invalid fields: %j", (input) => {
        expect(validate(input)).toBe(false);
    });
});

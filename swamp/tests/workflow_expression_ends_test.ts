import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { swampExpressions, unclosed } from "./_support/swamp_expression.ts";

const workflows = new URL("../workflows/", import.meta.url);

/** Every string a workflow file hands Swamp, with its YAML path. */
function strings(value: unknown, path: string, out: [string, string][] = []): [string, string][] {
  if (typeof value === "string") out.push([path, value]);
  else if (Array.isArray(value)) value.forEach((item, i) => strings(item, `${path}[${i}]`, out));
  else if (value !== null && typeof value === "object") {
    for (const [key, item] of Object.entries(value)) strings(item, path === "" ? key : `${path}.${key}`, out);
  }
  return out;
}

/** The `${{ … }}` expressions in one YAML text that Swamp would end before their brackets or quotes close. */
export function earlyEnds(text: string): string[] {
  const found: string[] = [];
  for (const [path, value] of strings(parse(text), "")) {
    for (const expression of swampExpressions(value)) {
      const reason = unclosed(expression);
      if (reason !== null) found.push(`${path}: ${reason}`);
    }
  }
  return found;
}

Deno.test("no workflow expression holds a `}}` that ends it before its end", () => {
  const found: string[] = [];
  for (const entry of Deno.readDirSync(workflows)) {
    if (!/\.ya?ml$/.test(entry.name)) continue;
    for (const early of earlyEnds(Deno.readTextFileSync(new URL(entry.name, workflows)))) found.push(`${entry.name}: ${early}`);
  }
  assertEquals(found, []);
});

Deno.test("a map or string closed with `}}` inside an expression is found, and `} }}` passes", () => {
  // The finish line of #3581, which reached the finish step as a string in Operations 19600 and 19602.
  const broken = `x: '\${{ s ? {"status": "succeeded", "outputs": {"stdout": s.outputs.stdout}} : dyn(null) }}'`;
  assertEquals(earlyEnds(broken), ["x: unclosed {"]);
  assertEquals(earlyEnds(broken.replace("stdout}} :", "stdout} } :")), []);
  assertEquals(earlyEnds(`x: '\${{ "}}" }}'`), ["x: unclosed \""]);
  assertEquals(earlyEnds(`x: 'a \${{ [1, {"b": 2}] }} and \${{ {"c": [3]} }}'`), []);
});

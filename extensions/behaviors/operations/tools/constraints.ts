// @deno-types="npm:@types/semver@7.7.1"
import { Range, satisfies } from "npm:semver@7.7.2";

/** Composer numeric ranges; node-semver owns every version comparison. */
export function constraintMatches(raw: string): (version: string) => boolean {
  if (
    !raw.trim() || raw.length > 255 ||
    Array.from(raw).some((c) => c.charCodeAt(0) < 32 || c.charCodeAt(0) === 127)
  ) {
    throw new Error("Invalid Tool constraint");
  }
  const options = { includePrerelease: true };
  const rangeFor = (value: string) => {
    const parsed = new Range(value, options);
    return new Range(
      parsed.set.map((group) =>
        group.map((term) =>
          term.value.replace(/^(>=|<)(\d+\.\d+\.\d+)$/, "$1$2-0")
        ).join(" ")
      ).join(" || "),
      options,
    );
  };
  const alternatives = raw.split(/\s*\|\|?\s*/).map((alternative) => {
    if (!alternative.trim()) throw new Error("Empty constraint alternative");
    if (/\s-\s/.test(alternative)) {
      const range = rangeFor(alternative);
      return (version: string) => satisfies(version, range, options);
    }
    const terms = alternative.replace(/([<>=!~^]+)\s+/g, "$1")
      .split(/[ ,]+/).filter(Boolean).map((term) => {
        const match = /^(\^|~|>=|<=|!=|<>|>|<|==|=)?v?(\d+(?:\.\d+){0,2})$/
          .exec(
            term,
          );
        let value = term;
        let excluded = false;
        if (match) {
          const operator = match[1] ?? "";
          const digits = match[2].split(".");
          const version = [...digits, ...Array(3 - digits.length).fill("0")]
            .join(".");
          excluded = operator === "!=" || operator === "<>";
          value = excluded || ["", "=", "=="].includes(operator)
            ? `=${version}`
            : operator === "~" && digits.length < 3
            ? `>=${version}-0 <${Number(digits[0]) + 1}.0.0-0`
            : `${operator}${version}`;
        } else if (/^(?:!=|<>)/.test(term)) {
          excluded = true;
          value = `=${term.slice(2)}`;
        }
        const range = rangeFor(value);
        return (version: string) =>
          satisfies(version, range, options) !== excluded;
      });
    if (!terms.length) throw new Error("Empty constraint group");
    return (version: string) => terms.every((term) => term(version));
  });
  return (version: string) => alternatives.some((test) => test(version));
}

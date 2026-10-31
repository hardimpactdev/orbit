/** Text scanners ported from Horizon New Dawn's scripts/horizon-sync. */
export type TextMap = Record<string, string>;
export type Change = { change: "add" | "modify" | "delete"; id: string };
export type Item = Change & {
  surface: "route" | "controller" | "screen" | "config";
};

export function routes(source: string): TextMap {
  const result: TextMap = {};
  const pattern = /Route::(get|post|put|patch|delete)\b/g;
  let match;
  while ((match = pattern.exec(source))) {
    const start = match.index;
    let index = start, quote: string | null = null, escaped = false;
    for (; index < source.length; index++) {
      const char = source[index];
      if (quote !== null) {
        if (escaped) escaped = false;
        else if (char === "\\" && quote === "'") escaped = true;
        else if (char === quote) quote = null;
        continue;
      }
      if (char === "'" || char === '"') {
        quote = char;
        continue;
      }
      if (char === ";") {
        index++;
        break;
      }
    }
    const statement = source.slice(start, index);
    const name = /->name\(\s*'([^']+)'/.exec(statement);
    if (!name) throw new Error("Horizon route has no name");
    result[`${match[1].toUpperCase()} ${name[1]}`] = statement.trim().replace(
      /\s+/g,
      " ",
    );
    pattern.lastIndex = index;
  }
  return result;
}

export function actions(source: string, path: string): TextMap {
  const className = path.split("/").at(-1)!.replace(/\.php$/, "");
  const result: TextMap = {};
  const pattern =
    /public function (?!__construct\b)([A-Za-z_][A-Za-z0-9_]*)\s*\(/g;
  let match;
  while ((match = pattern.exec(source))) {
    const start = match.index, name = `${className}@${match[1]}`;
    let index = source.indexOf("(", start), depth = 0;
    if (index === -1) {
      throw new Error(
        `Horizon controller action has no parameter list: ${name}`,
      );
    }
    for (; index < source.length; index++) {
      if (source[index] === "(") depth++;
      else if (source[index] === ")" && --depth === 0) {
        index++;
        break;
      }
    }
    while (index < source.length && source[index] !== "{") index++;
    if (index >= source.length) {
      throw new Error(`Horizon controller action has no body: ${name}`);
    }
    depth = 0;
    for (; index < source.length; index++) {
      if (source[index] === "{") depth++;
      else if (source[index] === "}" && --depth === 0) {
        index++;
        break;
      }
    }
    result[name] = source.slice(start, index);
    pattern.lastIndex = index;
  }
  return result;
}

export class ConfigKeys {
  private index = 0;
  constructor(private source: string) {}
  all(): TextMap {
    this.skip();
    let found = false;
    while (this.index < this.source.length) {
      this.skip();
      if (this.startsWith("return")) {
        this.index += 6;
        this.skip();
        if (this.char() === "[") {
          found = true;
          break;
        }
      }
      this.index++;
    }
    if (!found) throw new Error("Horizon config has no return array");
    return this.array("");
  }
  private array(prefix: string): TextMap {
    this.expect("[");
    const keys: TextMap = {};
    while (this.index < this.source.length) {
      this.skip();
      const char = this.char();
      if (char === "]") {
        this.index++;
        break;
      }
      if (char === ",") {
        this.index++;
        continue;
      }
      if (char !== "'" && char !== '"') {
        this.value();
        continue;
      }
      const text = this.string();
      this.skip();
      if (!this.startsWith("=>")) continue;
      this.index += 2;
      this.skip();
      if (/^\d+$/.test(text)) {
        if (this.char() === "[") this.array("");
        else this.value();
        continue;
      }
      const path = prefix === "" ? text : `${prefix}.${text}`;
      const start = this.index;
      if (this.char() === "[") Object.assign(keys, this.array(path));
      else this.value();
      keys[path] = this.source.slice(start, this.index);
    }
    return keys;
  }
  private value(): void {
    let paren = 0, bracket = 0;
    const start = this.index;
    while (this.index < this.source.length) {
      this.skip();
      const char = this.char();
      if (char === "'" || char === '"') {
        this.string();
        continue;
      }
      if (char === "(") {
        paren++;
        this.index++;
        continue;
      }
      if (char === ")") {
        if (paren === 0 && bracket === 0) return;
        paren--;
        this.index++;
        continue;
      }
      if (char === "[") {
        bracket++;
        this.index++;
        continue;
      }
      if (char === "]") {
        if (bracket === 0 && paren === 0) return;
        bracket--;
        this.index++;
        continue;
      }
      if (char === "," && paren === 0 && bracket === 0) return;
      this.index++;
      if (this.index > start + 100000) {
        throw new Error("Horizon config value is too large");
      }
    }
  }
  private string(): string {
    const quote = this.char();
    this.index++;
    let text = "";
    while (this.index < this.source.length) {
      const char = this.char();
      this.index++;
      if (char === "\\" && quote === "'") {
        text += this.char();
        this.index++;
        continue;
      }
      if (char === quote) return text;
      text += char;
    }
    throw new Error("Horizon config string does not end");
  }
  private skip(): void {
    while (this.index < this.source.length) {
      const char = this.char();
      if (/[ \n\r\t]/.test(char)) {
        this.index++;
        continue;
      }
      if (this.startsWith("//") || char === "#") {
        while (this.index < this.source.length && this.char() !== "\n") {
          this.index++;
        }
        continue;
      }
      if (this.startsWith("/*")) {
        const end = this.source.indexOf("*/", this.index + 2);
        if (end === -1) throw new Error("Horizon config comment does not end");
        this.index = end + 2;
        continue;
      }
      return;
    }
  }
  private expect(token: string): void {
    this.skip();
    if (!this.startsWith(token)) {
      throw new Error("Unexpected Horizon config token");
    }
    this.index += token.length;
  }
  private startsWith(token: string): boolean {
    return this.source.startsWith(token, this.index);
  }
  private char(): string {
    return this.source[this.index] ?? "";
  }
}

export const compareText = (a: string, b: string): number =>
  a < b ? -1 : a > b ? 1 : 0;
export function diffText(before: TextMap, after: TextMap): Change[] {
  const items: Change[] = [];
  for (const [id, text] of Object.entries(after)) {
    if (!Object.hasOwn(before, id)) items.push({ change: "add", id });
    else if (before[id] !== text) items.push({ change: "modify", id });
  }
  for (const id of Object.keys(before)) {
    if (!Object.hasOwn(after, id)) items.push({ change: "delete", id });
  }
  return items.sort((a, b) => compareText(a.id, b.id));
}
export function diffConfig(before: TextMap, after: TextMap): Change[] {
  const changes = diffText(before, after);
  return changes.filter(({ id, change }) =>
    !changes.some((other) =>
      other.id !== id &&
      ((other.id.startsWith(id + ".") && change === "modify") ||
        (id.startsWith(other.id + ".") &&
          ["add", "delete"].includes(other.change)))
    )
  );
}

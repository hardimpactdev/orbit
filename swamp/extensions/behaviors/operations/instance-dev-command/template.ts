/** Match the strict Instance importer: ordered references, no duplicate or undefined values. */
export function parseTemplate(raw: string): Record<string, string> {
    const fail = (): never => {
        throw new Error("env.import_invalid");
    };
    const values: Record<string, string> = {};
    const source = raw.replace(/^\uFEFF/, "").replaceAll("\r\n", "\n");
    const entry = /(?:export[ \t]+)?([A-Za-z0-9_.]+)[ \t]*=[ \t]*/y;
    let position = 0;
    while (position < source.length) {
        while (
            /[ \t\r\n]/.test(source[position] ?? "") && position < source.length
        ) position++;
        if (position === source.length) break;
        if (source[position] === "#") {
            position = source.indexOf("\n", position);
            if (position < 0) break;
            continue;
        }
        entry.lastIndex = position;
        const match = entry.exec(source);
        if (!match || Object.hasOwn(values, match[1])) fail();
        position = entry.lastIndex;
        const key = match![1];
        let value = "";
        const quote = source[position] === "'" || source[position] === '"'
            ? source[position++]
            : null;
        let closed = quote === null;
        while (position < source.length) {
            const c = source[position++];
            if (quote && c === quote) {
                closed = true;
                break;
            }
            if (!quote && (c === "#" || /[ \t\r\n]/.test(c))) {
                position--;
                break;
            }
            if (c === "\\" && quote === '"') {
                const next = source[position++];
                const escapes: Record<string, string> = {
                    '"': '"',
                    "\\": "\\",
                    "$": "$",
                    n: "\n",
                    r: "\r",
                    t: "\t",
                    f: "\f",
                    v: "\v",
                };
                if (!Object.hasOwn(escapes, next)) fail();
                value += escapes[next];
                continue;
            }
            if (c === "$" && quote !== "'") {
                const reference = /^\{([A-Za-z0-9_.]+)\}/.exec(
                    source.slice(position),
                );
                if (!reference || !Object.hasOwn(values, reference[1])) fail();
                value += values[reference![1]];
                position += reference![0].length;
                continue;
            }
            value += c;
        }
        if (!closed) fail();
        while (
            /[ \t\r]/.test(source[position] ?? "") && position < source.length
        ) position++;
        if (source[position] === "#") {
            const end = source.indexOf("\n", position);
            position = end < 0 ? source.length : end;
        }
        if (position < source.length && source[position] !== "\n") fail();
        values[key] = value;
        position++;
    }
    return values;
}

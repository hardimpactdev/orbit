/*
 * The one redaction rule for task VM command output on Node 209.
 *
 * A task VM command writes its output to a file on the VM; official
 * `@swamp/ssh copy` downloads that file into a private worker directory, and
 * the method that shapes the result reads it here. Raw output therefore never
 * reaches native run history: only the redacted, bounded text this module
 * returns does.
 *
 * The text rule is task-turn's `public_output.py`; the bounded tail is the
 * setup rule of `run_project_step.py` (#2355, #2370): normalize invalid UTF-8
 * before matching, redact known values in one pass across the whole capture,
 * drop a first line that may start mid-value after discarded bytes and every
 * line that still holds an 8-byte fragment of a known value, and keep only
 * whole lines. A line longer than the room left is replaced by a marker that
 * names its size, so one long line never hides the lines before it.
 */

export const REDACTED = "[REDACTED]";
/** The most output read from one file; older bytes are discarded. */
export const CAPTURE_BYTES = 1048576;
/** The shortest known value that is replaced; shorter ones would blank ordinary words. */
export const SHORTEST_VALUE = 8;
const FRAGMENT = 8;

const encoder = new TextEncoder();
// Invalid UTF-8 becomes U+FFFD before matching, so no dropped byte can join
// two harmless pieces into a secret.
const decoder = new TextDecoder("utf-8", { fatal: false });

export type RedactionOptions = {
    /** Exact known secret values, such as stored environment values; each is replaced whole. */
    values?: readonly string[];
    /**
     * Replace free-standing 64-character hex strings, the format of Pi and
     * model-router credentials. Structured evidence that legitimately holds
     * SHA-256 object IDs or digests turns this off and is refused instead.
     */
    credentialHex?: boolean;
};

/*
 * Secret field names: the Gateway rule of `CommandActivityInputSanitizer`, mirrored here because
 * Swamp cannot call PHP. A name is secret when one of its snake_case segments is a secret word,
 * unless it is one of the audited public names. Change both lists together.
 */
const SECRET_WORDS = new Set([
    "key",
    "keys",
    "token",
    "tokens",
    "secret",
    "secrets",
    "password",
    "passwords",
    "passwd",
    "passphrase",
    "credential",
    "credentials",
    "bearer",
    "pem",
    "apikey",
    "appkey",
    "authtoken",
    "accesstoken",
    "privatekey",
    "authorization",
    "cookie",
    "cookies",
    "session",
    "sessionid",
    "phpsessid",
]);
const NON_SECRET_KEYS = new Set([
    "public_key",
    "wireguard_public_key",
    "ssh_public_key",
    "public_pem",
    "host_key_fingerprint",
    "runtime_key",
    "env_key",
    "idempotency_key",
    "token_expires_at",
]);
/** Secret-named fields whose numeric values are usage counts, such as `tokens` or `token_count`. */
const NUMERIC_METRIC_SUFFIXES = new Set(["tokens", "count"]);
/** Headers whose whole line is a credential, scheme included. */
const CREDENTIAL_HEADERS = new Set([
    "authorization",
    "proxy_authorization",
    "cookie",
    "set_cookie",
]);

const segments = (name: string): string[] =>
    name.replace(/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/g, "_")
        .replace(/[^A-Za-z0-9]+/g, "_").replace(/^_+|_+$/g, "").toLowerCase()
        .split("_");

/** The Gateway field rule: a structured field with this name holds a secret. */
function secretParts(parts: string[]): boolean {
    return !NON_SECRET_KEYS.has(parts.join("_")) &&
        parts.some((part) => SECRET_WORDS.has(part));
}

export function secretField(name: string): boolean {
    return secretParts(segments(name));
}

/** In text a bare `key` is prose, so it needs a prefix, as in the Gateway text rule. */
function secretName(parts: string[]): boolean {
    return secretParts(parts) &&
        !(parts.length === 1 && (parts[0] === "key" || parts[0] === "keys"));
}

/**
 * A field name with its optional, possibly escaped, quotes and its separator. A name never
 * starts inside a word or right after a backslash, but may follow an escape such as `\n`.
 */
const FIELD =
    /(?<!\\|(?<!\\)[A-Za-z0-9_.-])(\\*["'])?([A-Za-z_][A-Za-z0-9_.-]{0,255})(\1)?([ \t]*(?:=>?|:(?!\/\/))[ \t]*)/g;

/** The index after the quote that closes a string opened by `quote` after `escapes` backslashes. */
function stringEnd(
    text: string,
    from: number,
    escapes: number,
    quote: string,
): number {
    for (let i = from; i < text.length; i++) {
        if (text[i] === "\n") return i; // an unclosed string ends with its line
        if (text[i] !== quote) continue;
        let before = 0;
        while (text[i - 1 - before] === "\\") before++;
        if (escapes === 0 ? before % 2 === 0 : before === escapes) return i + 1;
    }
    return text.length;
}

/** The index after a JSON-like object or array; an unbalanced one runs to the end. */
function bracketEnd(text: string, from: number): number {
    let depth = 0;
    for (let i = from; i < text.length; i++) {
        const char = text[i];
        if (char === '"' || char === "'") {
            let before = 0;
            while (text[i - 1 - before] === "\\") before++;
            if (before % 2 === 0) i = stringEnd(text, i + 1, 0, char) - 1;
        } else if (char === "{" || char === "[") depth++;
        else if ((char === "}" || char === "]") && --depth === 0) return i + 1;
    }
    return text.length;
}

/** The index after the value that starts at `from` under a secret field. */
function valueEnd(
    text: string,
    from: number,
    header: boolean,
    opener: string | undefined,
): number {
    const quoted = /^(\\*)(["'])/.exec(text.slice(from, from + 256));
    let end: number;
    if (quoted) {
        return stringEnd(
            text,
            from + quoted[0].length,
            quoted[1].length,
            quoted[2],
        );
    } else if (header) {
        const line = text.indexOf("\n", from);
        end = line < 0 ? text.length : line;
    } else if (text[from] === "{" || text[from] === "[") {
        return bracketEnd(text, from);
    } else {
        // A credential scheme belongs to its value, as in `token: Bearer abc`.
        const scheme = /^(?:Bearer|Basic|Digest|Token|Bot)[ \t]+/i.exec(
            text.slice(from, from + 16),
        );
        const start = from + (scheme ? scheme[0].length : 0);
        end = start +
            /^[^\s,;&}\])"\\]*/.exec(text.slice(start))![0].length;
    }
    // A field that a quote opened, as in `-H "Cookie: a=b"`, ends at the matching quote.
    if (!opener) return end;
    const close = stringEnd(text, from, opener.length - 1, opener.at(-1)!);
    return close <= end && text[close - 1] === opener.at(-1)
        ? close - opener.length
        : end;
}

/**
 * Redact the value of every secret-named field in JSON-like and key/value text: objects and
 * arrays, `key: value`, `key=value`, HTTP header lines and quoted or escaped strings, however
 * nested. The scan continues inside every public value, so a nested secret field is found too.
 */
function replaceFields(text: string): string {
    let out = "", copied = 0;
    // Names repeat in command output. Cache only their policy for this capture,
    // never a value or its context-dependent end position.
    const fields = new Map<string, {
        secret: boolean;
        header: boolean;
        numericMetric: boolean;
    }>();
    FIELD.lastIndex = 0;
    for (let match; (match = FIELD.exec(text));) {
        const [whole, opener, name, closer] = match;
        const from = match.index + whole.length;
        let field = fields.get(name);
        if (!field) {
            const parts = segments(name);
            field = {
                secret: secretName(parts),
                header: CREDENTIAL_HEADERS.has(parts.join("_")),
                numericMetric: NUMERIC_METRIC_SUFFIXES.has(parts.at(-1)!),
            };
            fields.set(name, field);
        }
        if (!field.secret) {
            FIELD.lastIndex = from;
            continue;
        }
        const header = !closer && whole.trimEnd().endsWith(":") &&
            field.header;
        const end = valueEnd(text, from, header, closer ? undefined : opener);
        const value = text.slice(from, end);
        const kept = value === "" || /^(?:true|false|null)$/.test(value) ||
            (/^-?\d+(?:\.\d+)?$/.test(value) &&
                field.numericMetric);
        if (!kept) {
            out += text.slice(copied, from) + REDACTED;
            copied = end;
        }
        FIELD.lastIndex = Math.max(end, from + 1);
    }
    return out + text.slice(copied);
}

/** Known values of at least eight characters, longest first, without duplicates. */
function knownValues(values: readonly string[] = []): string[] {
    return [...new Set(values.filter((v) => v.length >= SHORTEST_VALUE))]
        .sort((a, b) => encoder.encode(b).length - encoder.encode(a).length);
}

const escape = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/** One pass over all known values, so a marker is never matched again. */
function replaceValues(text: string, values: string[]): string {
    if (values.length === 0) return text;
    return text.replace(
        new RegExp(values.map(escape).join("|"), "g"),
        REDACTED,
    );
}

/** Credential shapes first, then the values of secret-named fields. */
function replacePatterns(raw: string, credentialHex: boolean): string {
    let text = credentialHex
        ? raw.replace(
            /(?<![A-Za-z0-9])[a-fA-F0-9]{64}(?![A-Za-z0-9])/g,
            REDACTED,
        )
        : raw;
    // Terminal escape sequences carry no evidence.
    // deno-lint-ignore no-control-regex
    text = text.replace(/\x1b\[[0-?]*[ -/]*[@-~]/g, "");
    text = text.replace(
        /-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|$)/g,
        REDACTED,
    );
    if (text.includes("-----END ")) {
        const tail = text.slice(text.lastIndexOf("-----END ") + 9);
        const close = tail.indexOf("-----");
        text = close < 0 ? tail : tail.slice(close + 5);
    }
    // A scheme starts only where no scheme character precedes it, so each run of
    // scheme characters is scanned once, not once per word boundary inside it.
    text = text.replace(
        /((?<![a-z0-9+.-])[a-z][a-z0-9+.-]*:\/\/)[^/@\s]+@/gi,
        "$1[REDACTED]@",
    );
    text = text.replace(/\bBearer\s+[A-Za-z0-9._+/=-]+/gi, "Bearer [REDACTED]");
    // The installation token has dots and dashes after `ghs_`, and a console error box wraps it over padded lines; a continuation is a piece with the box margin on both sides, so plain output after a token stays.
    text = text.replace(
        /(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9_.-]{16,}(?:[ \t]{2,}\r?\n[ \t]{2,}[A-Za-z0-9_.-]+(?=[\"']?[ \t]{2,}(?:\r?\n|$))){0,6}|github_pat_[A-Za-z0-9_]{20,})/g,
        REDACTED,
    );
    text = text.replace(
        /(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*/g,
        REDACTED,
    );
    return replaceFields(text);
}

/** Known values first, then the credential patterns. */
export function redactText(
    raw: string,
    options: RedactionOptions = {},
): string {
    return replacePatterns(
        replaceValues(raw, knownValues(options.values)),
        options.credentialHex ?? true,
    );
}

/** Every string inside a JSON-shaped value, redacted. */
export function redactValue(
    value: unknown,
    options: RedactionOptions = {},
): unknown {
    if (typeof value === "string") return redactText(value, options);
    if (Array.isArray(value)) {
        return value.map((item) => redactValue(item, options));
    }
    if (value !== null && typeof value === "object") {
        return Object.fromEntries(
            Object.entries(value).map((
                [key, item],
            ) => [key, redactValue(item, options)]),
        );
    }
    return value;
}

const contains = (haystack: Uint8Array, needle: Uint8Array) => {
    outer: for (let i = 0; i + needle.length <= haystack.length; i++) {
        for (let j = 0; j < needle.length; j++) {
            if (haystack[i + j] !== needle[j]) continue outer;
        }
        return true;
    }
    return false;
};

function holdsFragment(line: Uint8Array, values: Uint8Array[]): boolean {
    for (const value of values) {
        for (let offset = 0; offset + FRAGMENT <= value.length; offset++) {
            if (contains(line, value.subarray(offset, offset + FRAGMENT))) {
                return true;
            }
        }
    }
    return false;
}

export type PublicTail = {
    /** Fully redacted whole lines, at most `limit` UTF-8 bytes. */
    output: string;
    /** The command wrote more than `limit` bytes. */
    truncated: boolean;
};

/**
 * A fully redacted tail of command output, bounded by whole lines.
 * `discarded` says older bytes were already dropped before `raw`.
 */
export function publicTail(
    raw: Uint8Array | string,
    limit: number,
    options: RedactionOptions & { discarded?: boolean } = {},
): PublicTail {
    const bytes = typeof raw === "string" ? encoder.encode(raw) : raw;
    const values = knownValues(options.values);
    const redact = (text: string) =>
        redactText(text, { values, credentialHex: options.credentialHex });
    // The whole capture is redacted at once and split into whole lines, so no kept line starts mid-value.
    let lines = redact(decoder.decode(bytes)).split("\n");
    // The first line after discarded older bytes may start mid-value.
    if (options.discarded) lines = lines.slice(1);
    const fragments = values.map((value) => encoder.encode(value));
    const kept: string[] = [];
    let used = 0;
    for (const line of lines.reverse()) {
        const encoded = encoder.encode(line);
        if (holdsFragment(encoded, fragments)) continue; // dropped, not replaced
        const separator = kept.length ? 1 : 0;
        if (used + separator + encoded.length <= limit) {
            kept.push(line);
            used += separator + encoded.length;
            continue;
        }
        // A line longer than the room left is named, not cut, and older lines still fill the bound.
        const marker = `[a line of ${encoded.length} bytes omitted]`;
        if (used + separator + marker.length > limit) break; // whole lines only
        kept.push(marker);
        used += separator + marker.length;
    }
    return {
        output: kept.reverse().join("\n"),
        truncated: options.discarded === true || bytes.length > limit,
    };
}

/**
 * Read at most the last `CAPTURE_BYTES` of a downloaded output file, delete
 * it, and return its public tail. A missing file returns null: its command
 * did not run.
 */
export async function readPublicTail(
    path: string,
    limit: number,
    options: RedactionOptions = {},
): Promise<PublicTail | null> {
    const read = await readCapture(path, CAPTURE_BYTES);
    if (read === null) return null;
    return publicTail(read.bytes, limit, {
        ...options,
        discarded: read.size > CAPTURE_BYTES,
    });
}

/**
 * The last `bytes` of a downloaded file and its full size; the file is
 * deleted after reading. A missing file returns null.
 */
export async function readCapture(
    path: string,
    bytes: number,
): Promise<{ bytes: Uint8Array; size: number } | null> {
    let file: Deno.FsFile;
    try {
        file = await Deno.open(path, { read: true });
    } catch (error) {
        if (error instanceof Deno.errors.NotFound) return null;
        throw error;
    }
    try {
        const { size } = await file.stat();
        const start = Math.max(0, size - bytes);
        await file.seek(start, Deno.SeekMode.Start);
        const buffer = new Uint8Array(size - start);
        let offset = 0;
        while (offset < buffer.length) {
            const count = await file.read(buffer.subarray(offset));
            if (count === null) break;
            offset += count;
        }
        return { bytes: buffer.subarray(0, offset), size };
    } finally {
        file.close();
        await Deno.remove(path).catch(() => {});
    }
}

/**
 * The argv that runs `argv` on a task VM with its output in `<directory>/<name>`
 * instead of the SSH session, so official SSH captures nothing. The shell
 * text is fixed; the directory, name and command are positional arguments.
 * The directory is created private (0700). `stderr: "discard"` keeps only
 * stdout, for structured reports. The command's exit code is the step's.
 */
export function capturedArgv(
    directory: string,
    name: string,
    argv: readonly string[],
    stderr: "merge" | "discard" = "merge",
): string[] {
    const redirect = stderr === "merge" ? "2>&1" : "2>/dev/null";
    return [
        "/bin/bash",
        "-c",
        `mkdir -p -m 0700 -- "$1" && exec >"$1/$2" ${redirect} && shift 2 && exec "$@"`,
        "orbit-output",
        directory,
        name,
        ...argv,
    ];
}

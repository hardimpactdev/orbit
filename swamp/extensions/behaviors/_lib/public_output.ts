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
 * redact the tail again, drop a first line that may start mid-value and every
 * line that still holds an 8-byte fragment of a known value, and keep only
 * whole lines.
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

const SECRET_NAME =
    /TOKEN|SECRET|PASSWORD|PASSWD|PASSPHRASE|CREDENTIAL|APIKEY|APPKEY|PRIVATEKEY|KEYS?/i;

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

/** The credential-shaped text rule of `public_output.py`. */
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
    text = text.replace(
        /(\b[a-z][a-z0-9+.-]*:\/\/)[^/@\s]+@/gi,
        "$1[REDACTED]@",
    );
    text = text.replace(
        /(?:Proxy-)?Authorization\s*:[^\r\n]*/gi,
        "Authorization: [REDACTED]",
    );
    text = text.replace(/\bBearer\s+[A-Za-z0-9._+/=-]+/gi, "Bearer [REDACTED]");
    // The installation token has dots and dashes after `ghs_`, and a console error box wraps it over padded lines; a continuation is a piece with the box margin on both sides, so plain output after a token stays.
    text = text.replace(
        /(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9_.-]{16,}(?:[ \t]{2,}\r?\n[ \t]{2,}[A-Za-z0-9_.-]+(?=[\"']?[ \t]{2,}(?:\r?\n|$))){0,6}|github_pat_[A-Za-z0-9_]{20,})/g,
        REDACTED,
    );
    return text.replace(
        /(["']?)([A-Za-z][A-Za-z0-9_-]{0,255})\1(\s*[:=]\s*)(?:"[^"\r\n]*"|'[^'\r\n]*'|[^\s,}&{\[\]]+)/g,
        (match, quote: string, name: string, separator: string) =>
            SECRET_NAME.test(name)
                ? quote + name + quote + separator + REDACTED
                : match,
    );
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
    const whole = encoder.encode(redact(decoder.decode(bytes)));
    const cut = whole.length > limit;
    let lines = redact(
        decoder.decode(whole.subarray(Math.max(0, whole.length - limit))),
    )
        .split("\n");
    // The first line of a cut tail may start mid-value.
    if (options.discarded || cut) lines = lines.slice(1);
    const fragments = values.map((value) => encoder.encode(value));
    const kept: string[] = [];
    let used = 0;
    for (const line of lines.reverse()) {
        const encoded = encoder.encode(line);
        if (holdsFragment(encoded, fragments)) continue; // dropped, not replaced
        const size = encoded.length + (kept.length ? 1 : 0);
        if (used + size > limit) break; // whole lines only
        kept.push(line);
        used += size;
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

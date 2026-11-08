import { REDACTED, redactText, secretField } from "../../_lib/public_output.ts";

type ObjectValue = Record<string, unknown>;
type Entry = {
    id: string;
    kind: string;
    label: string;
    text: string;
    at: string;
};
const object = (value: unknown): ObjectValue =>
    value !== null && typeof value === "object" && !Array.isArray(value)
        ? value as ObjectValue
        : {};
const string = (value: unknown): string =>
    typeof value === "string" ? value : "";
const text = (value: unknown): string =>
    typeof value === "string"
        ? value
        : Array.isArray(value)
        ? value.filter((block) => object(block).type === "text")
            .map((block) => string(object(block).text)).join("\n")
        : "";

/**
 * The fixed class of a failed tool, read from the status line Pi ends its error text with.
 * Only the class is public; the diagnostic text never is.
 */
function failure(content: unknown): string {
    const line = text(content).trimEnd().split("\n").at(-1) ?? "";
    return /\btimed out\b|^timeout:/i.test(line)
        ? "timeout"
        : /\baborted\b/i.test(line)
        ? "aborted"
        : /^Command (?:exited with code [1-9]|terminated without an exit code)/
                .test(line)
        ? "exit_nonzero"
        : /\b(?:EACCES|EPERM)\b|permission denied|not permitted/i.test(line)
        ? "permission"
        : /\bENOENT\b|no such file|not found/i.test(line)
        ? "not_found"
        : "error";
}

/** Only message text and tool name/status/duration become public evidence. */
export function transcriptEntries(raw: unknown[]): Entry[] {
    const secrets: string[] = [];
    const values = (value: unknown): void => {
        if (typeof value === "string" && value !== "") secrets.push(value);
        else if (typeof value === "number") secrets.push(String(value));
        else if (Array.isArray(value)) value.forEach(values);
        else Object.values(object(value)).forEach(values);
    };
    const collect = (value: unknown): void => {
        if (Array.isArray(value)) value.forEach(collect);
        else {
            for (const [key, item] of Object.entries(object(value))) {
                if (secretField(key)) values(item);
                else collect(item);
            }
        }
    };
    raw.forEach(collect);
    const exact = [...new Set(secrets)].sort((a, b) => b.length - a.length)
        .map((value) => value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));
    const pattern = exact.length ? new RegExp(exact.join("|"), "g") : null;
    // Redact untrusted text only. Short secrets must never alter entry kinds,
    // IDs, roles, timestamps or generated status/duration text.
    const redact = (value: string) =>
        redactText(pattern ? value.replace(pattern, REDACTED) : value);
    const calls = new Map<string, { id: string; name: string; at: string }>();
    const entries = new Map<string, Entry>();
    const activity = (
        call: { id: string; name: string; at: string },
        status: string,
        at: string,
    ): void => {
        const duration = Date.parse(at) - Date.parse(call.at);
        const name = redact(call.name);
        entries.set(call.id, {
            id: call.id,
            at: at || call.at,
            kind: "activity",
            label: name,
            text: name + " · " + status +
                (Number.isFinite(duration) && duration >= 0
                    ? ` · ${duration} ms`
                    : ""),
        });
    };
    for (const value of raw) {
        const entry = object(value),
            id = string(entry.id),
            at = string(entry.timestamp);
        if (!id) continue;
        const message = object(entry.message), role = message.role;
        if (role === "user" || role === "assistant") {
            const content = text(message.content);
            // errorMessage is diagnostic output, not assistant message text.
            if (content) {
                entries.set(id, {
                    id,
                    at,
                    kind: "message",
                    label: role,
                    text: redact(content),
                });
            }
            if (role !== "assistant") continue;
            let index = 0;
            for (
                const rawBlock of Array.isArray(message.content)
                    ? message.content
                    : []
            ) {
                const block = object(rawBlock);
                if (block.type !== "toolCall" || typeof block.id !== "string") {
                    continue;
                }
                const call = {
                    id: id + ":" + index++,
                    name: string(block.name),
                    at,
                };
                calls.set(block.id, call);
                activity(call, "stopped without a result", "");
            }
        } else if (role === "toolResult") {
            const callId = string(message.toolCallId);
            const call = calls.get(callId) ?? {
                id,
                name: string(message.toolName) || "tool",
                at: "",
            };
            calls.delete(callId);
            // Never copy arguments, content, details or error text.
            activity(
                call,
                message.isError === true
                    ? "tool failed: " + failure(message.content)
                    : "succeeded",
                at,
            );
        }
    }
    return [...entries.values()];
}

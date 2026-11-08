import { REDACTED, redactText } from "../../_lib/public_output.ts";

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
const secretField = (name: string): boolean =>
    /(?:^|[_-])(?:keys?|tokens?|secrets?|passwords?|passwd|passphrase|credentials?|bearer|pem|apikey|appkey|authtoken|accesstoken|privatekey|authorization|cookies?)(?:$|[_-])/i
        .test(name.replace(/([a-z0-9])([A-Z])/g, "$1_$2"));

/** Normalize before persistence. File contents and unused tool arguments are never public evidence. */
export function transcriptEntries(raw: unknown[]): Entry[] {
    const secrets: string[] = [];
    const values = (value: unknown): void => {
        if (typeof value === "string" && value !== "") secrets.push(value);
        else if (typeof value === "number") secrets.push(String(value));
        else if (Array.isArray(value)) value.forEach(values);
        else Object.values(object(value)).forEach(values);
    };
    const sanitize = (value: unknown): unknown => {
        if (Array.isArray(value)) return value.map(sanitize);
        if (value !== null && typeof value === "object") {
            return Object.fromEntries(
                Object.entries(value).map(([key, item]) => {
                    if (secretField(key)) {
                        values(item);
                        return [key, REDACTED];
                    }
                    return [key, sanitize(item)];
                }),
            );
        }
        return value;
    };
    const input = raw.map(sanitize);
    // These values came from explicitly secret fields. Even a short password
    // must be removed when echoed in otherwise public tool output.
    const exact = [...new Set(secrets)].sort((a, b) => b.length - a.length)
        .map((value) => value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));
    const pattern = exact.length ? new RegExp(exact.join("|"), "g") : null;
    const redact = (value: string) =>
        redactText(pattern ? value.replace(pattern, REDACTED) : value)
            .replace(/(?:Set-)?Cookie\s*:[^\r\n]*/gi, "Cookie: [REDACTED]");
    const calls = new Map<
        string,
        { id: string; name: string; args: ObjectValue }
    >();
    const running = new Map<string, Entry>();
    const entries = new Map<string, Entry>();
    const describe = (name: string, args: ObjectValue): string => {
        if (name === "bash") return "$ " + string(args.command);
        const target = string(args.path ?? args.file_path ?? args.pattern);
        const verb = ({
            read: "Reading",
            edit: "Editing",
            write: "Writing",
        } as ObjectValue)[name];
        const queries = Array.isArray(args.queries)
            ? args.queries.filter((q) => typeof q === "string")
            : [];
        if (name === "search_docs" && queries.length) {
            return "Searching docs for " +
                queries.map((q) => `"${q}"`).join(", ");
        }
        return verb && target
            ? string(verb) + " " + target.split("/").filter(Boolean).at(-1)
            : (name + " " + target).trim();
    };
    const add = (entry: Entry): void => {
        entries.set(
            entry.id,
            Object.fromEntries(
                Object.entries(entry).map((
                    [key, value],
                ) => [key, redact(value)]),
            ) as Entry,
        );
    };
    for (const value of input) {
        const entry = object(value),
            id = string(entry.id),
            at = string(entry.timestamp);
        if (!id) continue;
        const message = object(entry.message), role = message.role;
        if (role === "user" || role === "system" || role === "assistant") {
            let content = text(message.content);
            if (
                !content && role === "assistant" &&
                message.stopReason === "error"
            ) content = "Error: " + string(message.errorMessage);
            if (content) {
                add({ id, at, kind: "message", label: role, text: content });
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
                    args: object(block.arguments),
                };
                calls.set(block.id, call);
                const activity = {
                    id: call.id,
                    at,
                    kind: "activity",
                    label: "Running",
                    text: "Running: " + describe(call.name, call.args),
                };
                running.set(block.id, activity);
                add(activity);
            }
        } else if (role === "toolResult") {
            const callId = string(message.toolCallId), call = calls.get(callId);
            running.delete(callId);
            const name = string(message.toolName) || call?.name || "tool",
                args = call?.args ?? {};
            const failed = message.isError === true;
            let content = describe(name, args);
            if (name === "bash") {
                const output = text(message.content),
                    match = output.match(
                        /Command exited with code (-?\d+)\s*$/,
                    );
                const exit = failed ? match?.[1] : "0";
                content = redact(output).slice(-8000) + "\n" + content +
                    (exit === undefined ? "" : "\nexit code " + exit);
            } else if (failed) {
                content += " failed: " +
                    redact(text(message.content)).slice(0, 500);
            }
            add({
                id: call?.id ?? id,
                at,
                kind: "activity",
                label: name,
                text: content.replace(/^\n+/, ""),
            });
        }
    }
    for (const [callId, entry] of running) {
        const call = calls.get(callId)!;
        add({
            ...entry,
            label: call.name,
            text: describe(call.name, call.args) +
                " (stopped without a result)",
        });
    }
    return [...entries.values()];
}

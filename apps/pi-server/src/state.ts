/** The five Orbit thread states from ADR 0112. Pi never reports `asking_for_input`. */
export type ThreadState = "idle" | "working" | "done" | "failed";
export type ProviderError = "overloaded" | "rate_limited" | "server_error";

export interface StateInput {
    /** A turn was accepted and Pi has not settled it yet. */
    working: boolean;
    /** The server restarted while the latest turn was active. */
    interruptedByRestart: boolean;
    /** The last assistant message in the transcript, if any. */
    lastAssistant:
        | {
              stopReason: string;
              errorMessage?: string | undefined;
              /** Sanitized provider-boundary evidence; null means known non-transient. */
              orbitProviderError?: ProviderError | null;
          }
        | undefined;
}

export interface DerivedState {
    state: ThreadState;
    error: string | null;
    providerError?: ProviderError;
}

/**
 * Maps Pi evidence to an Orbit thread state. Only a normal stop counts as success; every other
 * reason a settled turn can end with is a reported failure, never inferred idleness.
 */
export function deriveState(input: StateInput): DerivedState {
    if (input.working) {
        return { state: "working", error: null };
    }
    if (input.interruptedByRestart) {
        return { state: "failed", error: "The Pi server restarted during the turn." };
    }
    const last = input.lastAssistant;
    if (last === undefined) {
        return { state: "idle", error: null };
    }

    switch (last.stopReason) {
        case "stop":
            return { state: "done", error: null };
        case "aborted":
            return { state: "failed", error: "The turn was interrupted." };
        case "length":
            return { state: "failed", error: "The model reached its output limit." };
        case "error": {
            const providerError =
                last.orbitProviderError === undefined
                    ? classifyProviderError(last.errorMessage).providerError
                    : last.orbitProviderError;
            return {
                state: "failed",
                error: last.errorMessage ?? "The model provider reported an error.",
                ...(providerError ? { providerError } : {}),
            };
        }
        default:
            return {
                state: "failed",
                error: `The turn ended with stop reason "${last.stopReason}".`,
            };
    }
}

/** Compatibility fallback for adapters that retain codes/status in their error text. */
function classifyProviderError(message: string | undefined): Pick<DerivedState, "providerError"> {
    if (message === undefined) return {};
    const text = message.trim();
    // Only parse a provider JSON body, optionally preceded by the SDK's HTTP status prefix.
    const prefix = /^(?:[\w .-]+ API error \((\d{3})\):\s*|(\d{3})(?::|\s)\s*)/i.exec(text);
    const body = prefix ? text.slice(prefix[0].length) : text;
    let code: unknown;
    let status: unknown = prefix ? Number(prefix[1] ?? prefix[2]) : undefined;
    let detail = body;
    if (body.startsWith("{")) {
        try {
            const parsed = JSON.parse(body);
            const error = parsed.error ?? parsed;
            code = error.code ?? error.type ?? error.status;
            status ??=
                error.statusCode ?? (typeof error.code === "number" ? error.code : undefined);
            detail = typeof error.message === "string" ? error.message : body;
        } catch {
            // An SDK HTTP status remains usable even when its body is truncated.
            if (status === undefined) return {};
        }
    }
    const category = classifyProviderFailure({ status, code, message: detail });
    if (category !== null) return { providerError: category };
    if (status !== undefined || code !== undefined) return {};
    if (
        /^(?:the )?servers? (?:are |is )?(?:currently )?overloaded(?: or not ready yet)?[.!]?$/i.test(
            text,
        )
    ) {
        return { providerError: "overloaded" };
    }
    if (/^(?:too many requests|rate limit exceeded|rate limited)[.!]?$/i.test(text)) {
        return { providerError: "rate_limited" };
    }
    return {};
}

/** Only provider error records/status, never assistant prose or tool output. */
export function classifyProviderFailure(evidence: {
    status?: unknown;
    code?: unknown;
    message?: string;
}): ProviderError | null {
    const { status, code, message = "" } = evidence;
    if (typeof status === "number" && status >= 400 && status < 500 && status !== 429) {
        return null;
    }
    if (typeof code === "string") {
        if (["overloaded_error", "server_overloaded", "overloaded"].includes(code)) {
            return "overloaded";
        }
        if (["rate_limit_exceeded", "rate_limit_error", "too_many_requests"].includes(code)) {
            return "rate_limited";
        }
        if (["server_error", "internal_server_error", "INTERNAL", "UNAVAILABLE"].includes(code)) {
            return "server_error";
        }
        if (
            /invalid|auth|permission|quota|billing|payment|credit|content_filter|refus|safety|context_length|usage_limit_reached|usage_not_included/i.test(
                code,
            )
        ) {
            return null;
        }
    }
    if (status === 429) {
        if (/quota|billing|credit|payment|usage limit/i.test(message)) return null;
        return "rate_limited";
    }
    if (typeof status === "number" && status >= 500 && status <= 599) return "server_error";
    return null;
}

"""Sanitize public agent text before SSH history; never read credential files."""

import json
import re


# Pi and model-router credentials are 32-byte hex values in RecordTaskVmAction
# and TaskVmEnrollmentIntent. Recognize the format without reading their files.
def redact_text(text):
    text = re.sub(r"(?<![A-Za-z0-9])[a-fA-F0-9]{64}(?![A-Za-z0-9])", "[REDACTED]", text)
    text = re.sub(r"\x1b\[[0-?]*[ -/]*[@-~]", "", text)
    text = re.sub(
        r"-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|$)",
        "[REDACTED]",
        text,
    )
    if "-----END " in text:
        text = text.rsplit("-----END ", 1)[1].split("-----", 1)[-1]
    text = re.sub(r"(?i)(\b[a-z][a-z0-9+.-]*://)[^/@\s]+@", r"\1[REDACTED]@", text)
    text = re.sub(
        r"(?i)(?:Proxy-)?Authorization\s*:[^\r\n]*", "Authorization: [REDACTED]", text
    )
    text = re.sub(r"(?i)\bBearer\s+[A-Za-z0-9._+/=-]+", "Bearer [REDACTED]", text)
    text = re.sub(
        r"(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})",
        "[REDACTED]",
        text,
    )

    def assignment(match):
        return (
            match[0]
            if re.search(
                r"(?i)TOKEN|SECRET|PASSWORD|PASSWD|PASSPHRASE|CREDENTIAL|APIKEY|APPKEY|PRIVATEKEY|KEYS?",
                match[2],
            )
            is None
            else match[1] + match[2] + match[1] + match[3] + "[REDACTED]"
        )

    return re.sub(
        r"([\"\x27]?)([A-Za-z][A-Za-z0-9_-]{0,255})\1(\s*[:=]\s*)(?:\"[^\"\r\n]*\"|\x27[^\x27\r\n]*\x27|[^\s,}&{\[\]]+)",
        assignment,
        text,
    )


def public(value):
    if isinstance(value, str):
        return redact_text(value)
    if isinstance(value, list):
        return [public(item) for item in value]
    if isinstance(value, dict):
        return {name: public(item) for name, item in value.items()}
    return value


def emit(value):
    print(json.dumps(public(value)))

# Development Instance command assets

The Project step runner retains only bounded failure stderr. It normalizes invalid UTF-8, marks every byte covered by any known secret, and replaces each merged covered range with one marker. This includes overlapping occurrences of one value or several values. Generated markers are never redacted again.

The runner keeps complete trailing lines within 4096 bytes and drops known eight-byte fragments. If the newest line alone exceeds the limit, it retains nothing. Command stdout is discarded. This changes diagnostics only; command execution, exit status, workflow inputs and budgets stay unchanged.

The bounded coverage mask fixes L17-PR-2370-01. The runner remains a retained script pending the current Instance phase port; this patch adds no Node script or execution path.

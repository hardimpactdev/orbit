"""Restore only the captured Schedule artifact set after a native publication failure."""

from schedule_common import atomic, intent, paths, report, snapshot, staging


def restore():
    value = intent()
    saved = snapshot(value)
    for name, path in paths(value).items():
        info = saved["files"][name]
        if info is None:
            path.unlink(missing_ok=True)
        else:
            atomic(path, (staging(value) / ("previous-" + name)).read_text(), **info)


report(restore)

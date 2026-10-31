"""Placed dedicated PHP monitoring action: validate."""

from monitor_common import execute, validate_pool

if __name__ == "__main__":
    raise SystemExit(execute(validate_pool))

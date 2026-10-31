"""Clean only the completed native monitor stage."""

from monitor_common import cleanup, execute

if __name__ == "__main__":
    raise SystemExit(execute(cleanup))

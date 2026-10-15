# Library: the runtime of every Python remote program. It reads one JSON object from standard input, runs the
# program's main, and prints one JSON result line: {"outcome": "...", "data": {...}}. Logs go to standard error.
import json
import signal
import sys


class Outcome(Exception):
    """Ends the program with a named outcome, such as a refusal, and its data."""

    def __init__(self, outcome, data=None):
        super().__init__(outcome)
        self.outcome = outcome
        self.data = data or {}


def log(message):
    print(message, file=sys.stderr, flush=True)


def require(condition, outcome, check):
    """End with `outcome` when `condition` is false, and log the check that failed."""
    if not condition:
        log(f'{outcome}: {check}')
        raise Outcome(outcome)


def _interrupted(signum, frame):
    raise SystemExit(128 + signum)


def run_program(main):
    """Run main(payload), which returns (outcome, data) or raises Outcome, and print the result."""
    for interruption in (signal.SIGHUP, signal.SIGINT, signal.SIGTERM):
        signal.signal(interruption, _interrupted)
    payload = json.load(sys.stdin)
    if not isinstance(payload, dict):
        raise SystemExit('The program input must be a JSON object.')
    try:
        outcome, data = main(payload)
    except Outcome as ended:
        outcome, data = ended.outcome, ended.data
    sys.stdout.write(json.dumps({'outcome': outcome, 'data': data}, separators=(',', ':')) + '\n')
    sys.stdout.flush()

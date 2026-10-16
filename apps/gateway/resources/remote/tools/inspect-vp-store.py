# Program: tools.inspect-vp-store
# Uses: lib/program.py lib/vp-store.py
# Outcomes: inspected invalid
# Timeout: 30
# Input: {"environments": exact vp environment records}
# Result: inspected: {"installed": booleans in input order}. invalid: no data.
# Read-only: no locks, subprocesses, network, or store writes.


def main(payload):
    try:
        return 'inspected', {'installed': [installed(environment) for environment in payload['environments']]}
    except (OSError, ValueError, KeyError, TypeError):
        return 'invalid', {}


run_program(main)

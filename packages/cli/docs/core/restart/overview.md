# restart overview

Restarts all Orbit services by calling stop then start.

- Simple wrapper that calls stop and start commands
- Combines results when using JSON output

Failure and recovery paths

- Returns worst exit code from stop or start

Inputs and options

- --json: Output as JSON

Key integrations

- stop command
- start command

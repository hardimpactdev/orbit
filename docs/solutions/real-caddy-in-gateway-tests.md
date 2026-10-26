---
title: "Real Caddy in Gateway tests"
description: "Why a Gateway test hands Caddy a socket it already holds, and why a leaked error handler can be a time-limit abort."
covers:
  - apps/gateway/tests/Unit/Infrastructure/AppDev/AppDevCaddyConfigRendererTest.php
  - apps/gateway/tests/Unit/Infrastructure/WebSocket/WebSocketCaddySiteRendererTest.php
---

# Real Caddy in Gateway tests

## Problem

A Gateway CI shard failed with this risky test, and a rerun passed:

```text
RISKY  ...AppDevCaddyConfigRendererTest > it routes only the selected Pusher paths ... in real Caddy
Test code or tested code did not remove its own error handlers
at vendor/symfony/console/Command/Command.php:285
```

No test or Gateway code leaked an error handler. The location points at the test runner, not at the cause.

## Cause

The test ran longer than the CI time limit of five seconds. PHPUnit enforces the limit with `SIGALRM` and asynchronous signals, so its timeout exception can start in the middle of any PHP function.

Symfony Process sets an error handler around `stream_select()` in `UnixPipes::readAndWrite()` and removes it right after. `Process::waitUntil()` spends almost all of its time inside that window. When the alarm fires there, the handler stays on the stack. PHPUnit then reports the leaked handler, and the usual "This test was aborted after 5 seconds" message does not appear.

The test waited in `waitUntil()` for Caddy to log `serving initial configuration`, with no bound of its own. It also chose a port by binding `127.0.0.1:0`, closing the socket, and giving the port to Caddy. Caddy listens with `SO_REUSEPORT`, so another test's Caddy that takes the freed port shares it, and the kernel splits connections between the two servers.

## Solution

Give Caddy the listening socket the test already holds. Caddy's socket activation accepts `fd/N` listener addresses: in JSON, `"listen": ["fd/3"]`; in a Caddyfile, `bind fd/3`. Start Caddy with `proc_open()` and pass the socket as descriptor 3. Write Caddy's output to a file, and stop Caddy with `SIGKILL`.

- No other process can take the port, because the test never frees it.
- No readiness wait is needed. The kernel queues connections on the socket until Caddy accepts them, and each request has its own read timeout.
- An inherited TCP socket cannot serve HTTP/3, so a TLS site in the test sets `protocols h1 h2`.

## Limits

This removes the unbounded wait and the port race. It does not make a host stall shorter: a test that still runs past the time limit is aborted. Without Symfony Process in the wait, the abort reports as "This test was aborted after 5 seconds", which names the real cause.

## Verification

Reproduce the misleading message with a test that starts `sleep 3` in Symfony Process, calls `waitUntil()` with a callback that never returns `true`, and runs with `--default-time-limit=1`. Pest reports the leaked handler, not the timeout.

Run both Caddy test files many times at once, for example 120 runs with 28 in parallel and `--default-time-limit=5`. Every run passes.

# Runtime receipt: process:logs --follow history-then-live (post-main-merge tip)

- **candidate:** `691d7edfc3c9785372c9ea3f9a4dd1d0542d03c1`
- **venue:** retained-incus
- **environment:** `dev-6934a8` (kind=`operator_gateway_app-dev`, provider=`incus`, host=`beast`)
- **target:** operator `orbit-e2e-dev-6934a8-operator`, gateway `orbit-e2e-dev-6934a8-gateway` (10.6.0.2), dev `orbit-e2e-dev-6934a8-dev` / `app-dev-1` (10.6.0.4)
- **command:** `ORBIT_GATEWAY_URL=http://10.6.0.2:8080 TERM=xterm-256color NO_COLOR=1 orbit process:logs follow-proof-9b76d195 --node=app-dev-1 --follow --lines=5`
- **expected:** HIST nonce earlier PTY chunk than LIVE ~5s later; remain attached until controlled capture stop; log-stream 202 has Content-Length and completes at startup
- **observed:** hist_elapsed=2.106249s, live_elapsed=8.237559s, chunk_count=2, RESULT=PASS; capture exit 129 idle_timed_out after LIVE
- **result:** passed
- **evidence:** `.orbit/evidence/2026-08-05-process-logs-follow-dev-6934a8-n9b76d195/` (+ this receipt)

## Acquire

```bash
composer e2e:incus -- --start --topology=operator_gateway_app-dev --checkout-roles=operator,gateway,dev
# Retained topology [dev-6934a8]
```

## Markers (operator/gateway/dev)

- transport hash `8c87596dce08c2ad` (timeout-aware reads)
- `INITIAL_SUBSCRIBER_GRACE_MICROSECONDS = 1_000_000`
- log-stream `Content-Length`
- stop-decision target-agent auth string

## log-stream POST

| metric | value |
| --- | --- |
| http_code | 202 |
| Content-Length | 261 |
| ttfb | 0.058104s |
| total | 0.058192s |

## Nonce process

- nonce: `9b76d195`
- process: `follow-proof-9b76d195`
- journal restart: `2026-08-05T12:35:25+00:00`
- LIVE journal: `2026-08-05T12:35:38+00:00 FOLLOW-LIVE-9b76d195`

## Timings (UTC)

| event | time |
| --- | --- |
| HIST seed | 12:35:25 |
| log-stream RTT | 12:35:29 (~0.058s) |
| capture start | 12:35:30 |
| hist_seen_at | 12:35:32 |
| live emit / seen | 12:35:37–38 |
| capture end | duration 73.327s, exit 129 |

## Correlation

- process.logs.follow `8db8179d-ceed-4b2f-8209-51da86e119d6`: started `12:35:31`, finished `12:36:31`
- lease id=1 stream `5c826260-10e2-42c5-b732-a827649f4b02`, created_at=`12:35:31`, left_at=`12:36:31`

## Fixture

- Reverb `:8081` + Caddy `:8080` same-origin with identity header
- Operator `ORBIT_GATEWAY_URL=http://10.6.0.2:8080`

## Cleanup

- Disposable process removed; Reverb/Caddy removed; topology released `composer e2e:incus -- --stop --id=dev-6934a8`

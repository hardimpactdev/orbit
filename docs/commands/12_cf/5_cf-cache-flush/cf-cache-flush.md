# `orbit cf-cache:flush`

Purge Cloudflare cache for a zone or app-owned domain.

## Usage

```bash
orbit cf-cache:flush [--zone=<zone>] [--json]
```

## Examples

```bash
orbit cf-cache:flush --zone=example.com
orbit cf-cache:flush --zone=docs
orbit cf-cache:flush --zone=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --json
```

## Arguments And Options

- `--zone=<zone>`: Cloudflare zone ID, zone domain name, or Orbit app name with
  a configured Cloudflare zone.
- `--json`: Return the flush result in the shared JSON command envelope.

## What Happens

`cf-cache:flush` asks the gateway to purge cached Cloudflare content for the
resolved zone. If `--zone` is omitted in an interactive terminal, Orbit prompts
for a Cloudflare zone. Non-interactive invocation requires `--zone`.

The command flushes provider cache only. It does not deploy apps, restart
services, change proxy routes, or change Cloudflare cache rules.

## Output

Human output confirms the flushed zone. JSON output returns a status object
under `success.data.cache`.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The selected zone exists in the Cloudflare account.

## Related Commands

- [`orbit cf-cache-rule:add`](../6_cf-cache-rule-add/cf-cache-rule-add.md)
- [`orbit deploy:run`](../../10_deploy/4_deploy-run/deploy-run.md)
- [Technical contract](technical/1_cf-cache-flush.md)

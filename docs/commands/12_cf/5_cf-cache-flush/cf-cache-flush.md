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

## Arguments and options

- `--zone=<zone>`: Cloudflare zone ID, zone domain name, or Orbit app name with
  a configured Cloudflare zone.
- `--json`: Return the flush result in the shared JSON command envelope.

## What Happens

Run `orbit cf-cache:flush` to purge cached Cloudflare content for the selected zone.

`cf-cache:flush` asks the gateway to purge cached Cloudflare content for the
resolved zone. If `--zone` is omitted in an interactive terminal, Orbit prompts
for a Cloudflare zone. Non-interactive invocation requires `--zone`.

The command flushes provider cache only. It does not deploy apps, restart
services, change proxy routes, or change Cloudflare cache rules.

## Output

You will see a confirmation of the flushed zone.

Human output confirms the flushed zone. JSON output returns a status object
under `success.data.cache`.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The selected zone exists in the Cloudflare account.

## Related Commands

Use these commands to manage cache rules or trigger deploys that may need a flush.

- [`orbit cf-cache-rule:add`](../6_cf-cache-rule-add/cf-cache-rule-add.md)
- [`orbit deploy:run`](../../10_deploy/4_deploy-run/deploy-run.md)
- [Technical contract](technical/1_cf-cache-flush.md)

# 2026-05-16 — Existing gateway DNS cleanup

One-time cleanup steps for the already-deployed gateway after the gateway DNS
provisioning plan has landed (see
`docs/superpowers/plans/2026-05-16-gateway-dns-provisioning.md` and
`docs/commands/3_tool/dns-bootstrap-contract.md`).

These steps remove the hand-rolled `/opt/vpn-stack/` compose, the
`sync-tlds.sh` cron, and let Orbit re-converge the DNS layer through the
documented contract.

> Run from the gateway host as the `orbit` user unless stated otherwise.

## Inventory the legacy bits

```bash
sudo systemctl list-units --type=service | grep -i wg-easy || true
sudo ls -la /opt/vpn-stack || true
sudo crontab -l 2>/dev/null | grep -i sync-tlds || true
docker ps --format '{{.Names}}' | grep -E 'wg-easy|orbit-dns' || true
```

Confirm wg-easy + orbit-dns are running, and `/opt/vpn-stack/` exists.

## Capture current state for rollback

```bash
sudo tar -czf ~/vpn-stack-backup-$(date +%F).tgz /opt/vpn-stack
docker exec orbit-dns cat /etc/dnsmasq.conf > ~/dnsmasq.conf.backup-$(date +%F)
```

## Re-provision under Orbit ownership

1. Re-run gateway bootstrap so the new installers write the canonical compose
   files under `~/.config/orbit/`:

   ```bash
   php artisan orbit:internal:bootstrap-gateway-local \
       gateway 10.6.0.2 \
       --public-host=<gateway-public-ipv4>
   ```

   This is idempotent: a `WG_EASY_PASSWORD_HASH` already in `.env` is reused.

2. Verify orbit-dns is running under the new compose:

   ```bash
   docker ps --format '{{.Names}}\t{{.Status}}' | grep orbit-dns
   docker inspect orbit-dns --format '{{.HostConfig.NetworkMode}}'  # → container:wg-easy
   ```

3. Re-converge dnsmasq.conf from the current DB state (covers any TLD changes
   that landed via the manual sync):

   ```bash
   orbit doctor --family=tool --fix --restore
   ```

## Remove the legacy stack

```bash
# 1. Remove the root cron entry for sync-tlds.sh
sudo crontab -l | grep -v sync-tlds.sh | sudo crontab -

# 2. Tear down the hand-rolled stack (orbit-dns is already running under
#    ~/.config/orbit/ by this point, so this only stops the legacy compose
#    project, not the live service).
sudo docker compose -f /opt/vpn-stack/docker-compose.yml down --remove-orphans

# 3. Remove the legacy directory.
sudo rm -rf /opt/vpn-stack
```

## Verify

```bash
# DNS still answers over WG
docker exec orbit-dns dig +short orbit.gateway @10.6.0.1

# Doctor reports zero DNS drift
orbit doctor --family=tool
```

## Rollback

Untar `~/vpn-stack-backup-<date>.tgz` back to `/opt/vpn-stack` and re-add the
cron entry. The hand-rolled stack will resume on the next docker compose up.

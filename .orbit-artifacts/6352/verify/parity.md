# route.create parity: main (route.publish_site, dns.publish_records) vs host primitives (RouteEngineFlow)

| # | Main (site.rs / dns lib.rs) | New step | Primitive and input | Parity |
| --- | --- | --- | --- | --- |
| 1 | tls.ensure_csr | csr-i | unchanged | same |
| 2 | Gateway signing | sign-i | unchanged | same |
| 3 | access(): setfacl -R / find -exec over checkout and served roots | site-N-acl-k | posix.acl.ensure | PENDING Nick (tree scope); Routes with rows go to Swamp |
| 4 | url_line(): last APP_URL= replaced or appended; file must be the account's | site-N-url-k | dotenv.ensure {path, user, values} | same rule (missing file / other user's link fails) |
| 5 | link(): symlink, lchown uid only | site-N-link-k | file.ensure link {path, target, user, group=user} | group of the link now set to the account's group (link groups carry no access) |
| 6 | pool files written root:root 0644, placeholders -> gid/home on Node | site-N-pools | config.apply (Caddy lock), whole files, php-fpm validators | Gateway renders group NAME and home (as Swamp); main's engine wrote numeric gid. Validation failure now restores files (main left them) |
| 7 | pool test (php-fpm -t / env PHP_INI_SCAN_DIR ... --fpm-config) | site-N-pools | php-fpm validator {version, fpm_config, ini_scan_dir} | same command, built in Rust from the fixed list |
| 8 | php_unit(): enable if needed; start stopped / reload running | site-N-unit-k, site-N-reload | systemd.unit.ensure {enabled, running, budget_ms 30000}; config.apply same files + units reload | a freshly started unit also gets one reload |
| 9 | leaf cert root:caddy 0644; current link | site-N-cert-i, site-N-current-i | file.ensure file / link | same |
| 10 | install -D -o user -g gid -m 0600 key+cert into ~/.orbit/certificates/<scope>/versions/<v> | site-N-folder-i, site-N-key-i, site-N-copy-i | file.ensure directory (root 0755, as install -D), file.ensure source_path (PENDING ORB-6360), file.ensure file | key copy waits on A2 |
| 11 | account current link | site-N-copylink-i | file.ensure link | group set (see 5) |
| 12 | root trust file + update-ca-certificates on change (site.rs:146-148) | site-N-trust | ca.trust.ensure present {name orbit-managed-root-ca.crt, certificate, root:root 0644} | same, plus openssl verify read-back |
| 13 | Caddyfile: candidate validated as caddy, moved when different, read back; systemctl reload caddy every run | site-N-caddy | config.apply (Caddy lock) {Caddyfile root:caddy 0640, from_sha256, caddy validator, caddy.service reload} | now guarded: a live Caddyfile matching neither the admission render nor desired refuses owned_revision_conflict (main overwrote) |
| 14 | dns: DNS lock, records + catalog root 0644, dnsmasq --test, restart dnsmasq + orbit-private-dns | dns-i | config.apply (DNS lock), dnsmasq validator (/etc/dnsmasq.conf, /etc/dnsmasq.d, dpkg suffixes), units restart | guarded with raw digests (main's create sent no guard); validator reads the folder the service loads |
| 15 | Gateway TLS probe | probe-i | unchanged (Gateway step) | same; http.probe unused because main's create has no Node-side read-back |

Error codes (RouteEngineFlow::code): site steps -> route.input_invalid / route.content_missing / route.publish_failed; dns -> dns.input_invalid, dns.content_missing, dns.publish_busy (lock busy, recovery pending), dns.publish_lock_failed, dns.publish_lock_invalid, dns.check_failed, dns.restart_failed, dns.write_failed; owned_revision_conflict kept. Failed step: every site-N-* -> "Install cert, publish site-N"; dns-i -> "Publish private DNS-i". Projection: NativeRouteConvergenceProjection unchanged.

recovery_dir: /var/lib/orbit/executor/config-recovery on every Node (executor keys by sha256(lock)). Inputs derive from the plan only (test: byte-stable across runs).

# Command output samples

`nft.txt` and `nft-after.txt` were recorded on Beast with the real `nft list ruleset` in a disposable network namespace. Each namespace contained only the three fixture rules below, with counters of 12 packets / 720 bytes or 13 packets / 780 bytes. No host rules changed.

```nft
table inet orbit_fixture {
  chain forward {
    ip daddr 10.44.0.3 tcp dport 8318 counter packets 12 bytes 720 drop
  }
  chain output {
    ip daddr 10.44.0.3 tcp dport 8318 meta skuid != 999 counter packets 12 bytes 720 reject with tcp reset
  }
  chain input {
    ip daddr 10.253.0.1 tcp dport 8319 iifname != "orbitinst0" counter packets 12 bytes 720 reject with tcp reset
  }
}
```

To record them, load these rules with `sudo unshare -n sh -c 'nft -f rules.nft && nft list ruleset'`. Repeat with the higher counters. Addresses and user IDs are test values.

`dpkg-abbrev.txt` and `dpkg-status.txt` were recorded with real `dpkg-query --admindir=<temporary-directory> -W`. The format strings were `${db:Status-Abbrev} ${binary:Package}\n` and `${binary:Package} ${db:Status-Status}\n`. The disposable `status` database contained three packages with `Status: install ok installed`, `Status: hold ok installed` and `Status: deinstall ok config-files`. The abbreviation sample preserves the distinction that caused F14: `ii` and `hi` both mean installed. The production parser uses the status word; held packages must also skip installation. An absent package supplies empty stdout.

Other contexts in `cases.yaml` are sanitized format examples for the named commands. They contain no live host identities or secrets. The table selects expressions and inherited variables from production YAML. It never copies a production regex. Every regex-bearing field needs cases or an explicit input-only reason; new unclassified fields fail the inventory check.

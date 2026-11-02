# Native Tool commands

Install, update and removal use official cfgmgmt resources and one SSH argv per manager command. No package phase sends Python source to the Node. The package check provides the installed version or proven absence; the native assert rejects an incomplete result.

The apt key is fetched to a staging file. One `gpg --with-colons --show-keys` command reads its primary fingerprint. A native assert requires exactly the admitted primary key before the keyring and source are published. Cleanup removes the staging file after completed or skipped publication, including a failed fingerprint check.

APT installation uses one `apt-get` argv because the official apt resource cannot express `--no-remove` or the 300-second dpkg lock wait. Removal uses one `dpkg --remove` argv after the native removal-plan check, so no dependency removal can be added by apt. Official apt `check` proves the package state. Homebrew formula installation/removal uses the official resource; one targeted upgrade command preserves the package boundary. Composer, Vite+ and cask commands also use one argv.

`tool.adopt` stays frozen until the Node agent observer pass. Its shared query and repository scripts remain unchanged. The shared first-use Tool Manager child also retains the node-bootstrap family's pinned installers; this change does not replace that separate bootstrap boundary. No new script or script gap is added.

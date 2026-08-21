# Round 12 CLI activity writer removal

## Red

`bin/orbit-gateway-pest --compact tests/Feature/Removal/CliActivityWriterRemovalTest.php`

The new removal guard failed with one failed test because
`LogsCommandActivity` still existed. This was the expected pre-change state.

## Compatibility characterization

`bin/orbit-gateway-pest --compact tests/Unit/Services/Activity/ActivityPayloadFormatterTest.php`

All five tests passed before the production deletion. The added test proves
that a stored activity with `log_name=cli` is still rendered with the `cli`
channel.

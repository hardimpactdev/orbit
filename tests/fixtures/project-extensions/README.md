# Persisted configuration fixtures

`configuration-v1.json` is the unversioned Project 20 configuration from commit
`ea80fff`, before repository script retirement. It includes the retired `script`
key and the existing worker paths and policy.

`stage-job-v1.json` is a retained worker stage job in that format. Its
`inputIdentity` uses the pre-retirement serialization, including `script`.
Regression tests resume it as running, succeeded and failed without constructing
the old identity through the current serializer.

`publish-job-v1.json` retains a checked PR summary from a failed pre-retirement
publish cycle. The next cycle must reuse it after its child has ended, including
when JSON object keys appear in a different order.

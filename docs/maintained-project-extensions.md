# Maintained project extensions

These entries use current default-branch repository config and Orbit Project
settings, captured on 2026-10-27. The source commits and policy files are
retained in tests/fixtures/project-extensions/fleet.json. No project repository
is changed.

| Project              | Orbit ID | Composer paths | JavaScript paths | Check                                         | Policy in days       |
| -------------------- | -------- | -------------- | ---------------- | --------------------------------------------- | -------------------- |
| launch-starter-kit   | 30       | .              | .                | composer test && composer analyse && vp check | No explicit age rule |
| launch               | 32       | .              | .                | composer check                                | .: 7                 |
| treadmill-website    | 54       | .              | .                | composer test && composer analyse && vp check | No explicit age rule |
| orbit-website        | 33       | .              | .                | composer test && composer analyse && vp check | No explicit age rule |
| recall               | 49       | .              | .                | composer check                                | .: 7                 |
| ai-cli-proxy         | 51       | .              | .                | composer check                                | No explicit age rule |
| laravel-toolbar-site | 28       | .              | .                | composer check                                | .: 7                 |
| operator-website     | 47       | .              | .                | composer check                                | .: 7                 |
| operator             | 48       | server/web     | server/web       | composer --working-dir=server/web check       | server/web: 7        |
| fitta                | 15       | .              | .                | composer check                                | .: 7                 |
| house-italy          | 17       | .              | .                | composer check                                | .: 7                 |
| lindaretel           | 18       | .              | .                | composer check                                | .: 7                 |
| srpm                 | 25       | .              | .                | composer check                                | .: 7                 |
| tankwerk             | 26       | .              | .                | composer check                                | .: 7                 |
| ditis-hr             | 13       | .              | .                | composer check                                | .: 7                 |
| platform11           | 22       | .              | .                | composer check                                | .: 7                 |
| hauzer               | 16       | .              | ., mobile        | composer check                                | .: 7, mobile: 7      |
| mealou               | 19       | .              | ., mobile        | composer check                                | .: 7, mobile: 7      |

Operator keeps only server/web in its private extension config. Hauzer
and Mealou include mobile for Expo. Other manifests outside configured paths
(such as Operator mac/iOS, Operator Website Slack, and Platform11 design
templates) do not expand this rollout. Four projects have no explicit age rule;
their extensions preserve that absence rather than adding policy.

Each routine runs weekly on Monday and detection runs daily, in UTC. Fires are
staggered by ten minutes from Project 20. Read each workflow for its exact cron.
An unfinished routine/detection in the same Project records a skip; other
Projects continue. Scheduler issues and skips are queryable on orbit-gateway.

Horizon New Dawn is excluded: its upstream-parity extension has its own owner.

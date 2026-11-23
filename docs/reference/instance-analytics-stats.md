---
title: "Instance analytics stats"
description: "How the Gateway reads live visitors, period visitor counts, and top pages for an Instance, and when the Orbit web page shows that panel."
covers:
  - apps/gateway/app/Actions/Analytics/ShowInstanceAnalyticsStatsAction.php
  - apps/gateway/app/Domain/Analytics/{AnalyticsStatsDriver,AnalyticsStatsRead,AnalyticsPageStat}.php
  - apps/gateway/app/Infrastructure/Analytics/PlausibleCommunityEditionStatsDriver.php
---

# Instance analytics stats

The Gateway reports visitor statistics for any Instance that publishes an analytics tracking Route by querying the Plausible Community Edition Stats API on the [`analytics` role](/reference/analytics).

## Read the stats

`GET /api/v1/instances/{instance}/analytics/stats` returns one report for the Orbit web Instance page (there is no CLI or PHP SDK command).

| Field | Meaning |
| --- | --- |
| `available` | `false` when the `analytics` role is inactive or the Instance has no `analytics_tracking` Route (all other fields are omitted). |
| `readable` | `false` when the panel is available but stats could not be fetched (visitor fields are omitted). |
| `driver` | `plausible_ce`. |
| `site_domain` | The Instance's authoritative public domain, or `null` on `analytics.stats_domain_missing`. |
| `live_visitors` | Current realtime visitors from Plausible. |
| `visitors` | Visitor counts for `past_24h` (Plausible `day` in site timezone), `past_7d`, and `past_30d`. |
| `pages` | Up to 10 top paths over the last 30 days (`path`, truncated to 500 characters, and `visitors`), highest first. |
| `error_code`, `error` | Present only when `readable` is `false`. |

## Know when the panel is available

Stats are marked `available: true` when both hold:

- An active Node with a WireGuard address runs an active `analytics` role.
- The Instance owns at least one `analytics_tracking` Route.

The web UI renders the analytics panel only when `available` is `true`.

## Know how the Gateway reads it

The Gateway calls Plausible's Stats API on the analytics Node over WireGuard (never a public URL). It authenticates with the stored Stats API key as a bearer token using a 5-second HTTP timeout. It always queries the Instance's authoritative public domain (`data-domain`), fetching realtime visitors, `day`/`7d`/`30d` totals, and the 30-day top 10 pages. If any sub-request fails, the entire report returns `readable: false` so partial numbers are never mixed.

## Handle a failed read

An unavailable report (`available: false`) or unreadable report (`readable: false`) returns HTTP 200 without visitor counts so a missing key never looks like zero traffic.

| `error_code` | Cause |
| --- | --- |
| `analytics.stats_key_missing` | No Stats API key stored on the Gateway; set one with `orbit analytics:credentials --set`. |
| `analytics.stats_unauthorized` | Plausible rejected the stored Stats API key. |
| `analytics.stats_site_missing` | No site exists in Plausible for the Instance's domain. |
| `analytics.stats_domain_missing` | Instance has tracking enabled but no authoritative public domain (`site_domain` is `null`). |
| `analytics.stats_unreadable` | Plausible timed out, errored, or returned an unexpected payload. |

| Status | Error code | Cause |
| --- | --- | --- |
| 403 | `peer.identity_unknown` or `node_access.required` | Caller is not an active WireGuard peer or lacks access to the Instance's Node. |
| 404 | `resource.not_found` | No Instance matches the selector. |

---
title: "ADR 0240: Bound environment import private output"
sidebarTitle: "0240 Environment import output bound"
description: "In progress. Propose one bounded private-output exception for full Instance environment source."
---

# ADR 0240: Bound environment import private output

Propose a store-specific size policy for full Instance environment source. This document changes no runtime limit. The existing [private transport](/reference/operations#private-material-transport) remains unchanged until the implementation receives access review.

## Status

In progress.

Principle: [One way, one name](/mission#principles) and [Lean](/mission#principles). Reuse the shared private-output boundary and Gateway Dotenv parser. Add no family endpoint, parallel parser, staging record or execution machinery.

The orchestrator permits one store to accept up to 1 MiB of decoded environment source because the Gateway must apply its canonical Dotenv rules. This permission does not approve implementation, activation, terminal projection from an output store or exposure of private error text.

## Context

The current shared limit is 262,144 bytes of JSON-encoded output. A 1 MiB source becomes 1,398,104 base64 bytes before JSON framing. Raising the common limit would also enlarge unrelated stores. Treating the encoded length as the decoded limit would reject supported files. Checking only the encoded length would accept two bytes beyond the source limit.

The Node reader already limits source files to 1,048,576 bytes. The Gateway's existing Instance environment validator uses the same file limit. The Gateway still needs the complete source to parse quoting, interpolation, duplicate keys and import conflicts; a digest or key list cannot preserve those rules.

## Decision

Propose a code-owned exception restricted to the `env.import` kind, its one fixed declared store, one fixed output field and the existing Instance owner checks. Neither a request field nor runtime configuration selects a larger limit. All other stores and aggregate public results retain their current 262,144-byte limits. The old executor continues to extract secret outputs under its current limit.

The fixed store name is `instance.environment.import` and its only output field is `environment`. The field contains exactly `encoding`, `content` and `sha256`: the literal encoding `base64`, canonical base64 of the raw `.env` bytes, and the lowercase SHA-256 hex digest of those same bytes. The Gateway verifies the digest before parsing or storing the source. The store name belongs to the trusted declaration; it is not a caller-selected body field. These names and this object are the orchestrator's default contract for lane1 unless the family identifies a concrete objection. This proposal registers no store.

### Exact byte limits

For the fixed compact body below, `R` is a 36-byte UUID, `B` is canonical RFC 4648 standard base64 including required padding, and `H` is the 64-character lowercase hex SHA-256 digest of the decoded bytes. Byte limits exclude HTTP headers and TLS framing.

```json
{"run_id":"R","outputs":{"environment":{"encoding":"base64","content":"B","sha256":"H"}}}
```

| Representation | Maximum bytes |
| --- | ---: |
| Original source, counted as bytes | 1,048,576 |
| Base64 string, `4 * ceil(source_bytes / 3)` | 1,398,104 |
| Compact `environment` value object, including the 64-byte digest | 1,398,214 |
| Compact `outputs` object, including its fixed key | 1,398,230 |
| Complete compact HTTP JSON body with the actual UUID | 1,398,290 |

The value, outputs object and full body add exactly 110, 126 and 186 ASCII bytes to the base64 content length. The full-body cap is therefore `1,398,104 + 186 = 1,398,290` bytes. Derive it from the trusted declaration and this fixed frame, never from caller-selected fields. Do not mistake 1,398,104 for the complete request limit or 1,048,576 for an encoded limit.

Apply a raw HTTP body cap, a fixed-shape validation, an encoded string cap and a decoded source cap before parser or store invocation. Count actual body bytes as well as any supplied Content-Length. Whitespace and escaped spellings count toward the body cap. Reject excess data; never truncate it or fall through to an ordinary store. No global PHP, HTTP or result limit increases.

Validate the exact object keys, string types, literal encoding and 64-character lowercase hex digest. Validate standard base64 strictly and require its canonical re-encoding to equal the submitted string. Reject whitespace, missing or surplus padding, URL-safe alternatives and invalid alphabet bytes. After decoding, reject more than 1,048,576 bytes and verify SHA-256 against the supplied digest before Dotenv parsing or any record mutation. Sources of 1,048,577 and 1,048,578 bytes have the same encoded length as the maximum valid source and must still refuse. A digest mismatch refuses without echoing the source or digest into public errors or logs.

Native `JSON.stringify` does not escape base64 `/` characters. PHP's default `json_encode` does. Use raw request bytes for the HTTP cap and canonical serialization with unescaped slashes for the encoded-output calculation. Do not use PHP-escaped JSON as the size measurement for a cap calculated from unescaped JSON. A request that actually sends escaped slashes still counts those bytes toward its raw body cap; refuse it if it exceeds that cap. Do not add a larger global allowance to compensate.

Resolve the exceptional policy from the admitted kind and its registered store declaration. Reject additional output fields and additional keys in the environment object. Preserve exact caller Node, bound native run, unfinished-operation and owner checks. Private bytes and their digest remain outside Operation input, state, results, steps, Activity, logs and native resources. Responses retain no-store and MCP exclusion.

### Required verification

The implementation must test the maximum valid source, one byte over and two bytes over, empty source under existing parser semantics, malformed base64, slash-heavy base64, missing or extra fields, wrong encoding, malformed or mismatched digest and oversized raw bodies. Assert refusal before parser or store calls for every size, digest or wire-shape failure. Repeat the existing default-limit boundary on an unrelated store and the old executor to prove that neither limit changed. Count bytes in non-ASCII source and exercise the actual shipped HTTP serializer.

The implementation must exercise a real private-output HTTP request, a foreign Node and foreign run refusal, exact owner checks and a whole-scratch/log scan using synthetic source. Native fixture success is not a fleet TLS, placement, release or activation proof.

## Rejected alternatives

- Raise the common secret-output or public-result limit: enlarges unrelated stores.
- Compare only base64 length: permits two bytes beyond the decoded source bound.
- Send only a digest or parsed key list: loses canonical Gateway parsing and import semantics.
- Truncate the source: changes environment values and parser behavior.
- Add a family transport or caller-selected limit: creates another boundary or gives size authority to the caller.

## Consequences

This proposal permits full source only for one reviewed store while retaining the existing transport. It does not resolve lost-output-reply import conflicts, atomic record/result projection or bounded private conflict codes. Those remain separate semantic gates. No env-import cutover or canary is authorized by this record.

## Affects

- Components: apps/gateway
- ADRs: none.
- Detail: [Private material transport](/reference/operations#private-material-transport).
- Verify: the boundary cases and actual HTTP checks above; exact implementation and family source require independent access review before release.

# Product Decisions

This is Orbit's **intent ledger**: a chronological, append-only record of
*direction-change* decisions. It sits above the authority-doc chain
(mission → architecture → concepts → tech-stack → domains) as the anchor for
**current intent**. It does not restate contracts — detailed behavior still
lives in the authority docs.

## How to use it

- **Find current intent on a topic:** `grep` this file for the topic noun
  (e.g. `scheduler`, `gateway`, `swarm`, `php`, `s3`). The matching entry with
  the **latest date is the current direction**; older entries on the same topic
  are superseded.
- **Resolving drift:** when two docs disagree, the latest dated decision here is
  current intent. The stale doc is the side that contradicts it, unless a
  *later* decision reaffirms the doc. This pre-fills the fix direction; it does
  not authorize silent edits.

## What gets a line (the bar)

Only a decision that **establishes a new product direction** or
**changes/reverses a previously-documented one**. Not a feature changelog: no
flags, bug fixes, refactors, test-lane tweaks, or gap-filling within an existing
direction.

## Entry format

`- YYYY-MM-DD — <decision, present tense, current direction; include the topic noun>. (solo todo #NNNN)`

- Present tense ("Gateway runs as…"); the date carries when it became intent.
- Put the topic noun in the line so `grep` finds it.
- The `(solo todo #NNNN)` link is optional — include it when a Solo todo drove
  the decision (it is the context trail and timeline anchor); omit it otherwise.
- Newest entry first.

## Decisions

<!-- newest first; add new entries directly under this comment -->

## Archive

<!-- Manually move fully-absorbed, settled decisions here once the authority
docs reflect them. Keeps the active list above high-signal while preserving the
dated intent trail. -->

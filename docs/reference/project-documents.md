---
title: "Project Documents"
description: "Native Project folders, versioned files, editing, private S3 storage, and the shared API, CLI, SDK, MCP, and web contract."
covers:
  - apps/gateway/extensions/documents/**
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/artisan
  - apps/cli/app/Commands/ProjectDocuments/Concerns/GatesDocumentsExtension.php
  - apps/web/src/{api/documents.ts,extensions/documents/**,pages/ProjectMenu.tsx}
---

# Project Documents

Project Documents are folders and versioned files owned by a [Project](/reference/projects) outside Git. They belong to the Project rather than any Instance, branch, task, or Node, with metadata stored in the Gateway database and file bodies stored in a dedicated private S3-compatible bucket.

Project Documents is the optional `documents` [extension](/reference/extensions) (off by default on new installs; preserved on upgrades). While disabled, document and storage requests fail with HTTP 409 `extension.disabled` and CLI, MCP, and web entries stay hidden, while stored data, background cleanup, and Project-removal cleanup continue.

Enable the extension, configure private S3 storage, and create your first folder and file:

```bash
orbit extension:enable documents
orbit project:document-storage:update --endpoint=https://s3.example.test --region=us-east-1 --bucket=project-documents
orbit project:document:create acme specs --kind=folder
orbit project:document:create acme notes.md --kind=file --parent=1 --content="# Notes" --media-type=text/markdown
```

Every action is shared across the API, CLI, SDK, MCP, and web app. Documents do not replace maintained repository docs or enter agent context automatically, except for the reserved [`agent/` folder](#agent-instructions). Real-time collaboration, rich-text editing, public sharing, OCR, and embeddings are out of scope.

## Agent instructions

The root folder named `agent` is reserved for Project-level [agent instructions](/reference/development-containers#agent-instructions): `agent/AGENTS.md` and `agent/.skills/<skill>/SKILL.md` (with sibling files). Every new clone of the Project's container default receives them at creation.

Edit them with standard document commands (such as `project:document:write <project> <entry> --from=AGENTS.md` or MCP `project-document-write`) and roll back with `project:document:restore-version`. [`project:show`](/cli/project/show) reports what new clones will read. The `agent/` folder holds at most 32 files and 32 KiB of UTF-8 text, with no control characters other than tabs and line breaks.

## Ownership and fields

Each Project has an implicit root folder (`parent_id: null`). Entries expose `id`, `project_id`, `kind` (`folder` or `file`), `parent_id`, `name`, `revision`, `archived_at`, `is_archived`, `path`, `created_at`, and `updated_at`. IDs are positive integers that stay stable across rename, move, archive, and restore; `revision` starts at `1` and increments on each entry mutation. Computed slash-separated `path` values are display-only and never used as IDs or S3 keys.

Files also expose `current_version` (`id`, `number`, `media_type`, `size_bytes`, `sha256`, `created_at`, `created_by_node_id`); folders have `current_version: null`. Versions are immutable, numbered sequentially from `1`, and record lowercase hex SHA-256 digests. Responses never include storage keys, credentials, or provider URLs. Removing an author Node sets `created_by_node_id` to `null` without deleting versions.

Entry names are NFC-normalized UTF-8 strings of 1 to 255 bytes with no leading/trailing whitespace, `/`, `\`, control characters, `.`, or `..`. Names are case-sensitive and unique among all siblings (including archived entries and across kinds). Empty folders are valid, parents must be folders in the same Project, moves into self or descendants are refused, and tree depth is capped at 32 entries. Root cannot be renamed, moved, archived, or removed.

## Folders, archive, and removal

Create folders with a name and optional `parent_id` (`null` for root). Rename or move entries with a metadata update (omitted fields stay unchanged; `parent_id: null` moves to root). Folder metadata updates never create file versions or bump descendant revisions.

### Archive

Archiving sets `archived_at` without deleting versions or bytes; restoring clears it. An entry is effectively archived (`is_archived: true`) if it or any ancestor is archived. Archiving or restoring a folder updates only that folder's flag and revision, preserving children's own flags.

Default list and search queries exclude effectively archived entries; pass `state=archived` or `state=all` to include them. Direct show, version history, read, and download still work on archived entries, but archived entries (or entries under archived parents) cannot be edited, moved, or used as create destinations. Restoring requires an active parent, and repeating archive or restore at the current revision is a no-op.

### Permanent removal

Removal is permanent and distinct from archiving. Removing a file hides its metadata and versions immediately and schedules deletion of all stored bodies. Removing a nonempty folder requires `recursive=true` and deletes the entire subtree (including archived children) atomically under subtree locks.

There is no undelete or per-version deletion; restoring old content creates a new version. Removing a Project schedules cleanup for all its Documents in the same transaction without requiring S3 to be reachable. Ordinary Instance removal does not touch Documents.

## Content and versions

Files are created with their first body. Content writes accept exactly one of `content_text` (UTF-8 string) or `content_base64` (strict canonical RFC 4648 base64). The Gateway computes size and SHA-256 and stores exact bytes without newline conversion. Binary files are treated as attachments rather than editable text.

### Limits

These size, media-type, and query limits apply across every client:

| Limit | Value |
| --- | --- |
| Decoded file body | 10 MiB (10,485,760 bytes); empty files allowed |
| HTTP JSON and MCP envelope | 15 MiB (15,728,640 bytes), checked before JSON parsing |
| Inline text read/write (`content_text`) | 1 MiB (1,048,576 bytes), valid UTF-8 without NUL |
| Editable inline media types | `text/plain`, `text/markdown`, `application/json`, `application/yaml`, `text/csv` |
| `media_type` length | Lowercase `type/subtype`, at most 127 bytes (defaults: `text/plain` for text, `application/octet-stream` for base64) |
| Search query `q` | Trimmed UTF-8 string, 1 to 200 characters |
| Pagination `limit` | 1 to 100 rows (default 50) |

Larger text files can be uploaded and downloaded via `content_base64` up to 10 MiB, but not edited inline. Changing a file's `media_type` counts as a content write and creates a new version.

### Version history and search

Each content write creates an immutable version and increments `revision` together. Writing identical bytes and `media_type` is a no-op. Restoring a version copies its exact bytes and `media_type` into a new version (also a no-op if already current). Version history is ordered newest first; downloads pin the selected or current version for the full request.

Search matches entry names and computed paths within one Project using a literal substring match (case-insensitive, with no SQL wildcards), supporting the same `state` and `kind` filters as list. File bodies are never indexed in the database.

## Optimistic concurrency and retries

Every mutation on an existing entry (update, write, restore-version, archive, restore, removal) requires `expected_revision`. A stale revision returns HTTP 409 `project_documents.revision_conflict` with `entry_id` and `current_revision` and changes nothing. Clients never force-overwrite or silently retry conflicts; reload metadata and content, keep the caller's draft, and submit with the new revision.

Create is never an upsert (duplicate sibling names always conflict). After an ambiguous network error on create, list the parent folder and compare name, SHA-256, and media type; after an ambiguous removal, a 404 on show confirms deletion.

## API contract

All routes live under `/api/v1` and require the `documents` extension. Only `{project}` and `{entry}` are numeric path parameters; cross-Project or cross-file IDs return 404. Authorization runs before body parsing or S3 access, using Project-owning Node grants for document routes and Gateway access for storage routes.

Paths below are relative to `/api/v1/projects/{project}/documents` unless noted. Operation IDs match MCP tool names. Creates return 201 and all other successes return 200 (never 204) with `data` and `meta.request_id`.

| Method and path | Operation ID | Inputs and result |
| --- | --- | --- |
| `GET` (base) | `project-document-list` | `parent_id` (default root), `state` (`active`), `kind`, `cursor`, `limit`; entry page. |
| `GET /search` | `project-document-search` | Required `q`, `state`, `kind`, `cursor`, `limit`; entry page across tree. |
| `POST` (base) | `project-document-create` | `kind`, `name`, optional `parent_id`; files require one body field and optional `media_type`. |
| `GET /{entry}` | `project-document-show` | Entry metadata including `is_archived`. |
| `PATCH /{entry}` | `project-document-update` | `expected_revision`, at least one of `name` or `parent_id`. |
| `PUT /{entry}/content` | `project-document-write` | `expected_revision`, one body field, optional `media_type` (defaults to current). |
| `GET /{entry}/content` | `project-document-read` | Optional `version` query param; `{entry_id, revision, version, content_text}`. |
| `GET /{entry}/download` | `project-document-download` | Optional `version` query param; `{entry_id, revision, version, content_base64}`. |
| `GET /{entry}/versions` | `project-document-version-list` | `cursor`, `limit`; descending version page. |
| `POST /{entry}/restore-version` | `project-document-restore-version` | `expected_revision`, required `version_id` in JSON body. |
| `POST /{entry}/archive` | `project-document-archive` | `expected_revision`; archived entry. |
| `POST /{entry}/restore` | `project-document-restore` | `expected_revision`; restored entry. |
| `DELETE /{entry}` | `project-document-destroy` | JSON `expected_revision`, `recursive` (default `false`); `{id, removed: true, cleanup_pending}`. |
| `GET /api/v1/project-document-storage` | `project-document-storage-show` | Redacted storage config and cleanup status (no S3 probe). |
| `PUT /api/v1/project-document-storage` | `project-document-storage-update` | Storage fields; runs probe before saving. |

Entries sort by kind (folders first), case-sensitive name, then ID, with opaque filter-bound `meta.next_cursor` pagination. File-only operations on folders return `project_documents.not_file`. Downloads return base64 JSON rather than redirects so API, CLI, SDK, and MCP share one transport contract.

### Errors and recovery

Orbit maps document and storage failures to these stable codes without leaking credentials, keys, or provider responses:

| HTTP | Code | Meaning and caller action |
| --- | --- | --- |
| 403 | `peer.identity_unknown`, `node_access.required` | Use an authorized peer with the required Node grant. |
| 404 | `project_documents.not_found` | Entry or version is absent or removed; relist. |
| 409 | `project_documents.revision_conflict` | Reload and merge the draft; never force-overwrite. |
| 409 | `project_documents.upload_abandoned` | Expired upload was fenced; reread metadata and retry. |
| 409 | `project_documents.name_conflict` | Sibling name already exists; choose another name. |
| 409 | `project_documents.archived` | Entry or ancestor is archived; restore ancestors first. |
| 409 | `project_documents.folder_not_empty` | Inspect descendants and pass `recursive: true`. |
| 409 | `project_documents.storage_in_use` | Destination cannot change while versions, intents, fences, or pending cleanup exist. |
| 409 | `project_documents.storage_not_configured` | Configure the dedicated S3 bucket first. |
| 413 | `project_documents.content_too_large` | Decoded 10 MiB or HTTP 15 MiB limit exceeded. |
| 422 | `validation.failed` | Invalid fields, name, base64, depth, cycle, media type, query, or cursor. |
| 422 | `project_documents.not_file` | File-only operation targeted a folder. |
| 422 | `project_documents.not_editable` | Content exceeds 1 MiB inline limit or uses a non-editable media type. |
| 502 | `project_documents.body_unavailable` | Committed object is missing or fails SHA-256; operator recovery required. |
| 503 | `project_documents.storage_unavailable` | S3 timeout, invalid credentials, failed probe, or network outage; retry later. |

## CLI contract

Commands use `orbit project:document:<verb>` following [CLI UX](/reference/cli-ux). The first argument is the Project ID or slug; entry and version selectors are numeric IDs. All commands support `--json`, exit `0` on success, `2` on invalid/missing input or declined confirmation, and `1` on API, storage, or local I/O failures (`project_documents.local_io_failed`).

| Verb | Arguments and options beyond Project | Behavior |
| --- | --- | --- |
| `list` | `--parent=ID`, `--state=active\|archived\|all`, `--kind=folder\|file`, `--cursor`, `--limit` | Lists direct children (ID, KIND, NAME, REVISION, SIZE, ARCHIVED). |
| `search` | Query; `--state`, `--kind`, `--cursor`, `--limit` | Lists matching paths across the tree. |
| `show` | Entry ID | Prints metadata properties, never body bytes. |
| `create` | Name, `--kind`, optional `--parent`, `--from=PATH\|-` or `--content=TEXT`, `--media-type` | Creates folder or file. |
| `update` | Entry ID, `--name`, `--parent=ID\|root`, `--expected-revision` | Renames or moves an entry. |
| `read` | Entry ID, optional `--version` | Prints exact UTF-8 text to stdout (`--json` returns API envelope). |
| `upload` | Entry ID, `--from=PATH\|-`, optional `--media-type`, `--expected-revision` | Writes local bytes as a new version. |
| `write` | Entry ID, `--content=TEXT` or `--from=PATH\|-`, optional `--media-type`, `--expected-revision` | Writes UTF-8 inline text as a new version. |
| `download` | Entry ID, optional `--version`, `--output=PATH\|-` | Decodes and verifies bytes to file or stdout (`--json` forbids `--output`). |
| `versions` | Entry ID, `--cursor`, `--limit` | Lists version history (NUMBER, ID, TYPE, SIZE, SHA256, CREATED). |
| `restore-version` | Entry ID, `--version`, `--expected-revision` | Copies an older version into a new version. |
| `archive`, `restore` | Entry ID, `--expected-revision` | Toggles archive state. |
| `remove` | Entry ID, `--expected-revision`, `--recursive`, `--yes` | Permanent deletion; requires explicit confirmation. |

In interactive terminals, missing inputs are prompted (with masked credential prompts). Downloads verify size and SHA-256, refuse binary stdout to a TTY, and never overwrite existing files.

Storage uses `orbit project:document-storage:show` and `orbit project:document-storage:update` with `--endpoint`, `--region`, `--bucket`, `--access-key-id-file`, and `--secret-access-key-file` (UTF-8 files with optional trailing newline; secrets are never accepted as CLI arguments).

## SDK and MCP contract

The PHP SDK exposes one `GatewayRequest` class per API endpoint (`ListProjectDocumentsRequest`, `SearchProjectDocumentsRequest`, `CreateProjectDocumentRequest`, `ShowProjectDocumentRequest`, `UpdateProjectDocumentRequest`, `WriteProjectDocumentRequest`, `ReadProjectDocumentRequest`, `DownloadProjectDocumentRequest`, `ListProjectDocumentVersionsRequest`, `RestoreProjectDocumentVersionRequest`, `ArchiveProjectDocumentRequest`, `RestoreProjectDocumentRequest`, `DestroyProjectDocumentRequest`, `ShowProjectDocumentStorageRequest`, `UpdateProjectDocumentStorageRequest`) returning typed DTOs with verified base64 decoding and redacted credentials.

Each API operation generates an [MCP tool](/reference/mcp) with the same operation ID. Because `/mcp/search` caps responses at 65,536 bytes, use `/mcp` directly, the CLI, or the SDK for larger downloads.

## Web contract

### Browsing and actions

When the `documents` extension is enabled, each Project exposes a Documents workspace at `/projects/{id}/documents` (storing folder, search, and archive state in the URL query). Users can browse folders, search names/paths, filter active/archived entries, create folders or text files, upload attachments, move via a folder picker, preview escaped text safely, view version history, download, restore, and remove entries.

### Large-tree browsing limitation

The Gateway currently loads all entries in a Project in memory before filtering/sorting a page and resolves current versions per entry (and loads all versions of a file before slicing history). Large trees and long version histories increase memory and latency even for small pages.

### Editing and pending requests

Editable files open a plain-text editor with explicit Save (no autosave). Inputs lock while saves, reloads, or folder refreshes are pending. On revision conflict, the unsaved draft is preserved so the user can copy or reload without losing work. User content (HTML, SVG, Markdown) is never executed or rendered as trusted markup.

### Storage status and verification

Gateway settings provide S3 credential fields that are write-only, plus sanitized cleanup status without ever returning stored secrets. Metadata remains readable during S3 outages.

## Private S3 boundary

Configure one existing dedicated private UpCloud S3-compatible bucket per Gateway (`endpoint`, `region`, `bucket`, `access_key_id`, `secret_access_key`). Orbit uses HTTPS, Signature Version 4, path-style addressing, and no per-object ACL headers (`GetObject`, `PutObject`, `DeleteObject`, and bucket listing are required; `PutObjectAcl` is not).

Validation limits: `endpoint` is an HTTPS origin (max 255 chars, no userinfo/path/query/fragment), `region` is the S3 signing region (1 to 63 ASCII chars), `bucket` is 3 to 63 chars, and credentials are nonempty strings up to 1,024 bytes encrypted at rest with the Gateway app key. Credentials are write-only and redacted everywhere; rotating them requires supplying both fields and does not decrypt the old pair.

Before saving storage settings, Orbit runs a read/write/delete probe under `orbit-document-probes/` (32 random bytes, 2s connect / 5s total cURL timeout, no redirects or retries, 33-byte GET / 64 KiB PUT/DELETE memory sinks, 2xx required). Failures return `project_documents.storage_unavailable` and preserve previous settings.

Destination fields (`endpoint`, `region`, `bucket`) cannot change while any live version, active upload intent, permanently retained abandoned-upload fence, or pending cleanup exists (`project_documents.storage_in_use`, even when `pending_cleanup_count` is `0`).

### Recover reserved probes

Before each probe PUT, Orbit records the probe ID, destination, and encrypted credentials in a separate synchronous SQLite journal at `$ORBIT_HOME/project-document-probes/journal.sqlite`. Records stay permanently so late PUTs remain cleanable.

Every minute, `project-documents:probes:reconcile` claims up to 20 due probe records (10-minute claim expiry) and issues one bounded DELETE per exact `orbit-document-probes/{UUID}` key (1-minute interval on success, exponential backoff 1m to 1h on failure). Probe reconciliation stays separate from document-body cleanup and continues while document cleanup is paused. Repair revoked probe credentials with `project-documents:probes:repair RECORD --access-key-id-file=PATH --secret-access-key-file=PATH` (mode-0600 files). Back up the probe journal alongside the database and app key.

### Publish and recover

Body writes record an `active` upload intent with a fresh random object key before uploading to S3 (outside any DB transaction, 30s timeout, no automatic retry), then atomically publish the version and revision in one DB transaction. Active intents older than 1 hour are atomically marked `abandoned` with a permanent fence and deletion tombstone; late publication attempts fail with HTTP 409 `project_documents.upload_abandoned`. Abandoned fences stay permanently to catch delayed PUTs.

#### Transfer published keys on removal

File, subtree, and Project removals record exact-key removal tombstones in the same metadata transaction while leaving matching `published` upload rows for the cleanup worker's transactional handoff. No S3 calls run inside the metadata transaction.

Every 5 minutes, `project-documents:cleanup:work` expires up to 100 stale intents and claims up to 100 due cleanup records (prioritizing pending deletions over idle fence rechecks, 10-minute claim expiry, 2s connect / 30s request timeout, 5m to 1h failure backoff). Before each DELETE, the worker acquires the execution lock, verifies the current-generation permit, completes any pending published-row handoff, and rechecks exact-key authorization. Unknown bucket objects are never deleted automatically. Missing or corrupt committed objects return `project_documents.body_unavailable` without silent fallback.

### Restore-time cleanup gate

Document-body deletion is fail-closed via a local permit under `/run/orbit/project-documents/` (outside backups). Gateway startup clears the permit and rotates the generation so a restored older database cannot delete newer bucket objects before reconciliation.

Run these operator commands as the Gateway service user via `php artisan <command>` (single JSON object on stdout, `0` success, `2` invalid input, `1` state/provider failure):

| Command | Extra output fields | Behavior |
| --- | --- | --- |
| `project-documents:cleanup:pause` | None | Removes permit, invalidates reports, and rotates generation under lock. |
| `project-documents:cleanup:status` | None | Reports local gate state and DB cleanup counts (no S3 access). |
| `project-documents:cleanup:reconcile` | `report_id`, `report_path`, `report_state`, `difference_count` | Requires paused state; inventories DB and bucket without deleting. |
| `project-documents:cleanup:resume --report=ID` | None | Requires a current, complete, difference-free report; writes permit. |
| `project-documents:cleanup:work` | `claimed_count`, `deleted_count`, `failed_count` | Runs one bounded cleanup batch (no-op when paused). |

All commands return `cleanup_state` (`paused` or `running`), `cleanup_generation`, `reconciliation_report_id`, `pending_cleanup_count`, `oldest_pending_cleanup_at`, and `last_cleanup_error_code`. The lock file is bound by device and inode; never unlink or replace it while services run.

### Local state and startup ordering

`/run/orbit/project-documents/` is mode `0700` with mode `0600` files owned by the Gateway user. Because `/run/orbit` lives on tmpfs, the `gateway` role installs `/etc/tmpfiles.d/orbit-gateway.conf` (`d /run/orbit 0755 orbit orbit -`) so systemd recreates it on boot before services start.

Starting `schedule:work`, `queue:work`, `queue:listen`, or standalone `schedule:run` invalidates the cleanup permit and starts a fresh generation. Run recurring cleanup under supervised `schedule:work`, which holds an exclusive lock on `scheduler.session` and passes `ORBIT_DOCUMENT_SCHEDULER_SESSION` to its minute ticks so ticks preserve a valid permit without treating each tick as a restart. [Laravel Horizon](/reference/gateway-queue) does not touch the document cleanup permit.

### Private reconciliation reports

Reports live at `$ORBIT_HOME/project-document-recovery/reports/{ID}.json` (dir `0700`, file `0600`), written atomically and bound to the current generation. They record inventory counts, canonical fingerprints, and every missing, corrupt, inconsistent, or unknown key without credentials or body bytes. Treat reports as private recovery files.

Follow these steps for installation, restart, or restore:

1. **Pause before replacement:** Run `project-documents:cleanup:pause`, confirm `paused`, stop API/scheduler/workers, and back up DB, bucket, probe journal, and app key (never `/run`).
2. **Restore with workers stopped:** Restore DB and bucket objects, start the Gateway (cleanup starts paused), and keep document mutations disabled.
3. **Inventory without deletion:** Run `project-documents:cleanup:reconcile` to read the DB inventory, page the bucket with `EncodingType=url`, and verify every committed body's size and SHA-256.
4. **Resolve differences:** Restore missing bytes or metadata, or back up and remove unknown objects. Rerun reconcile with `--resolution-file=PATH` (mode-0600 JSON recording `prior_report_id` and each resolved key).
5. **Authorize cleanup:** Run `project-documents:cleanup:resume --report=ID`. Under the execution lock, Orbit rechecks fingerprints and committed body digests before writing the permit.

### Fingerprints and refusal outcomes

Reconciliation and resume compute lowercase SHA-256 fingerprints over canonical `orbit-document-inventory-v1` JSON arrays covering destination (`endpoint`, `region`, `bucket`), entries, versions, upload intents, tombstones/fences, and bucket objects (sorted deterministically by table/ID or exact key bytes, excluding credentials).

| Failure | Local `error_code` | Outcome |
| --- | --- | --- |
| Invalid arguments or resolution file | `project_documents.cleanup_input_invalid` | Exit 2; no permit granted. |
| Lock, state, or permission error | `project_documents.cleanup_state_unavailable` | Exit 1; treat as paused and repair local state. |
| Reconcile or resume while running | `project_documents.cleanup_not_paused` | Exit 1; pause first. |
| Missing, stale, or modified report | `project_documents.cleanup_report_invalid` | Exit 1; reconcile again. |
| Report has unresolved differences | `project_documents.cleanup_unresolved` | Exit 1; resolve differences and reconcile again. |
| DB or bucket inventory/digest drifted | `project_documents.cleanup_inventory_changed` | Exit 1; reconcile again. |
| Provider list/read/delete failure | `project_documents.storage_unavailable` | Exit 1; worker backs off while keeping valid permit. |
| Conflicting durable references | `project_documents.cleanup_reference_conflict` | Refuses DELETE; pause and inspect metadata. |

## Test boundaries

Lifecycle, recovery, deletion, and API tests run against private copies of a migrated SQLite template rather than wrapping transactions so real commits, locks, and subprocess visibility are exercised (see [contributor test guidance](/contributor-guide#3-implement-and-verify)). Transport tests keep one real stalled-TLS and one real stalled-response case and inject those failures for classification cases.

## Why it works this way

Keeping hierarchy and revisions in SQLite while storing immutable bytes in a dedicated private S3 bucket avoids bloating database backups and decouples Project data from Git commits and Instance lifetimes. Proxying all body operations through the Gateway keeps one authorization, limit, redaction, and API/MCP contract across every client (avoiding presigned URLs or multipart upload state).

The plain-text editor is a narrow exception to [Where Orbit stops](/mission#where-orbit-stops), serving [Agents operate, humans steer](/mission#principles) and [One way, one name](/mission#principles) without turning Orbit into an IDE or collaborative docs suite. Explicit save and `expected_revision` surface conflicts instead of losing edits.

Because SQLite and S3 cannot share a transaction, Orbit relies on durable upload intents, permanent abandoned-upload fences, exact-key removal tombstones, a fail-closed `/run` cleanup gate, and a separate probe journal. Together these prevent late uploads from leaking and stop restored older databases from deleting newer bucket objects.

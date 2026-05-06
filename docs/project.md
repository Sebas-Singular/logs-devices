# Logs Devices - Project Plan

## Current Scope

This repository currently contains the production log ingestion endpoint at the
repository root:

- `index.php`: legacy production endpoint used by devices.
- `storage/`: server-side weekly JSON log files.

Do not change the behavior of `index.php` until the production devices can be
coordinated and tested.

## Target Domain

- Public URL: `https://logs.singularthings.io`
- Purpose: private web viewer for device logs.
- Ingestion: keep compatibility with the current bridge payload and headers.

## Current Log Format

Weekly files are stored as:

```text
storage/bridge_logs_YYYY_Www.json
```

Each weekly JSON file contains:

- `week`
- `updated_at`
- `upload_count`
- `uploads[]`
- `uploads[].received_at`
- `uploads[].remote_addr`
- `uploads[].user_agent`
- `uploads[].payload`
- `uploads[].payload.bridgeId`
- `uploads[].payload.logText`

Observed log types:

- `TELEMETRY`
- `SPEED`
- `UNSYNCED`

Observed bridge IDs:

- `11`
- `115`
- `120`
- `121`

## Phase 1 - Repository Preparation

- Keep the current root `index.php` untouched.
- Add `.gitignore` so generated logs and secrets are not committed.
- Add `.env.example` for future configuration.
- Add `storage/.htaccess` to prevent direct access if `storage` is web-served.
- Add GitHub Actions FTP deploy workflow for cdmon.
- Add docs for deployment and future implementation.

## Phase 2 - Private Viewer

Create the first read-only viewer without changing ingestion:

- Login protection.
- Week selector.
- Bridge selector.
- Type selector: `TELEMETRY`, `SPEED`, `UNSYNCED`.
- Free-text search.
- Pagination.
- Raw line view.
- Weekly summary.

The viewer should read existing weekly JSON files from `storage`.

## Phase 3 - Safer Storage

After the viewer works and production risk is low, migrate ingestion from
rewriting a complete weekly JSON file to append-only NDJSON:

```text
storage/logs/bridge_logs_YYYY_Www.ndjson
```

The viewer should support both formats during migration.

## Phase 4 - Production Cleanup

- Move ingestion to `public/api/ingest.php`.
- Move the private viewer to `public/index.php`.
- Move shared logic to `src/`.
- Keep `storage` outside the public document root when cdmon allows it.
- Remove hardcoded secrets from PHP.

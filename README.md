# logs-devices

Private log viewer and ingestion project for `logs.singularthings.io`.

## Current State

The production ingestion endpoint is still [index.php](index.php) at the
repository root. It receives bridge logs and writes weekly JSON files into
`storage/`.

Do not change the ingestion behavior until the production devices can be tested
against a compatible endpoint.

## Project Layout

```text
.
├── index.php                 # Current production ingestion endpoint
├── storage/                  # Runtime logs, not committed or deployed
├── public/                   # Future web root for viewer/API
├── src/                      # Future shared PHP code
├── scripts/                  # Local maintenance scripts
├── docs/                     # Project and deployment notes
└── .github/workflows/        # GitHub Actions deploy workflow
```

## Useful Commands

Analyze local storage files:

```bash
scripts/analyze-storage.sh
```

## Docs

- [Project plan](docs/PROJECT_PLAN.md)
- [cdmon FTP deploy](docs/CDMON_DEPLOY.md)

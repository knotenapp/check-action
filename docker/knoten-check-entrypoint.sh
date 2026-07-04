#!/bin/sh
# Entrypoint for the Knoten architecture-gate GitHub Action.
#
# Args (from action.yml):
#   $1  project path to check, relative to the repo (default ".")
#   $2  optional rules file, relative to the repo (default: auto-discover)
#
# Runs `knoten:check --json` against the mounted project, turns each violation
# into a GitHub error annotation (so it shows inline on the PR), prints a
# summary, and exits with the check's status so a violation fails the job.
set -e

PROJECT_INPUT="${1:-.}"
CONFIG_INPUT="${2:-}"

PROJECT="$(realpath "$PROJECT_INPUT" 2>/dev/null || true)"

if [ -z "$PROJECT" ] || [ ! -d "$PROJECT" ]; then
    echo "::error::Knoten: project path not found: ${PROJECT_INPUT}"
    exit 1
fi

set -- knoten:check "$PROJECT" --json
if [ -n "$CONFIG_INPUT" ]; then
    set -- "$@" --config="$(realpath "$CONFIG_INPUT")"
fi

# Capture output and exit code without tripping `set -e`.
set +e
OUTPUT="$(php /opt/knoten/artisan "$@")"
CODE=$?
set -e

# Annotations must be relative to the repo root; on GitHub that is the workspace.
printf '%s' "$OUTPUT" | php /opt/knoten/docker/annotate.php "${GITHUB_WORKSPACE:-$PROJECT}"

exit "$CODE"

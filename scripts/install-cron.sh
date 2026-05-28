#!/usr/bin/env bash
#
# install-cron.sh — install (or re-install) the Carafe cron block into
# root's crontab on the smappen droplet. Idempotent: running twice
# produces the same crontab as running once.
#
# How it works:
#   1. Extract the BEGIN/END CARAFE CRON block from scripts/cron/carafe-crontab
#      (the preamble comments in that file are repo-only documentation).
#   2. Read the current crontab (or empty if none).
#   3. Strip out anything between BEGIN/END CARAFE CRON markers, plus any
#      trailing whitespace, from the current crontab.
#   4. Append the extracted Carafe block.
#   5. If the result is byte-identical to what is already installed, do
#      nothing. Otherwise pipe the result into `crontab -`.
#
# Operator-added lines (Smappen cleanup, mysqldump, etc.) outside the
# markers are preserved untouched.
#
# Usage (on the droplet, as root):
#     bash /var/www/smappen/scripts/install-cron.sh
#
# Exit codes:
#   0 — installed / no change needed
#   1 — source crontab file missing, unreadable, or has no BEGIN/END markers
#   2 — crontab(1) not available
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${SCRIPT_DIR}/cron/carafe-crontab"
LOG_DIR="$(dirname "${SCRIPT_DIR}")/storage/logs/cron"

BEGIN_MARK="# === BEGIN CARAFE CRON (managed by install-cron.sh) ==="
END_MARK="# === END CARAFE CRON ==="

if [[ ! -r "${SRC}" ]]; then
    echo "install-cron: source file not readable: ${SRC}" >&2
    exit 1
fi

if ! command -v crontab >/dev/null 2>&1; then
    echo "install-cron: crontab(1) is not installed" >&2
    exit 2
fi

# Per-worker log dir — flock + log paths in the crontab assume it exists.
mkdir -p "${LOG_DIR}"

# Extract just the BEGIN→END block from the source file. The preamble
# above BEGIN is repo-only documentation; we don't want it in the live
# crontab. Use printf so awk sees a definite EOF.
CARAFE_BLOCK="$(awk -v b="${BEGIN_MARK}" -v e="${END_MARK}" '
    $0 == b { p = 1 }
    p       { print }
    $0 == e { p = 0 }
' "${SRC}")"

if [[ -z "${CARAFE_BLOCK}" ]]; then
    echo "install-cron: source file ${SRC} does not contain BEGIN/END CARAFE CRON markers" >&2
    exit 1
fi

# Current crontab — empty if none. `|| true` so `set -e` doesn't bail
# when the crontab is empty (crontab -l exits 1 in that case).
CURRENT="$(crontab -l 2>/dev/null || true)"

# Strip the prior Carafe block (everything between BEGIN/END markers,
# inclusive), then trim trailing blank lines so we don't accumulate
# them across runs.
STRIPPED="$(printf '%s\n' "${CURRENT}" | awk -v b="${BEGIN_MARK}" -v e="${END_MARK}" '
    BEGIN  { inblock = 0 }
    {
        if ($0 == b)               { inblock = 1; next }
        if (inblock && $0 == e)    { inblock = 0; next }
        if (!inblock)              print $0
    }
' | awk '
    # Collapse a run of trailing blank lines into nothing.
    /^$/  { blanks = blanks "\n"; next }
            { printf "%s", blanks; blanks = ""; print }
')"

# Compose new crontab. Separate operator block + Carafe block with one
# blank line if there is any operator content.
if [[ -n "${STRIPPED}" ]]; then
    NEW="${STRIPPED}"$'\n\n'"${CARAFE_BLOCK}"
else
    NEW="${CARAFE_BLOCK}"
fi

# Trim any trailing newlines off both sides before comparing so a
# whitespace-only delta doesn't trip the equality check on re-runs.
# `${var%$'\n'}` only strips one — loop until empty.
trim_trailing_newlines() {
    local s="$1"
    while [[ "${s: -1}" == $'\n' ]]; do s="${s::-1}"; done
    printf '%s' "$s"
}
CURRENT_TRIM="$(trim_trailing_newlines "${CURRENT}")"
NEW_TRIM="$(trim_trailing_newlines "${NEW}")"

if [[ "${CURRENT_TRIM}" == "${NEW_TRIM}" ]]; then
    echo "install-cron: crontab already up-to-date (no change)."
    exit 0
fi

printf '%s\n' "${NEW_TRIM}" | crontab -
echo "install-cron: installed Carafe cron block into root crontab."
echo "install-cron: verify with: crontab -l | sed -n '/BEGIN CARAFE/,/END CARAFE/p'"

#!/usr/bin/env bash
#
# install-cron.sh — install (or re-install) the Carafe cron block into
# root's crontab on the smappen droplet, plus the matching logrotate
# config. Idempotent: running twice produces the same crontab as once.
#
# Steps:
#   1. Extract the BEGIN/END CARAFE CRON block from scripts/cron/carafe-crontab
#      (the preamble comments in that file are repo-only documentation).
#   2. Read the current crontab (or empty if none).
#   3. Strip out anything between BEGIN/END CARAFE CRON markers, plus any
#      trailing whitespace, from the current crontab.
#   4. Append the extracted Carafe block.
#   5. If the result is byte-identical to what is already installed, do
#      nothing. Otherwise pipe the result into `crontab -`.
#   6. Install scripts/cron/carafe-logrotate to /etc/logrotate.d/carafe-cron
#      iff different from the source (logrotate picks it up automatically
#      via /etc/cron.daily/logrotate).
#   7. SELF-TEST: re-read crontab + diff the live Carafe block against the
#      source. Nonzero exit if mismatched. Catches install-cron.sh bugs.
#
# Operator-added lines (Smappen cleanup, mysqldump, etc.) outside the
# markers are preserved untouched.
#
# Usage (on the droplet, as root):
#     bash /var/www/smappen/scripts/install-cron.sh
#
# Exit codes:
#   0 — installed / no change needed (and self-test passed)
#   1 — source crontab file missing, unreadable, or has no BEGIN/END markers
#   2 — crontab(1) not available
#   3 — self-test FAILED: live Carafe block doesn't match source (bug in this script)
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${SCRIPT_DIR}/cron/carafe-crontab"
LOGROTATE_SRC="${SCRIPT_DIR}/cron/carafe-logrotate"
LOGROTATE_DST="/etc/logrotate.d/carafe-cron"
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
    /^$/  { blanks = blanks "\n"; next }
            { printf "%s", blanks; blanks = ""; print }
')"

if [[ -n "${STRIPPED}" ]]; then
    NEW="${STRIPPED}"$'\n\n'"${CARAFE_BLOCK}"
else
    NEW="${CARAFE_BLOCK}"
fi

trim_trailing_newlines() {
    local s="$1"
    while [[ "${s: -1}" == $'\n' ]]; do s="${s::-1}"; done
    printf '%s' "$s"
}
CURRENT_TRIM="$(trim_trailing_newlines "${CURRENT}")"
NEW_TRIM="$(trim_trailing_newlines "${NEW}")"

if [[ "${CURRENT_TRIM}" == "${NEW_TRIM}" ]]; then
    echo "install-cron: crontab already up-to-date (no change)."
else
    printf '%s\n' "${NEW_TRIM}" | crontab -
    echo "install-cron: installed Carafe cron block into root crontab."
fi

# ─────────────────────────── logrotate ───────────────────────────
if [[ -r "${LOGROTATE_SRC}" ]]; then
    if [[ -f "${LOGROTATE_DST}" ]] && cmp -s "${LOGROTATE_SRC}" "${LOGROTATE_DST}"; then
        echo "install-cron: logrotate config already up-to-date (no change)."
    else
        install -m 644 "${LOGROTATE_SRC}" "${LOGROTATE_DST}"
        echo "install-cron: installed logrotate config to ${LOGROTATE_DST}."
        # Sanity-check the new config — exits nonzero on parse error.
        if command -v logrotate >/dev/null 2>&1; then
            logrotate -d "${LOGROTATE_DST}" >/dev/null 2>&1 \
                && echo "install-cron: logrotate -d parsed config OK." \
                || echo "install-cron: WARNING — logrotate -d reported a parse error on ${LOGROTATE_DST}." >&2
        fi
    fi
else
    echo "install-cron: NOTE — logrotate source ${LOGROTATE_SRC} not found; skipping." >&2
fi

# ─────────────────────────── self-test ───────────────────────────
# Read the crontab back and diff the live Carafe block against the source.
# If install-cron.sh ever ships a bug that corrupts the install, this
# fails loudly instead of leaving cron quietly wrong.
LIVE_BLOCK="$(crontab -l 2>/dev/null | awk -v b="${BEGIN_MARK}" -v e="${END_MARK}" '
    $0 == b { p = 1 }
    p       { print }
    $0 == e { p = 0 }
')"

if [[ "${LIVE_BLOCK}" != "${CARAFE_BLOCK}" ]]; then
    echo "install-cron: SELF-TEST FAILED — live crontab Carafe block does not match source." >&2
    echo "----- source -----" >&2
    printf '%s\n' "${CARAFE_BLOCK}" >&2
    echo "----- live -----" >&2
    printf '%s\n' "${LIVE_BLOCK}" >&2
    exit 3
fi

echo "install-cron: self-test passed — live crontab matches source byte-for-byte."
echo "install-cron: verify with: crontab -l | sed -n '/BEGIN CARAFE/,/END CARAFE/p'"

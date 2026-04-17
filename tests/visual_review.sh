#!/bin/bash
# visual_review.sh — feed a directory of screenshots through Gemini and
# aggregate any observable-correctness issues it finds. Exit 1 on any 'high'
# severity, 0 otherwise. Warnings for medium/low go to stdout.
#
# Usage: visual_review.sh <screenshot_dir> [<prompt_file>]
#
# Runs on the dev machine (needs `gemini` CLI on PATH, authenticated).
# Designed to consume screenshots captured by the L4 UI-interaction subagent.

set -u
SCREEN_DIR="${1:?usage: visual_review.sh <screenshot_dir> [prompt_file]}"
PROMPT_FILE="${2:-$(dirname "$0")/visual_prompt.txt}"
MODEL="${GEMINI_MODEL:-gemini-3-flash-preview}"
FALLBACK_MODEL="${GEMINI_FALLBACK:-gemini-2.5-flash}"
TIMEOUT_S="${GEMINI_TIMEOUT:-60}"

if [ ! -d "$SCREEN_DIR" ]; then
    echo "ERROR: screenshot dir not found: $SCREEN_DIR" >&2
    exit 2
fi
if [ ! -f "$PROMPT_FILE" ]; then
    echo "ERROR: prompt file not found: $PROMPT_FILE" >&2
    exit 2
fi
if ! command -v gemini >/dev/null 2>&1; then
    echo "ERROR: 'gemini' CLI not on PATH. Install from npm (see ~/.claude/skills/gemini-cli/SKILL.md)." >&2
    exit 2
fi

PROMPT=$(cat "$PROMPT_FILE")
HIGH_COUNT=0
MED_COUNT=0
LOW_COUNT=0
AGGREGATE_FILE="$SCREEN_DIR/visual_review_report.json"

# Build aggregate JSON incrementally (simple concatenation, not full-parse)
printf '{"reviewed_at":"%s","model":"%s","screenshots":[\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$MODEL" > "$AGGREGATE_FILE"

first=1
shopt -s nullglob
pushd "$SCREEN_DIR" >/dev/null
for img in *.png; do
    echo "[review] $img ..."
    # Run Gemini. Fall back to secondary model on non-zero exit.
    raw=$(timeout "$TIMEOUT_S" gemini -m "$MODEL" -o text -p "@$img $PROMPT" 2>/dev/null | tail -1)
    if [ -z "$raw" ] || ! echo "$raw" | grep -q '"issues"'; then
        echo "  primary model empty/malformed — retrying with fallback: $FALLBACK_MODEL"
        raw=$(timeout "$TIMEOUT_S" gemini -m "$FALLBACK_MODEL" -o text -p "@$img $PROMPT" 2>/dev/null | tail -1)
    fi
    if [ -z "$raw" ] || ! echo "$raw" | grep -q '"issues"'; then
        echo "  [warn] could not obtain structured response — skipping $img"
        continue
    fi

    # Validate JSON + tally severities (simple grep-count — no jq dependency)
    hi=$(echo "$raw" | grep -oE '"severity":"high"'   | wc -l | tr -d ' ')
    me=$(echo "$raw" | grep -oE '"severity":"medium"' | wc -l | tr -d ' ')
    lo=$(echo "$raw" | grep -oE '"severity":"low"'    | wc -l | tr -d ' ')
    HIGH_COUNT=$(( HIGH_COUNT + hi ))
    MED_COUNT=$((  MED_COUNT  + me ))
    LOW_COUNT=$((  LOW_COUNT  + lo ))

    if [ "$hi" -gt 0 ]; then echo "  [HIGH $hi]"; fi
    if [ "$me" -gt 0 ]; then echo "  [med $me]";  fi
    if [ "$lo" -gt 0 ]; then echo "  [low $lo]";  fi

    # Append to aggregate
    [ "$first" -eq 0 ] && printf ',\n' >> "$AGGREGATE_FILE"
    printf '  {"screenshot":"%s","findings":%s}' "$img" "$raw" >> "$AGGREGATE_FILE"
    first=0
done
popd >/dev/null
printf '\n],"totals":{"high":%d,"medium":%d,"low":%d}}\n' \
    "$HIGH_COUNT" "$MED_COUNT" "$LOW_COUNT" >> "$AGGREGATE_FILE"

echo
echo "=== Visual review summary ==="
echo "  Screenshots reviewed: $(ls -1 "$SCREEN_DIR"/*.png 2>/dev/null | wc -l | tr -d ' ')"
echo "  High-severity: $HIGH_COUNT"
echo "  Medium:        $MED_COUNT"
echo "  Low:           $LOW_COUNT"
echo "  Report: $AGGREGATE_FILE"

if [ "$HIGH_COUNT" -gt 0 ]; then
    echo "FAIL: $HIGH_COUNT high-severity issue(s)" >&2
    exit 1
fi
exit 0

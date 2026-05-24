#!/bin/bash
# visual_review.sh — thin shim that invokes the shared Claude vision batcher with
# this plugin's prompt. Keeps the per-plugin file tiny; improvements to the
# batcher benefit every plugin that adopts the pattern.

set -u
if [ "$#" -lt 1 ]; then
    echo "usage: visual_review.sh <screenshot_dir> [--since=<epoch>|--manifest=<file>]" >&2
    exit 2
fi
SCREEN_DIR="$1"; shift
THIS_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SHARED_BIN="$(cd "$THIS_DIR/../.." && pwd)/.claude/skills/unraid-testing/shared/visual_review.sh"
SHARED_PROMPT="$(cd "$THIS_DIR/../.." && pwd)/.claude/skills/unraid-testing/shared/visual_prompt.txt"
LOCAL_PROMPT="$THIS_DIR/visual_prompt.txt"

if [ ! -f "$SHARED_BIN" ]; then
    echo "ERROR: shared visual_review.sh not found at $SHARED_BIN" >&2
    exit 2
fi

# Concatenate shared + per-plugin prompts so Claude gets base rules + scope
TMP_PROMPT=$(mktemp)
cat "$SHARED_PROMPT" > "$TMP_PROMPT"
[ -f "$LOCAL_PROMPT" ] && { echo "" >> "$TMP_PROMPT"; cat "$LOCAL_PROMPT" >> "$TMP_PROMPT"; }
trap 'rm -f "$TMP_PROMPT"' EXIT

# Forward any remaining flags (--since / --manifest / --*) through to the shared batcher.
bash "$SHARED_BIN" "$SCREEN_DIR" "$TMP_PROMPT" "$@"

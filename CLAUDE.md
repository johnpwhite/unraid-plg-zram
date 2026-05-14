# unraid-plg-zram — project facts

## OpenProject — project facts

**Project ID:** `22`
**Identifier:** `unraid-plg-zram`
**Name:** unraid-plg-zram
**Parent:** `unraid-plugins` (id 20)

**Type IDs:** Task=1, Milestone=2, Summary task=3, Feature=4, Epic=5, User story=6, Bug=7
**Status IDs:** New=1
**Priority IDs:** Default=8

**Custom fields** (Feature/Epic only — NOT exposed on Bug type per `list field-availability 22`):
| API key | Human name | Type |
|---|---|---|
| `customField3` | `canonical_spec` | Text |
| `customField4` | `related_adr` | Text |

For Bug WPs, capture spec links inline in the description until the form
layout is updated to expose `canonical_spec` on the Bug type.

**Existing WPs of record:**

Features (open):
- #58  [Feature] Expose mem_limit parameter in settings UI
- #59  [Feature] Option to mount /tmp on ZRAM (compressed tmpfs)
- #60  [Feature] Option to mount /var/log on ZRAM (compressed log storage)

Bugs — author-found / current arc (open):
- #422 [Bug] Dashboard Compressed chip shows TOTAL (RAM occupied), not COMPR — label/source mismatch
- #423 [Bug] (duplicate of #422 — auto-rejected, ignore)
- #749 [Bug] Tier 2 disk swap: no recovery path when boot-retry poller times out (long array/mount outage) — fix in progress, spec `docs/specs/TIER2_RECOVERY.md` (ACTIVATE button + collector self-heal)
- #774 [Bug] Plugin .plg install leaves 0-byte stubs on partial network failure; uninstall destroys Tier 2 swap file — fix in progress, spec `docs/specs/INSTALL_HARDENING.md` (fetch-to-temp + --tries=3 + abort-on-fail + post-install sanity gate; remove script keeps swap file)

Bugs — retroactive forum reports (open, fixes shipped — close manually in UI):
- #424 [Bug] Tier 2-only dashboard renders empty — fixed v2026.05.06.11 (DASHBOARD_TIER2_VISIBILITY.md)
- #425 [Bug] Tier 2 disk swap inactive after reboot when mount not ready — fixed v2026.05.06.09 (TIER2_BOOT_RETRY.md)
- #426 [Bug] REMOVE button state-sync — fixed v2026.05.03.01 (REMOVE_ZRAM_STATE_SYNC.md)
- #427 [Bug] CREATE ignores live form values — fixed v2026.05.03.01 (CREATE_ZRAM_LIVE_PARAMS.md)

**OpenProject workflow note:** the API role does not have permission to
transition `New → Closed` directly (workflow restriction). Either close the
shipped retroactive bugs in the UI, or use an admin role for the API token
when closing programmatically.

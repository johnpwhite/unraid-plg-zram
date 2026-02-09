# Workflow: Unraid ZRAM Development & Release

Use this file to resume development in a clean Gemini CLI session.

## 1. Context Onboarding

- **Project**: Unraid ZRAM Status & Management Plugin.
- **Goal**: Maintain a high-quality dashboard card and swap management utility for Unraid 7.2+.
- **Architecture**: 
  - **GitLab (Private)**: The "Factory". Detailed commit history, interim releases, and full debug logs.
  - **GitHub (Public)**: The "Storefront". Clean, squashed history with professional README and images.

## 2. Essential Skills (Load First)

Always ask the agent to read `AGENT_SKILL_UNRAID_PLUGIN.md` at the start of a session. It contains the "Golden Path" findings:

- Hybrid XML Strategy (Hardcoded header, flexible payload).
- **Ampersand Safety**: Always use `&amp;` instead of `&` in changelog and scripts.
- Dashboard Safety (No nested `<tbody>` tags).
- Boot Persistence (`zram_init.sh` via `.plg` install phase).
- Dynamic Binary Path detection.

## 3. Standard Operating Procedures (SOP)

### Phase 1: Development & Local Testing

1. **Modify Source**: Edit files in `src/unraid-zram-card/`.
2. **Sync PLG**: Update the version number and changelog in `unraid-zram-card.plg`.
   - ⚠️ **Remember**: Use `&amp;` for any `&` characters (e.g., `Time &amp; Pulse`).
3. **GitLab Push**:
   - `git add .`
   - `git commit -m "feat/fix: description (v.XX)"`
   - `git push origin master`
4. **Local Install**: Test on Unraid by browsing to the local `.plg` or via GitLab raw URL.

### Phase 2: Public Release (GitHub)

**Action**: Only perform this when explicitly requested by the user.

1. **Update Public Assets**: Ensure `README.public.md` and `CHANGES.public.xml` are ready.
2. **Run Automation**: Execute `./publish-to-github.ps1` in PowerShell.
3. **Verification**: Confirm the new "Official Release" commit appears on GitHub and the `.plg` URLs are correctly rewritten to the GitHub raw source.

## 4. Key File Map

- `unraid-zram-card.plg`: The heart of the plugin.
- `src/unraid-zram-card/ZramCard.php`: Dashboard logic (Function Pattern).
- `src/unraid-zram-card/UnraidZramCard.page`: Settings UI (Card-based).
- `src/unraid-zram-card/zram_swap.php`: Backend device manager (with Safety Guards).
- `src/unraid-zram-card/zram_init.sh`: Boot persistence script.
- `publish-to-github.ps1`: Release automation.

---

**Status**: Ready for development. GitLab is current. GitHub is at Version 2026.01.25.66.

# Context: Unraid ZRAM Status & Management

Plugin for managing and monitoring ZRAM swap devices on Unraid 7.2+.

## Project Specifics
- **Architecture**: PHP (Dynamix Style).
- **Backend**: `zramctl` / `zram_swap.php`.
- **Persistence**: `zram_init.sh` boot script.

## Local Reference
- **Project Context**: `docs/architecture.md`
- **User Guide**: `README.public.md` (Public Help & Settings Guide)

## Development Workflow
- **Standard**: `activate_skill unraid-plugin`
- **Staging/Testing**: `activate_skill unraid-factory` (**MANDATORY** for GitLab commits). Use `/cmt-plg zram` or the `update-version.ps1`/`update-index.ps1` scripts for all commits.
- **Public Release**: `activate_skill unraid-storefront` (`/pub-plg zram`)

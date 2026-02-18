# Unraid Plugin: ZRAM Status & Management

## 1. Context & Goals
**Project**: Unraid ZRAM Status & Management Plugin.
**Goal**: Maintain a high-quality dashboard card and swap management utility for Unraid 7.2+.

**Generic Development Guide:**
For general Unraid Plugin development workflow (`.plg` XML, Dashboard safety, etc.), refer to the **Master Index**:
[unraid-community-applications-index/unraid-development-guide.md](../../unraid-community-applications-index/unraid-development-guide.md)

---

## 2. Project Architecture

### Key File Map
*   **`unraid-zram-card.plg`**: The main installer and lifecycle manager.
*   **`src/unraid-zram-card/ZramCard.php`**: Dashboard card logic (Function Pattern).
*   **`src/unraid-zram-card/UnraidZramCard.page`**: Settings UI (Native Dynamix).
*   **`src/unraid-zram-card/zram_swap.php`**: Backend device manager (with Safety Guards).
*   **`src/unraid-zram-card/zram_init.sh`**: Boot persistence script (via `.plg` install phase).

### Core Technical Principles
- **Boot Persistence**: The `zram_init.sh` script is executed during the plugin's installation phase to ensure ZRAM devices are initialized upon every system boot.
- **Dynamic Binary Path**: All backend calls to `zramctl` or `swapon` must check for existence in standard paths (`/sbin/`, `/usr/sbin/`) to ensure compatibility with Unraid 7.2+.
- **Ampersand Safety**: Always use `&amp;` instead of `&` in the `.plg` XML to prevent parser crashes.

## 3. Workflow (Factory vs Storefront)
This project follows the **"Factory (GitLab) vs. Storefront (GitHub)"** strategy.
- For private development and testing (GitLab), use the `unraid-factory` skill (`/cmt-plg`).
- For official public releases (GitHub), use the `unraid-storefront` skill (`/pub-plg`).


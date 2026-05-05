# Parked Tools

Tools that are built but not shipped because they hit walls during live testing.
The tool loader (`loadTools()` in `Frogman.class.php`) scans `Tools/*.php` non-recursively, so files here are NOT auto-registered.

## How to revive

1. Resolve the underlying blocker (see notes per-file and `memory/project_sc_onboarding_combo.md`).
2. Rename `.php.parked` → `.php` and move back to `Tools/`.
3. Re-add the chat parser entries (see git history for the deleted block).
4. Re-add the formatter case in `Frogman.class.php`.
5. Deploy.

## Currently parked (2026-05-05)

### `SangomaConnectEnableUser.php.parked` — `fm_sc_enable_user`
**Wall:** `\FreePBX::Sangomaconnect()->enableUserFromWizard($ext, $umId, false)` fails with:
- AJAX context: `array_push(): Argument #1 ($array) must be of type array, bool given`
- CLI context: `Cannot modify header information - headers already sent`

The BMO method appears wired to expect AJAX dispatcher context, not direct calls.

**Revive when:** we identify either (a) the per-user GUI AJAX command name + replicate via a proper public BMO method, or (b) a non-AJAX-bound BMO entry point.

### `SangomaConnectConfigureDomain.php.parked` — `fm_sc_configure_domain`
**Wall (and root cause):** SC handles its own domain provisioning via Sangoma-issued `<UUID>.connect.sangoma.com` subdomains during the GUI install. Frogman should NEVER set the domain. This tool was attempting the wrong thing entirely.

**Revive only if:** there's a specific re-registration or recovery scenario that requires programmatic domain control. Probably never useful for initial bring-up.

# Snippa MU-Plugin Implementation Plan

> **Baseline:** This implementation will maximize use of WordPress built-in functionality, styles, and admin patterns to minimize custom code. All UI and logic should leverage core WordPress features wherever possible.

> **Note:** This implementation will avoid unnecessary object-oriented programming (OOP). Procedural code and plain functions will be used wherever possible to minimize code size and complexity. Classes will only be used where required by WordPress hooks or for clear, tangible benefits.

---

## Snippet Docblock Format

Each snippet PHP file should begin with a docblock in the following format:

```php
<?php
/**
 * Snippet: Custom WooCommerce checkout fields
 * Description: Adds company VAT field to checkout with validation
 * Tags: woocommerce, checkout, ecommerce
 * Required: woocommerce>=5.0
 * Secrets: vat_api_key
 * Version: 1.2.0
 * Author: Your Name
 * Priority: 10
 * Context: frontend
 */
```

**Supported fields:**
- Snippet (required): Name/title of the snippet
- Description: Short description of what the snippet does
- Tags: Comma-separated list of tags
- Required: Dependencies and version constraints
- Secrets: Comma-separated list of required secrets
- Version: Snippet version
- Author: Author name
- Priority: Integer for load order
- Context: Where the snippet should run (e.g., frontend, admin)

---

## Phase 1: Project Bootstrapping & Setup Detection

**Tasks:**
- Create `snippa.php` in `mu-plugins/`.
- On load, check if `wp-content/snippets/` exists.
- If not, display a setup interface in wp-admin.

**Testing:**
- **Manual:** Remove/rename `snippets/` and reload wp-admin. Confirm setup UI appears.
- **Visual:** Check for correct WordPress admin styling and clear instructions.
- **Script:** Write a WP-CLI or PHPUnit test to simulate missing directory and assert setup mode triggers.

---

## Phase 2: Repository Cloning

**Tasks:**
- Prompt for GitHub repo URL in setup UI.
- Clone repo into `wp-content/snippets/` using PHP's `exec()` or similar.
- Handle errors (missing git, permissions, invalid URL).

**Testing:**
- **Manual:** Enter valid/invalid URLs, test with/without git installed, check error messages.
- **Visual:** Confirm progress indicators and error notices use `admin_notices`.
- **Script:** Mock `exec()` to simulate failures and assert error handling.

---

## Phase 3: Snippet Discovery & Caching

**Tasks:**
- Scan `snippets/` for `.php` files.
- Parse docblocks for metadata (name, description, tags, dependencies).
- Cache parsed data in a WordPress option.
- Only re-parse on git pull or manual refresh.

**Testing:**
- **Manual:** Add/remove `.php` files, run refresh, check admin listing updates.
- **Visual:** Confirm snippet list displays correct metadata.
- **Script:** Unit test docblock parser with various header formats.

---

## Phase 4: Admin UI & Snippet Management

**Tasks:**
- Use `WP_List_Table` to list snippets with toggle switches.
- Store enabled/disabled state in options.
- Add bulk actions, sorting, and filtering by tag.
- Show snippet details (description, version, dependencies).

**Testing:**
- **Manual:** Toggle snippets, use bulk actions, filter by tag, check persistence after reload.
- **Visual:** Confirm UI matches WordPress standards, no layout issues.
- **Script:** Test option updates and state persistence.

---

## Phase 5: Git Integration

**Tasks:**
- Add "Git Pull" button to admin.
- On pull, re-scan changed files only.
- Show last sync time and repo URL (read-only).
- Implement rollback (git checkout to previous commit).

**Testing:**
- **Manual:** Click "Git Pull", verify new/updated snippets appear, check last sync time.
- **Visual:** Confirm status messages and repo URL display.
- **Script:** Mock git output, test changed file detection and rollback.

---

## Phase 6: Webhook Endpoint

**Tasks:**
- Register REST API endpoint for GitHub webhooks.
- Validate GitHub signature.
- On valid push, auto-run git pull and refresh cache.

**Testing:**
- **Manual:** Send test webhook from GitHub, check for auto-sync.
- **Script:** Use curl to POST webhook payloads, test signature validation and sync trigger.

---

## Phase 7: Snippet Loading Logic

**Tasks:**
- On `init`, load all enabled snippets via `require_once`.
- Handle dependencies and error reporting.

**Testing:**
- **Manual:** Enable/disable snippets, check for expected behavior on frontend/backend.
- **Script:** PHPUnit test to ensure only enabled snippets are loaded.

---

## Phase 8: Uninstall & Cleanup

**Tasks:**
- Add admin button to delete all Snippa data and `snippets/` directory.
- Remove options and cached data.

**Testing:**
- **Manual:** Click uninstall, confirm all data and files are removed.
- **Script:** Test cleanup function removes options and directory.

---

## General Testing

- **Manual:** Use the plugin as an admin, following all user flows.
- **Visual:** Check for consistent WordPress UI/UX.
- **Script:** Where possible, automate with PHPUnit, WP-CLI, or custom scripts.

---

**Tip:** For each phase, document test cases and edge conditions. Consider using a checklist or test matrix to track coverage. 
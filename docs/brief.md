# Snippa MU-Plugin Brief

## Core Concept
A single-file WordPress MU-plugin that manages centralized code snippets from a GitHub repository. Assumes and uses Git-based versioning with a simple toggle interface for enabling/disabling snippets.

## Key Features

### Self-Setup Capability
- **Interactive setup mode**: Detects if snippets directory exists, shows setup interface if not
- **Repository cloning**: Prompts for GitHub repo URL and clones automatically
- **Progressive interface**: Setup mode → scanning mode → management mode
- **Error handling**: Helpful messages for Git availability, permissions, etc.

### Snippet Management
- **Auto-discovery**: Scans `wp-content/snippets/` for PHP files
- **Smart caching**: Full docblock parsing only on git pulls or manual refresh
- **Change detection**: Lightweight file stat comparison for quick admin page loads
- **Selective re-parsing**: Only re-reads changed files after git pull using git output
- **Header parsing**: Reads WordPress-style docblock headers for metadata
- **Tag-based filtering**: Only shows snippets matching configured site tags
- **Toggle interface**: Simple on/off switches for each snippet
- **Snippet details**: Description, version, dependencies from headers

### Git Integration
- **Repository sync**: Git pull button to update snippets
- **Webhook endpoint**: Registers REST API endpoint to receive GitHub webhooks
- **Auto-sync on push**: Automatically runs git pull when repository is updated
- **Webhook security**: Validates GitHub signature to ensure legitimate requests
- **Version safety**: Git-based rollback capability
- **Last sync tracking**: Shows when snippets were last updated

### WordPress Integration
- **MU-plugin benefits**: Always loaded, hidden from plugin listing, can't be disabled
- **Admin menu**: Accessible via "Snippets" menu item in wp-admin
- **Repository display**: Shows configured GitHub URL in read-only field
- **Hook integration**: Loads active snippets during WordPress `init`
- **Options storage**: Uses WordPress options for toggle states and configuration
- **Clean uninstall**: Admin button to delete all Snippa data and snippets directory

## Implementation Guidelines

**Maximize WordPress Defaults**: Use built-in WordPress admin components, styles, and patterns wherever possible to minimize custom code:
- `WP_List_Table` for snippet listing (sorting, pagination, bulk actions)
- `admin_notices` for status messages
- `<table class="form-table">` for settings layouts  
- `submit_button()` and standard form helpers
- Settings API with `register_setting()`
- Default button classes (`button-primary`, `button-secondary`)
- WordPress modal dialogs and confirmation patterns

## Plugin Structure

**Single File Implementation:**
```php
<?php
/**
 * Plugin Name: Snippa
 * Description: Git-backed snippet manager
 */

class Snippa_MU {
    // Setup detection and routing
    // Admin page registration  
    // Settings handling
    // Git operations
    // Snippet discovery and caching
    // REST endpoint for webhooks
    // Uninstall functionality
}

// WordPress hooks
add_action('admin_menu', [new Snippa_MU(), 'add_admin_menu']);
add_action('rest_api_init', [new Snippa_MU(), 'register_webhook']);
add_action('init', [new Snippa_MU(), 'load_active_snippets']);
```

**File Structure:**
```
wp-content/
├── mu-plugins/
│   └── snippa.php                     # Complete MU-plugin (setup + management)
└── snippets/                          # Git repository (created by setup)
    ├── example-snippet.php
    ├── woocommerce-tweaks.php
    └── performance-optimizations.php
```

**Snippa.php contains:**
- Setup mode interface and repository cloning
- Snippet discovery and header parsing
- Admin page with toggle switches
- Git sync functionality
- Snippet loading logic
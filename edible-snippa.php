<?php
/**
 * Plugin Name: Snippa
 * Description: Git-backed snippet manager (regular plugin version for development)
 * Version: 0.1.0
 * Author: Your Name
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ===== Constants & Setup =====
// Path to the snippets directory (relative to wp-content)
define( 'SNIPPA_SNIPPETS_DIR', WP_CONTENT_DIR . '/snippets/' );

define( 'SNIPPA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

define( 'SNIPPA_OPTION_KEY', 'snippa_options' );

define( 'SNIPPA_CACHE_KEY', 'snippa_snippet_cache' );

/*
====================================
Snippa Option Structure Documentation
====================================

1. snippa_snippet_cache (array of associative arrays):
   [
     [
       'Snippet'    => (string) Name/title of the snippet,
       'Description'=> (string) Description,
       'Tags'       => (string) Comma-separated tags,
       'Required'   => (string) Dependencies/version constraints,
       'Secrets'    => (string) Comma-separated secrets,
       'Version'    => (string) Snippet version,
       'Author'     => (string) Author name,
       'Priority'   => (string|int) Load order,
       'Context'    => (string) Where snippet should run,
       'file'       => (string) Filename (e.g., 'my-snippet.php')
     ],
     ...
   ]

2. snippa_enabled_snippets (array of strings):
   [
     'my-snippet.php',
     'another-snippet.php',
     ...
   ]
*/

// Ensure standard binary paths are available for exec() calls
putenv('PATH=/usr/bin:/usr/local/bin:' . getenv('PATH'));

// ===== Admin Notices =====
// Setup detection: show admin notice if snippets directory is missing
add_action( 'admin_notices', function() {
    if ( ! is_dir( SNIPPA_SNIPPETS_DIR ) ) {
        echo '<div class="notice notice-warning"><p><strong>Snippa:</strong> The <code>wp-content/snippets/</code> directory is missing. Please run setup to continue.</p></div>';
    }
} );

// Add Snippa admin menu
add_action( 'admin_menu', function() {
    add_menu_page(
        'Snippa Setup',
        'Snippa',
        'manage_options',
        'snippa',
        'snippa_admin_page',
        'dashicons-editor-code',
        65
    );
} );

// ===== Setup/Clone Logic =====
// Handle form submission and redirect in admin_init (WordPress standard pattern)
add_action('admin_init', function() {
    if (
        isset($_POST['snippa_github_url']) &&
        check_admin_referer('snippa_setup') &&
        current_user_can('manage_options')
    ) {
        $repo_url = trim(sanitize_text_field($_POST['snippa_github_url']));
        $output = $return_var = '';
        if (empty($repo_url)) {
            wp_redirect(admin_url('admin.php?page=snippa&snippa_error=missing_url'));
            exit;
        } elseif (!snippa_git_available()) {
            wp_redirect(admin_url('admin.php?page=snippa&snippa_error=missing_git'));
            exit;
        } else {
            $cmd = 'git clone ' . escapeshellarg($repo_url) . ' ' . escapeshellarg(SNIPPA_SNIPPETS_DIR) . ' 2>&1';
            exec($cmd, $output, $return_var);
            if ($return_var === 0 && is_dir(SNIPPA_SNIPPETS_DIR)) {
                wp_redirect(admin_url('admin.php?page=snippa&snippa_cloned=1'));
                exit;
            } else {
                $error = esc_html(implode("\n", $output));
                wp_redirect(admin_url('admin.php?page=snippa&snippa_error=' . urlencode($error)));
                exit;
            }
        }
    }
});

// Show notices based on query parameters
add_action('admin_notices', function() {
    $snippa_params = ['snippa_cloned', 'snippa_refreshed', 'snippa_error', 'snippa_git_pulled'];
    $js_remove = '';
    foreach ($snippa_params as $param) {
        if (isset($_GET[$param])) {
            $js_remove .= "u.searchParams.delete('{$param}');";
        }
    }
    if (isset($_GET['snippa_cloned'])) {
        echo '<div class="notice notice-success"><p><strong>Snippa:</strong> Repository cloned successfully!</p></div>';
    }
    if (isset($_GET['snippa_refreshed'])) {
        echo '<div class="notice notice-success"><p><strong>Snippa:</strong> Snippet list refreshed.</p></div>';
    }
    if (isset($_GET['snippa_git_pulled'])) {
        echo '<div class="notice notice-success"><p><strong>Snippa:</strong> Git pull and snippet refresh complete.</p></div>';
    }
    if (isset($_GET['snippa_error'])) {
        $msg = '';
        if ($_GET['snippa_error'] === 'missing_url') {
            $msg = 'Please enter a GitHub repository URL.';
        } elseif ($_GET['snippa_error'] === 'missing_git') {
            $msg = 'Git is not available on the server. Please install git.';
        } else {
            $msg = 'Failed to clone repository.<br><pre style="white-space:pre-wrap;">' . esc_html($_GET['snippa_error']) . '</pre>';
        }
        echo '<div class="notice notice-error"><p><strong>Snippa:</strong> ' . $msg . '</p></div>';
    }
    if ($js_remove) {
        echo '<script>if(window.history.replaceState){var u=new URL(window.location);' . $js_remove . 'window.history.replaceState({},"",u);}</script>';
    }
});

// ===== Snippet Discovery & Caching =====
// Snippet discovery and caching
function snippa_discover_snippets() {
    $dir = SNIPPA_SNIPPETS_DIR;
    $snippets = [];
    if ( ! is_dir( $dir ) ) {
        return $snippets;
    }
    $files = glob( $dir . '*.php' );
    foreach ( $files as $file ) {
        $meta = snippa_parse_docblock( $file );
        if ( ! empty( $meta['Snippet'] ) ) {
            $meta['file'] = basename( $file );
            if (empty($meta['Context'])) {
                $meta['Context'] = 'frontend,backend';
            }
            $snippets[] = $meta;
        }
    }
    // Filter by allowed tags before caching
    $snippets = snippa_filter_snippets_by_tags($snippets);
    update_option( SNIPPA_CACHE_KEY, $snippets );
    return $snippets;
}

// Parse docblock for snippet metadata
function snippa_parse_docblock( $file ) {
    $meta = [];
    $lines = file( $file );
    $in = false;
    foreach ( $lines as $line ) {
        if ( strpos( $line, '/**' ) !== false ) {
            $in = true;
            continue;
        }
        if ( $in && strpos( $line, '*/' ) !== false ) {
            break;
        }
        if ( $in ) {
            if ( preg_match( '/\*\s*(\w+):\s*(.+)/', $line, $m ) ) {
                $meta[ trim( $m[1] ) ] = trim( $m[2] );
            }
        }
    }
    return $meta;
}

// ===== Snippet List Refresh Logic =====
// Handle refresh action
add_action( 'admin_init', function() {
    if ( isset( $_GET['snippa_refresh'] ) && current_user_can( 'manage_options' ) ) {
        $snippets = snippa_discover_snippets();
        $enabled = get_option('snippa_enabled_snippets', []);
        $all_files = array_column($snippets, 'file');
        // Keep only enabled snippets that still exist
        $enabled = array_values(array_intersect($enabled, $all_files));
        // Enable new snippets by default
        $new_files = array_diff($all_files, $enabled);
        $enabled = array_merge($enabled, $new_files);
        update_option('snippa_enabled_snippets', $enabled);
        wp_redirect( admin_url( 'admin.php?page=snippa&snippa_refreshed=1' ) );
        exit;
    }
} );

// ===== Admin UI =====
// Handle snippet enable/disable form submission
add_action('admin_init', function() {
    if (
        isset($_POST['snippa_enabled']) &&
        check_admin_referer('snippa_toggle') &&
        current_user_can('manage_options')
    ) {
        $enabled = array_map('sanitize_text_field', (array)$_POST['snippa_enabled']);
        update_option('snippa_enabled_snippets', $enabled);
        wp_redirect(admin_url('admin.php?page=snippa&snippa_refreshed=1'));
        exit;
    }
});

// ===== Tag Filtering Logic =====
// Handle allowed tags form submission
add_action('admin_init', function() {
    if (
        isset($_POST['snippa_allowed_tags']) &&
        check_admin_referer('snippa_tags') &&
        current_user_can('manage_options')
    ) {
        $tags = trim(sanitize_text_field($_POST['snippa_allowed_tags']));
        update_option('snippa_allowed_tags', $tags);
        wp_redirect(admin_url('admin.php?page=snippa&snippa_refreshed=1'));
        exit;
    }
});

// Helper: Get allowed tags as array (lowercase, trimmed)
function snippa_get_allowed_tags() {
    $tags = get_option('snippa_allowed_tags', '');
    if ($tags === '') return [];
    return array_filter(array_map('trim', array_map('strtolower', explode(',', $tags))));
}

// Filter snippets by allowed tags
function snippa_filter_snippets_by_tags($snippets) {
    $allowed = snippa_get_allowed_tags();
    if (empty($allowed)) return $snippets;
    $filtered = [];
    foreach ($snippets as $s) {
        $snippet_tags = isset($s['Tags']) ? array_filter(array_map('trim', array_map('strtolower', explode(',', $s['Tags']))) ) : [];
        if (array_intersect($allowed, $snippet_tags)) {
            $filtered[] = $s;
        }
    }
    return $filtered;
}

// Extend admin page to show snippet list and refresh button
function snippa_admin_page() {
    if ( is_dir( SNIPPA_SNIPPETS_DIR ) ) {
        echo '<div class="wrap"><h1>Snippa</h1>';
        echo '<div style="display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 1em;">';
        // Left: buttons
        echo '<div>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=snippa&snippa_git_pull=1' ) ) . '" class="button button-primary" style="margin-right:1em;">Git Pull</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=snippa&snippa_refresh=1' ) ) . '" class="button button-secondary">Refresh Snippet List</a>';
        echo '</div>';
        // Right: webhook endpoint
        echo '<div style="text-align: right;">';
        $last_webhook = get_option('snippa_last_webhook');
        if ($last_webhook) {
            echo '<div style="font-size: 12px; color: #666; margin-bottom: 0.5em;">Last webhook call: ' . esc_html($last_webhook) . '</div>';
        } else {
            echo '<div style="font-size: 12px; color: #666; margin-bottom: 0.5em;">Last webhook call: Never</div>';
        }
        $webhook_url = esc_url( rest_url('snippa/v1/webhook') );
        echo '<label for="snippa-webhook-url" style="font-weight: bold;">Webhook Endpoint:</label> ';
        echo '<input type="text" id="snippa-webhook-url" value="' . $webhook_url . '" readonly style="width: 400px; font-family: monospace; margin-left: 0.5em;" onclick="this.select();">';
        echo '</div>';
        echo '</div>';
        // Tag(s) field
        $allowed_tags = esc_attr(get_option('snippa_allowed_tags', ''));
        echo '<form method="post" style="margin-bottom:1em; display:inline-block;">';
        wp_nonce_field('snippa_tags');
        echo '<label for="snippa-allowed-tags" style="font-weight:bold;">Tag(s):</label> ';
        echo '<input type="text" id="snippa-allowed-tags" name="snippa_allowed_tags" value="' . $allowed_tags . '" placeholder="e.g. woocommerce,checkout" style="width:250px; font-family:monospace;"> ';
        submit_button('Save Tags', 'secondary', '', false);
        echo '</form>';
        $snippets = get_option( SNIPPA_CACHE_KEY );
        if ( ! is_array( $snippets ) ) {
            $snippets = snippa_discover_snippets();
        }
        // Filter by allowed tags
        $snippets = snippa_filter_snippets_by_tags($snippets);
        $enabled = get_option('snippa_enabled_snippets', null);
        if ($enabled === null && !empty($snippets)) {
            // First import: enable all by default
            $enabled = array_column($snippets, 'file');
            update_option('snippa_enabled_snippets', $enabled);
        }
        if ( empty( $snippets ) ) {
            echo '<p>No snippets found in <code>wp-content/snippets/</code>.</p>';
        } else {
            echo '<form method="post">';
            wp_nonce_field('snippa_toggle');
            echo '<table class="widefat fixed striped"><thead><tr>';
            echo '<th>Name</th><th>Description</th><th>Version</th><th>Tags</th><th>File</th><th>Enabled</th>';
            echo '</tr></thead><tbody>';
            foreach ( $snippets as $s ) {
                $file = $s['file'] ?? '';
                $is_enabled = in_array($file, $enabled, true);
                echo '<tr>';
                echo '<td>' . esc_html( $s['Snippet'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $s['Description'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $s['Version'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $s['Tags'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $file ) . '</td>';
                echo '<td style="text-align:center;"><input type="checkbox" name="snippa_enabled[]" value="' . esc_attr($file) . '"' . ($is_enabled ? ' checked' : '') . '></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            submit_button('Save Changes');
            echo '</form>';
        }
        echo '</div>';
        return;
    }
    // Setup form (no form processing here)
    echo '<div class="wrap">';
    echo '<h1>Snippa Setup</h1>';
    echo '<p>To get started, enter the URL of your GitHub snippets repository. This will be cloned into <code>wp-content/snippets/</code>.</p>';
    echo '<form method="post">';
    wp_nonce_field( 'snippa_setup' );
    $default_repo = 'https://github.com/ediblesites/wordpress-snippets';
    echo '<table class="form-table"><tr><th scope="row"><label for="snippa_github_url">GitHub Repo URL</label></th>';
    echo '<td><input type="url" name="snippa_github_url" id="snippa_github_url" class="regular-text" required placeholder="' . esc_attr( $default_repo ) . '" value="' . esc_attr( $default_repo ) . '"></td></tr></table>';
    submit_button( 'Clone Repository' );
    echo '</form>';
    echo '</div>';
}

// Helper: Check if git is available
function snippa_git_available() {
    $output = $return_var = '';
    exec( 'git --version 2>&1', $output, $return_var );
    return $return_var === 0;
}

// ===== Snippet Loading =====
// Load enabled snippets on init (conditional by Context)
add_action('init', function() {
    $enabled = get_option('snippa_enabled_snippets', []);
    if (empty($enabled) || !is_array($enabled)) {
        return;
    }
    $snippets = get_option(SNIPPA_CACHE_KEY, []);
    $snippets_by_file = [];
    foreach ($snippets as $s) {
        if (!empty($s['file'])) {
            $snippets_by_file[$s['file']] = $s;
        }
    }
    $is_admin = is_admin();
    foreach ($enabled as $file) {
        $path = SNIPPA_SNIPPETS_DIR . $file;
        if (file_exists($path)) {
            $context = isset($snippets_by_file[$file]['Context']) ? strtolower($snippets_by_file[$file]['Context']) : 'frontend,backend';
            $contexts = array_filter(array_map('trim', explode(',', $context)));
            if (
                (!$is_admin && in_array('frontend', $contexts)) ||
                ($is_admin && in_array('backend', $contexts))
            ) {
                try {
                    require_once $path;
                } catch (Throwable $e) {
                    add_action('admin_notices', function() use ($file, $e) {
                        echo '<div class="notice notice-error"><p><strong>Snippa:</strong> Failed to load snippet <code>' . esc_html($file) . '</code>: ' . esc_html($e->getMessage()) . '</p></div>';
                    });
                }
            }
        }
    }
});

// ===== Git Pull Logic =====
// Handle git pull action
add_action('admin_init', function() {
    if (isset($_GET['snippa_git_pull']) && current_user_can('manage_options')) {
        $output = $return_var = '';
        $changed_files = [];
        if (!is_dir(SNIPPA_SNIPPETS_DIR)) {
            wp_redirect(admin_url('admin.php?page=snippa&snippa_error=Snippets+directory+missing.'));
            exit;
        }
        // Run git pull
        $cmd = 'cd ' . escapeshellarg(SNIPPA_SNIPPETS_DIR) . ' && git pull 2>&1';
        exec($cmd, $output, $return_var);
        if ($return_var !== 0) {
            $error = esc_html(implode("\n", $output));
            wp_redirect(admin_url('admin.php?page=snippa&snippa_error=' . urlencode($error)));
            exit;
        }
        // Get changed files using git diff
        $diff_output = [];
        $diff_cmd = 'cd ' . escapeshellarg(SNIPPA_SNIPPETS_DIR) . ' && git diff --name-only HEAD@{1} HEAD 2>&1';
        exec($diff_cmd, $diff_output, $diff_return);
        foreach ($diff_output as $file) {
            if (substr($file, -4) === '.php') {
                $changed_files[] = basename($file);
            }
        }
        // Do not fallback to all files; only report actual changes
        // Update only changed files in cache
        $snippets = get_option(SNIPPA_CACHE_KEY, []);
        $snippets_by_file = [];
        foreach ($snippets as $s) {
            if (!empty($s['file'])) {
                $snippets_by_file[$s['file']] = $s;
            }
        }
        foreach ($changed_files as $file) {
            $path = SNIPPA_SNIPPETS_DIR . $file;
            if (file_exists($path)) {
                $meta = snippa_parse_docblock($path);
                if (!empty($meta['Snippet'])) {
                    $meta['file'] = $file;
                    $snippets_by_file[$file] = $meta;
                }
            } else {
                // File deleted
                unset($snippets_by_file[$file]);
            }
        }
        // Save updated cache
        $new_snippets = array_values($snippets_by_file);
        $new_snippets = snippa_filter_snippets_by_tags($new_snippets);
        update_option(SNIPPA_CACHE_KEY, $new_snippets);
        // Update enabled/disabled state: preserve, enable new, remove missing
        $enabled = get_option('snippa_enabled_snippets', []);
        $all_files = array_column($new_snippets, 'file');
        $enabled = array_values(array_intersect($enabled, $all_files));
        $new_files = array_diff($all_files, $enabled);
        $enabled = array_merge($enabled, $new_files);
        update_option('snippa_enabled_snippets', $enabled);
        wp_redirect(admin_url('admin.php?page=snippa&snippa_git_pulled=1'));
        exit;
    }
});

// ===== Webhook Endpoint =====
// Register REST API endpoint for GitHub webhook
add_action('rest_api_init', function() {
    register_rest_route('snippa/v1', '/webhook', [
        'methods' => ['POST', 'GET'],
        'callback' => function($request) {
            if ($request->get_method() === 'GET') {
                return new WP_REST_Response(['success' => true, 'message' => 'Snippa webhook endpoint is active.'], 200);
            }
            // Store webhook call timestamp
            update_option('snippa_last_webhook', current_time('mysql'));
            // Run git pull and refresh changed snippets (POST logic)
            $output = $return_var = '';
            $changed_files = [];
            if (!is_dir(SNIPPA_SNIPPETS_DIR)) {
                return new WP_REST_Response(['success' => false, 'error' => 'Snippets directory missing.'], 400);
            }
            $cmd = 'cd ' . escapeshellarg(SNIPPA_SNIPPETS_DIR) . ' && git pull 2>&1';
            exec($cmd, $output, $return_var);
            if ($return_var !== 0) {
                $error = implode("\n", $output);
                return new WP_REST_Response(['success' => false, 'error' => $error], 500);
            }
            // Get changed files using git diff
            $diff_output = [];
            $diff_cmd = 'cd ' . escapeshellarg(SNIPPA_SNIPPETS_DIR) . ' && git diff --name-only HEAD@{1} HEAD 2>&1';
            exec($diff_cmd, $diff_output, $diff_return);
            foreach ($diff_output as $file) {
                if (substr($file, -4) === '.php') {
                    $changed_files[] = basename($file);
                }
            }
            // Do not fallback to all files; only report actual changes
            // Update only changed files in cache
            $snippets = get_option(SNIPPA_CACHE_KEY, []);
            $snippets_by_file = [];
            foreach ($snippets as $s) {
                if (!empty($s['file'])) {
                    $snippets_by_file[$s['file']] = $s;
                }
            }
            foreach ($changed_files as $file) {
                $path = SNIPPA_SNIPPETS_DIR . $file;
                if (file_exists($path)) {
                    $meta = snippa_parse_docblock($path);
                    if (!empty($meta['Snippet'])) {
                        $meta['file'] = $file;
                        $snippets_by_file[$file] = $meta;
                    }
                } else {
                    unset($snippets_by_file[$file]);
                }
            }
            $new_snippets = array_values($snippets_by_file);
            $new_snippets = snippa_filter_snippets_by_tags($new_snippets);
            update_option(SNIPPA_CACHE_KEY, $new_snippets);
            // Update enabled/disabled state: preserve, enable new, remove missing
            $enabled = get_option('snippa_enabled_snippets', []);
            $all_files = array_column($new_snippets, 'file');
            $enabled = array_values(array_intersect($enabled, $all_files));
            $new_files = array_diff($all_files, $enabled);
            $enabled = array_merge($enabled, $new_files);
            update_option('snippa_enabled_snippets', $enabled);
            return new WP_REST_Response(['success' => true, 'changed_files' => $changed_files], 200);
        },
        'permission_callback' => '__return_true', // No auth required
    ]);
}); 
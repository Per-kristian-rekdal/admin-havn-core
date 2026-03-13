<?php
/*
Plugin Name: Admin Havn Core
Description: Core system for Admin Havn (members, slips, dugnad, utleie, tilsyn, historikk).
Version: 3.3.0
Author: Admin Havn
*/

if (!defined('ABSPATH')) exit;

/* =====================================================
   PAGE TEMPLATE (from plugin)
   - Avoids theme containers (e.g. Astra .ast-container) that lock width
   - Lets Admin Havn pages render true full-width without modifying the theme
   ===================================================== */

// Register a selectable page template in the editor (Page Attributes → Template)
add_filter('theme_page_templates', function($post_templates, $wp_theme, $post, $post_type) {
    if ($post_type === 'page') {
        $post_templates['admin-havn-fullwidth.php'] = 'Admin Havn – Full bredde';
    }
    return $post_templates;
}, 10, 4);

// When selected, load the template file from this plugin.
add_filter('template_include', function($template) {
    if (is_singular('page')) {
        $post_id = get_queried_object_id();
        if ($post_id) {
            $slug = get_page_template_slug($post_id);
            if ($slug === 'admin-havn-fullwidth.php') {
                $candidate = plugin_dir_path(__FILE__) . 'templates/admin-havn-fullwidth.php';
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
        }
    }
    return $template;
});

// Minimal CSS to ensure true full-width on the template page without touching theme files.
add_action('wp_head', function() {
    if (!is_singular('page')) return;
    $post_id = get_queried_object_id();
    if (!$post_id) return;
    if (get_page_template_slug($post_id) !== 'admin-havn-fullwidth.php') return;

    echo "\n<style id='admin-havn-fullwidth-css'>\n";
    // Break out of theme containers (Astra etc.) ONLY on this template.
    echo ".ast-container,.entry-content{max-width:100% !important;width:100% !important;}\n";
    echo ".ah-fullwidth-main{max-width:none !important;width:100% !important;margin:0 !important;padding:0 !important;}\n";
    // App container: centered, max 1800px, with a touch more air on the left.
    echo ".ah-fullwidth-inner{max-width:none !important;width:100% !important;margin:0 !important;padding:0 !important;}\n";
    echo ".ah-portal{max-width:1800px;margin:0 auto;box-sizing:border-box;padding:16px 18px 16px 28px;}\n";
    // Common theme wrappers that may clip full-bleed layouts.
    echo ".site-content,.content-area,.site-main{overflow:visible !important;}\n";
    echo "html,body{overflow-x:hidden;}\n";
    echo "</style>\n";
});

// Agreements/Invoice DB layer is implemented in this file to avoid missing-include issues
// when updating the plugin via ZIP. (Older installs may still have includes/ on disk.)
register_activation_hook(__FILE__, 'admin_havn_install_db');

/* ============================
   DB: AGREEMENTS / INVOICES
   - agreement_no == fakturanr == avtalenr
   - One global series, default start at 90000
============================ */

if (!function_exists('admin_havn_install_db')) {
    function admin_havn_install_db() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $t_series = $wpdb->prefix . 'ah_number_series';
        $t_agree  = $wpdb->prefix . 'ah_agreement';
        $t_lines  = $wpdb->prefix . 'ah_agreement_line';

        $sql1 = "CREATE TABLE $t_series (
            series_key VARCHAR(64) NOT NULL,
            next_no BIGINT NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (series_key)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE $t_agree (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agreement_no BIGINT NOT NULL,
            type VARCHAR(64) NOT NULL,
            customer_type VARCHAR(16) NOT NULL,
            member_id BIGINT NULL,
            external_name VARCHAR(190) NULL,
            external_email VARCHAR(190) NULL,
            external_phone VARCHAR(64) NULL,
            external_address VARCHAR(190) NULL,
            source_type VARCHAR(64) NULL,
            source_id BIGINT NULL,
            status VARCHAR(16) NOT NULL,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY agreement_no (agreement_no),
            KEY source (source_type, source_id),
            KEY member (member_id)
        ) $charset_collate;";

        $sql3 = "CREATE TABLE $t_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agreement_id BIGINT UNSIGNED NOT NULL,
            description VARCHAR(255) NOT NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY agreement_id (agreement_id)
        ) $charset_collate;";

        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);

        // Ensure default series exists
        $now = current_time('mysql');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_series WHERE series_key=%s", 'agreement'));
        if ((int)$exists === 0) {
            $wpdb->insert($t_series, [
                'series_key' => 'agreement',
                'next_no' => 90000,
                'updated_at' => $now,
            ], ['%s','%d','%s']);
        }
    }
}

// If plugin is updated without de/activation, ensure tables exist.
add_action('plugins_loaded', function() {
    global $wpdb;
    $t_agree = $wpdb->prefix . 'ah_agreement';
    $ok = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t_agree));
    if (!$ok) {
        admin_havn_install_db();
    }
});

if (!function_exists('admin_havn_next_number')) {
    function admin_havn_next_number($series_key = 'agreement') {
        global $wpdb;
        $t = $wpdb->prefix . 'ah_number_series';
        $now = current_time('mysql');

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $t (series_key, next_no, updated_at) VALUES (%s, %d, %s)",
            $series_key,
            90000,
            $now
        ));

        $cur = (int)$wpdb->get_var($wpdb->prepare("SELECT next_no FROM $t WHERE series_key=%s", $series_key));
        $next = $cur + 1;
        $updated = $wpdb->query($wpdb->prepare("UPDATE $t SET next_no=%d, updated_at=%s WHERE series_key=%s AND next_no=%d", $next, $now, $series_key, $cur));
        if ((int)$updated !== 1) {
            $cur = (int)$wpdb->get_var($wpdb->prepare("SELECT next_no FROM $t WHERE series_key=%s", $series_key));
            $next = $cur + 1;
            $wpdb->query($wpdb->prepare("UPDATE $t SET next_no=%d, updated_at=%s WHERE series_key=%s", $next, $now, $series_key));
        }
        return $cur;
    }
}

if (!function_exists('admin_havn_create_agreement')) {
    function admin_havn_create_agreement($header, $lines) {
        global $wpdb;
        $t_agree = $wpdb->prefix . 'ah_agreement';
        $t_lines = $wpdb->prefix . 'ah_agreement_line';
        $now = current_time('mysql');

        $agreement_no = admin_havn_next_number('agreement');

        $member_id = isset($header['member_id']) ? (int)$header['member_id'] : null;
        $customer_type = (string)($header['customer_type'] ?? 'external');
        $type = (string)($header['type'] ?? 'unknown');
        $status = (string)($header['status'] ?? 'generated');

        $total = 0.0;
        foreach (($lines ?: []) as $l) {
            $total += (float)($l['amount'] ?? 0);
        }

        $ins = [
            'agreement_no' => $agreement_no,
            'type' => $type,
            'customer_type' => $customer_type,
            'member_id' => $member_id ?: null,
            'external_name' => $header['external_name'] ?? null,
            'external_email' => $header['external_email'] ?? null,
            'external_phone' => $header['external_phone'] ?? null,
            'external_address' => $header['external_address'] ?? null,
            'source_type' => $header['source_type'] ?? null,
            'source_id' => isset($header['source_id']) ? (int)$header['source_id'] : null,
            'status' => $status,
            'total' => number_format($total, 2, '.', ''),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $ok = $wpdb->insert($t_agree, $ins);
        if (!$ok) {
            return new WP_Error('db_insert_failed', 'Kunne ikke lagre faktura (DB).');
        }
        $agreement_id = (int)$wpdb->insert_id;

        foreach (($lines ?: []) as $l) {
            $meta = isset($l['meta']) ? wp_json_encode($l['meta']) : null;
            $wpdb->insert($t_lines, [
                'agreement_id' => $agreement_id,
                'description' => (string)($l['description'] ?? ''),
                'qty' => number_format((float)($l['qty'] ?? 1), 2, '.', ''),
                'unit_price' => number_format((float)($l['unit_price'] ?? 0), 2, '.', ''),
                'amount' => number_format((float)($l['amount'] ?? 0), 2, '.', ''),
                'meta' => $meta,
                'created_at' => $now,
            ]);
        }

        return [
            'agreement_no' => $agreement_no,
            'agreement_id' => $agreement_id,
            'total' => number_format($total, 2, '.', ''),
        ];
    }
}

if (!function_exists('admin_havn_list_agreements')) {
    function admin_havn_list_agreements($limit = 200) {
        global $wpdb;
        $t_agree = $wpdb->prefix . 'ah_agreement';
        $limit = max(1, (int)$limit);
        return $wpdb->get_results("SELECT agreement_no, type, customer_type, member_id, external_name, status, total, created_at FROM $t_agree ORDER BY agreement_no DESC LIMIT $limit", ARRAY_A) ?: [];
    }
}

if (!function_exists('admin_havn_get_agreement')) {
    function admin_havn_get_agreement($agreement_no) {
        global $wpdb;
        $t_agree = $wpdb->prefix . 'ah_agreement';
        $t_lines = $wpdb->prefix . 'ah_agreement_line';
        $no = (int)$agreement_no;
        $head = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_agree WHERE agreement_no=%d", $no), ARRAY_A);
        if (!$head) return null;
        $lines = $wpdb->get_results($wpdb->prepare("SELECT description, qty, unit_price, amount, meta FROM $t_lines WHERE agreement_id=%d ORDER BY id ASC", (int)$head['id']), ARRAY_A) ?: [];
        foreach ($lines as &$l) {
            if (!empty($l['meta'])) {
                $m = json_decode((string)$l['meta'], true);
                $l['meta'] = is_array($m) ? $m : null;
            } else {
                $l['meta'] = null;
            }
        }
        $head['lines'] = $lines;
        return $head;
    }
}

if (!function_exists('admin_havn_update_agreement_status')) {
    function admin_havn_update_agreement_status($agreement_no, $status) {
        global $wpdb;
        $t_agree = $wpdb->prefix . 'ah_agreement';
        $no = (int)$agreement_no;
        $status = (string)$status;
        $now = current_time('mysql');
        $wpdb->update($t_agree, ['status' => $status, 'updated_at' => $now], ['agreement_no' => $no], ['%s','%s'], ['%d']);
        return true;
    }
}


/* ============================
   REGISTER POST TYPES
============================ */

function admin_havn_register_post_types() {

    // NOTE: Styreportalen brukes av brukere som ofte kun har "read"-rettigheter.
    // For å unngå at frontend-AJAX feiler ved opprettelse av poster (utleie osv.),
    // mapper vi CPT-capabilities til "read". Portalen krever innlogging.
    $open_caps = [
        'read_post' => 'read',
        'read_private_posts' => 'read',
        'edit_post' => 'read',
        'edit_posts' => 'read',
        'edit_others_posts' => 'read',
        'edit_published_posts' => 'read',
        'edit_private_posts' => 'read',
        'publish_posts' => 'read',
        'delete_post' => 'read',
        'delete_posts' => 'read',
        'delete_others_posts' => 'read',
        'delete_published_posts' => 'read',
        'delete_private_posts' => 'read',
        'create_posts' => 'read',
    ];

    register_post_type('medlem', [
        'label' => 'Medlemmer',
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'supports' => ['title'],
        'menu_icon' => 'dashicons-groups',
        'capabilities' => $open_caps,
    ]);

    register_post_type('batplass', [
        'label' => 'Båtplasser',
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'supports' => ['title'],
        'menu_icon' => 'dashicons-anchor',
        'capabilities' => $open_caps,
    ]);

    register_post_type('dugnadstime', [
        'label' => 'Dugnadstimer',
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'supports' => ['title'],
        'menu_icon' => 'dashicons-clock',
        'capabilities' => $open_caps,
    ]);

    register_post_type('utleie', [
        'label' => 'Utleie',
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'supports' => ['title'],
        'menu_icon' => 'dashicons-admin-home',
        'capabilities' => $open_caps,
    ]);
}
add_action('init', 'admin_havn_register_post_types');

/* ============================
   POST STATUS: ARKIV
============================ */

function admin_havn_register_archived_status() {
    register_post_status('archived', [
        'label'                     => 'Arkiv',
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => false,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Arkiv <span class="count">(%s)</span>', 'Arkiv <span class="count">(%s)</span>'),
    ]);
}
add_action('init', 'admin_havn_register_archived_status');

/* ============================
   ADMIN MENU
============================ */

function admin_havn_admin_menu() {
    add_menu_page(
        'Admin Havn',
        'Admin Havn',
        'manage_options',
        'admin-havn-dashboard',
        function() {
            echo '<div class="wrap"><h1>Admin Havn</h1><p>System aktivert.</p></div>';
        },
        'dashicons-admin-generic',
        3
    );

    add_submenu_page(
        'admin-havn-dashboard',
        'Importer CSV',
        'Importer CSV',
        'manage_options',
        'admin-havn-import',
        'admin_havn_import_page'
    );

    // Faktura/avtaler (egen admin-side – dette er ikke en CPT, men DB-tabeller)
    add_submenu_page(
        'admin-havn-dashboard',
        'Faktura',
        'Faktura',
        'manage_options',
        'admin-havn-faktura',
        function() {
            if (!current_user_can('manage_options')) return;
            echo '<div class="wrap"><h1>Faktura</h1>';
            echo '<p>Dette er faktura-/avtalejournalen (avtalenr = fakturanr).</p>';
            if (!function_exists('admin_havn_list_agreements')) {
                echo '<div class="notice notice-error"><p>Fakturamodul er ikke tilgjengelig.</p></div></div>';
                return;
            }
            $rows = admin_havn_list_agreements(500);
            echo '<table class="widefat fixed striped"><thead><tr>';
            echo '<th>Fakturanr</th><th>Type</th><th>Kunde</th><th>Status</th><th>Sum</th><th>Dato</th>';
            echo '</tr></thead><tbody>';
            if (!$rows) {
                echo '<tr><td colspan="6">Ingen fakturaer ennå.</td></tr>';
            } else {
                foreach ($rows as $r) {
                    $kunde = ($r['customer_type']==='member' && !empty($r['member_id'])) ? ('Medlem #' . (int)$r['member_id']) : (esc_html($r['external_name'] ?: 'Ekstern'));
                    echo '<tr>';
                    echo '<td>' . esc_html($r['agreement_no']) . '</td>';
                    echo '<td>' . esc_html($r['type']) . '</td>';
                    echo '<td>' . $kunde . '</td>';
                    echo '<td>' . esc_html($r['status']) . '</td>';
                    echo '<td>NOK ' . esc_html($r['total']) . '</td>';
                    echo '<td>' . esc_html(substr((string)$r['created_at'], 0, 10)) . '</td>';
                    echo '</tr>';
                }
            }
            echo '</tbody></table></div>';
        }
    );

    add_submenu_page(
        'admin-havn-dashboard',
        'Konfigurasjon',
        'Konfigurasjon',
        'manage_options',
        'admin-havn-config',
        'admin_havn_config_page'
    );

    add_submenu_page(
        'admin-havn-dashboard',
        'Arkiv medlemmer',
        'Arkiv medlemmer',
        'manage_options',
        'edit.php?post_type=medlem&post_status=archived'
    );

    add_submenu_page(
        'admin-havn-dashboard',
        'Arkiv båtplasser',
        'Arkiv båtplasser',
        'manage_options',
        'edit.php?post_type=batplass&post_status=archived'
    );
    add_submenu_page(
        'admin-havn-dashboard',
        'Kalibrering av havnekart',
       'Havnekart',
       'manage_options',
       'admin-havn-havnekart',
       'tes_havnekart_admin_page'
    );

}
add_action('admin_menu', 'admin_havn_admin_menu');

/* ============================
   STYREPORTAL (FRONTEND)
   Pages:
     https://tes.as/styre/medlemmer/
     https://tes.as/styre/batplasser/
   Use shortcodes on the pages:
     [admin_havn_portal view="medlemmer"]
     [admin_havn_portal view="batplasser"]
============================ */


/* ============================
   STYREPORTAL THEME ENHANCEMENTS (FRONTEND)
============================ */

function admin_havn_is_styre_page() {
    if (!is_page()) return false;
    $url = (string) get_permalink();
    if (strpos($url, '/styre/') !== false) return true;
    // fallback: if page has a parent with slug 'styre'
    $post = get_post();
    if (!$post) return false;
    $p = $post;
    while ($p && $p->post_parent) {
        $p = get_post($p->post_parent);
        if ($p && $p->post_name === 'styre') return true;
    }
    return ($post && $post->post_name === 'styre');
}

add_action('wp_enqueue_scripts', function() {
    if (!admin_havn_is_styre_page()) return;

    wp_register_style('admin-havn-portal-theme', false, [], '2.9.5');
    wp_enqueue_style('admin-havn-portal-theme');

    $css = <<<CSS
/* Maritim, rolig portal-stil på /styre/ */
body{background:#f3f6f9;}
/* Gi innhold litt "kort"-følelse */
.site-content, .content-area{padding-bottom:24px;}
/* Skjul standard nettstedstittel for portal-sider (viser vår egen toppstripe i portalen) */
.site-title, .site-branding .site-title{display:none !important;}
/* Gjør toppmeny mer som tydelige knapper */
.main-navigation a, nav a{
  display:inline-block;
  padding:8px 14px;
  margin:0 4px;
  border-radius:999px;
  border:1px solid #c7d8ee;
  background:#eef4fb;
  color:#1f3a5f !important;
  text-decoration:none !important;
  font-weight:600;
}
.main-navigation a:hover, nav a:hover{
  background:#e3eefb;
}
.main-navigation .current-menu-item > a,
.main-navigation .current_page_item > a{
  background:#1f3a5f;
  border-color:#1f3a5f;
  color:#fff !important;
}
/* Litt mer luft over portal */
.entry-content{margin-top:10px;}
CSS;

    wp_add_inline_style('admin-havn-portal-theme', $css);
});

function admin_havn_portal_allowed() {
    return is_user_logged_in() && current_user_can('read');
}

function admin_havn_portal_badge_class($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'til leie') $s = 'til leige';
    switch ($s) {
        case 'opptatt': return 'ah-badge ah-badge--opptatt';
        case 'sperret': return 'ah-badge ah-badge--sperret';
        case 'til salgs': return 'ah-badge ah-badge--til-salgs';
        case 'utleid': return 'ah-badge ah-badge--utleid';
        case 'ledig for utleie': return 'ah-badge ah-badge--klar';
        default: return 'ah-badge';
    }
}

function admin_havn_portal_shortcode($atts) {
    $atts = shortcode_atts(['view' => 'medlemmer'], $atts);
    $view = strtolower(trim((string)$atts['view']));

    if (!admin_havn_portal_allowed()) {
        return '<div class="ah-portal">Du må være innlogget for å se denne siden.</div>';
    }

    // Bump when changing assets to avoid browser caching old JS/CSS
    $ver = '3.2.2';
    $base_url = plugin_dir_url(__FILE__);
    wp_enqueue_style('admin-havn-portal', $base_url . 'assets/portal.css', [], $ver);
    wp_enqueue_script('admin-havn-portal', $base_url . 'assets/portal.js', [], $ver, true);

        $mode = '';
        if ($view === 'utleie') {
            $mode = isset($_GET['mode']) ? sanitize_key((string)$_GET['mode']) : 'list';
            if (!in_array($mode, ['list','timeline'], true)) $mode = 'list';
        }

        // Optional prefill (used when jumping from timeline -> list for quick registration)
        $prefill = null;
        if ($view === 'utleie') {
            $sel = isset($_GET['select']) ? absint($_GET['select']) : 0;
            $from = isset($_GET['from']) ? sanitize_text_field((string)$_GET['from']) : '';
            $to   = isset($_GET['to']) ? sanitize_text_field((string)$_GET['to']) : '';
            if ($sel && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $prefill = [ 'select' => $sel, 'from' => $from, 'to' => $to ];
            }
        }

        $cfg = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('admin_havn_portal'),
            'view' => $view,
            'mode' => $mode,
            'prefill' => $prefill,
        ];

        wp_add_inline_script('admin-havn-portal', 'window.AdminHavnPortal = ' . wp_json_encode($cfg) . ';', 'before');

    $title = ($view === 'batplasser') ? 'Båtplasser'
        : (($view === 'utleie') ? 'Utleie'
        : (($view === 'faktura') ? 'Faktura'
        : (($view === 'dugnad') ? 'Dugnad' : 'Medlemmer')));

    $leftTitle = ($view === 'batplasser') ? 'Båtplass'
        : (($view === 'utleie') ? 'Båtplass'
        : (($view === 'faktura') ? 'Avtale'
        : 'Medlem'));

    // Server-side initial rows (helps if JS is blocked/cached and makes debugging easier)
    $initialRowsHtml = '';
    try {
        if ($view === 'faktura') {
            $agreements = admin_havn_list_agreements(200);
            foreach ($agreements as $a) {
                $no = (int)($a['agreement_no'] ?? 0);
                $st = (string)($a['status'] ?? '');
                $badge = admin_havn_portal_badge_class($st);
                $initialRowsHtml .= '<tr data-id="' . esc_attr($no) . '">' .
                    '<td>Avtale ' . esc_html($no) . '</td>' .
                    '<td><span class="ah-badge ' . esc_attr($badge) . '">' . esc_html($st ?: '—') . '</span></td>' .
                    '</tr>';
            }
        } else {
            // Minimal inlined list implementation (keep consistent with AJAX list output)
            if ($view === 'batplasser' || $view === 'utleie') {
                $posts = get_posts([
                    'post_type' => 'batplass',
                    'post_status' => ['publish','private','draft','pending','archived'],
                    'posts_per_page' => 200,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'no_found_rows' => true,
                ]);
                foreach ($posts as $p) {
                    $kode = get_post_meta($p->ID, 'admin_havn_batplasskode_bp', true);
                    $pir = get_post_meta($p->ID, 'admin_havn_pir', true);
                    $plassnr = get_post_meta($p->ID, 'admin_havn_plassnr', true);
                    $bat_status = admin_havn_portal_status_for_batplass_id($p->ID);

                    if ($view === 'utleie') {
                        $utleid = false;
                        $uq = new WP_Query([
                            'post_type' => 'utleie',
                            'post_status' => ['publish','private','draft','pending','archived'],
                            'posts_per_page' => 1,
                            'meta_query' => [[
                                'key' => 'admin_havn_utleie_batplass_id',
                                'value' => (string)$p->ID,
                                'compare' => '=',
                            ]],
                            'no_found_rows' => true,
                        ]);
                        if (!empty($uq->posts)) {
                            foreach ($uq->posts as $up) {
                                $to = get_post_meta($up->ID, 'admin_havn_utleie_til', true);
                                if (!$to || strtotime($to) >= time()) { $utleid = true; break; }
                            }
                        }
                        $badge = admin_havn_portal_badge_class($utleid ? 'utleid' : 'ledig for utleie');
                        $initialRowsHtml .= '<tr data-id="' . esc_attr($p->ID) . '">' .
                            '<td>' . esc_html($kode ?: $p->post_title) . '<br><small>Pir ' . esc_html($pir) . ' • Plass ' . esc_html($plassnr) . '</small></td>' .
                            '<td><span class="ah-badge ' . esc_attr($badge) . '">' . esc_html($utleid ? ('Utleid til ' . $utleid_til) : ($neste_res_fra ? ('Reservert fra ' . $neste_res_fra) : 'Ledig')) . '</span></td>' .
                            '</tr>';
                    } else {
                        $badge = admin_havn_portal_badge_class($bat_status ?: '');
                        $initialRowsHtml .= '<tr data-id="' . esc_attr($p->ID) . '">' .
                            '<td>' . esc_html($kode ?: $p->post_title) . '<br><small>Pir ' . esc_html($pir) . ' • Plass ' . esc_html($plassnr) . '</small></td>' .
                            '<td><span class="ah-badge ' . esc_attr($badge) . '">' . esc_html($bat_status ?: '—') . '</span></td>' .
                            '</tr>';
                    }
                }
            } else {
                $posts = get_posts([
                    'post_type' => 'medlem',
                    'post_status' => ['publish','private','draft','pending','archived'],
                    'posts_per_page' => 200,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'no_found_rows' => true,
                ]);
                foreach ($posts as $p) {
                    $fornavn = get_post_meta($p->ID, 'admin_havn_fornavn', true);
                    $etternavn = get_post_meta($p->ID, 'admin_havn_etternavn', true);
                    $fullt = trim($fornavn . ' ' . $etternavn);
                    if ($fullt === '') $fullt = $p->post_title;
                    $medlemsnr = get_post_meta($p->ID, 'admin_havn_medlemsnr', true);
                    $batplasskode = get_post_meta($p->ID, 'admin_havn_batplasskode', true);
                    $bp_id = admin_havn_portal_find_batplass_by_code($batplasskode);
                    $status = $bp_id ? admin_havn_portal_status_for_batplass_id($bp_id) : '';
                    $badge = admin_havn_portal_badge_class($status ?: '');
                    $initialRowsHtml .= '<tr data-id="' . esc_attr($p->ID) . '">' .
                        '<td>' . esc_html($fullt) . '<br><small>' . esc_html($medlemsnr) . ($batplasskode ? (' • ' . esc_html($batplasskode)) : '') . '</small></td>' .
                        '<td><span class="ah-badge ' . esc_attr($badge) . '">' . esc_html($status ?: '—') . '</span></td>' .
                        '</tr>';
                }
            }
        }
    } catch (Throwable $e) {
        $initialRowsHtml = '';
    }

    $btn = ($view === 'utleie') ? 'Generer faktura'
        : (($view === 'faktura') ? 'Oppdater status'
        : (($view === 'dugnad') ? 'Registrer timer' : 'Lagre'));
    $home = 'https://tes.as/styre/';
    return '<div id="ah-portal-root" class="ah-portal" data-view="' . esc_attr($view) . '" data-mode="' . esc_attr($mode) . '">' 
        . '<div class="ah-brand"><div class="ah-brand__left"><span class="ah-mark">AH</span><span class="ah-brand__title">Administrasjon havna</span><span class="ah-brand__sub">Admin Havn</span></div><div class="ah-brand__right"><a class="ah-home" href="' . esc_url($home) . '">Hjem</a><span class="ah-brand__hint">Klikk en rad for å redigere</span></div></div>'
        . '<div class="ah-shell">'
        .   '<div class="ah-left">'
        .     '<div class="ah-head"><div class="ah-headrow"><h2>' . esc_html($title) . '</h2>' . (($view==='utleie') ? ('<div class="ah-tabs">'
            .'<a class="ah-tab" href="' . esc_url(add_query_arg('mode','list')) . '">Liste</a>'
            .'<a class="ah-tab" href="' . esc_url(add_query_arg('mode','timeline')) . '">Tidslinje</a>'
            .'</div>') : '') . '</div><div class="ah-search"><input id="ah-q" type="search" placeholder="Søk..." /></div></div>'
        .     '<div class="ah-tablewrap"><table class="ah-list"><thead><tr><th>' . esc_html($leftTitle) . '</th><th>Status</th></tr></thead><tbody id="ah-list-body">' . $initialRowsHtml . '</tbody></table></div>'
        .   '</div>'
        .   '<div class="ah-right">'
        .     '<div class="ah-head"><div><h2 id="ah-title" style="margin:0">Velg</h2><div id="ah-meta" class="ah-meta"></div></div><div class="ah-actions"><button id="ah-save" disabled>' . esc_html($btn) . '</button></div></div>'
        .     '<div class="ah-panel" id="ah-panel-body">Velg en rad til venstre.</div>'
        .   '</div>'
        . '</div>'
        . '</div>';
}
add_shortcode('admin_havn_portal', 'admin_havn_portal_shortcode');

/* ============================
   DASHBOARD SHORTCODE
   Use: [admin_havn_dashboard]
============================ */

function admin_havn_dashboard_shortcode() {
    if (!admin_havn_portal_allowed()) {
        return '<div class="ah-notice">Du må være innlogget for å bruke styreportalen.</div>';
    }

    global $wpdb;
    $base = site_url('/styre/');

    // Quick counts for the dashboard
    $members = (int)$wpdb->get_var("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='medlem' AND post_status NOT IN ('trash','auto-draft')");
    $slips   = (int)$wpdb->get_var("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='batplass' AND post_status NOT IN ('trash','auto-draft')");
    $for_sale = (int)$wpdb->get_var(
        "SELECT COUNT(1)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type='medlem'
           AND p.post_status NOT IN ('trash','auto-draft')
           AND pm.meta_key='admin_havn_onskes_solgt'
           AND LOWER(pm.meta_value)='ja'"
    );
    $cards = [
        ['title' => 'Medlemmer', 'desc' => 'Søk, status og redigering', 'url' => site_url('/styre/medlemmer/'), 'icon' => '👤'],
        ['title' => 'Båtplasser', 'desc' => 'Status og kan leies ut', 'url' => site_url('/styre/batplasser/'), 'icon' => '⚓'],
        ['title' => 'Utleie', 'desc' => 'Registrer og arkiver utleie', 'url' => site_url('/styre/utleie/'), 'icon' => '⛵'],
        ['title' => 'Havnekart', 'desc' => 'Vis havna som kart', 'url' => site_url('/styre/havnekart/'), 'icon' => '🗺️'],
        ['title' => 'Dugnad', 'desc' => 'Oversikt og timer', 'url' => site_url('/styre/dugnad/'), 'icon' => '🛠️'],
    ];

    ob_start(); ?>
    <div class="ah-dash">
      <div class="ah-dash__hero">
        <div class="ah-dash__brand">Administrasjon havna</div>
        <div class="ah-dash__sub">Administrasjon havna</div>
      </div>
      <div class="ah-dash__stats" role="group" aria-label="Nøkkeltall">
        <div class="ah-dash__stat"><div class="ah-dash__statn"><?php echo (int)$members; ?></div><div class="ah-dash__statl">Medlemmer</div></div>
        <div class="ah-dash__stat"><div class="ah-dash__statn"><?php echo (int)$slips; ?></div><div class="ah-dash__statl">Båtplasser</div></div>
        <div class="ah-dash__stat"><div class="ah-dash__statn"><?php echo (int)$for_sale; ?></div><div class="ah-dash__statl">Til salgs</div></div>
      </div>
      <div class="ah-dash__grid">
        <?php foreach ($cards as $c): ?>
          <a class="ah-dash__card" href="<?php echo esc_url($c['url']); ?>">
            <div class="ah-dash__icon"><?php echo esc_html($c['icon']); ?></div>
            <div class="ah-dash__title"><?php echo esc_html($c['title']); ?></div>
            <div class="ah-dash__desc"><?php echo esc_html($c['desc']); ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    $html = ob_get_clean();

    // Ensure portal theme css is loaded (already on /styre/, but harmless elsewhere)
    wp_register_style('admin-havn-dashboard', false);
    wp_enqueue_style('admin-havn-dashboard');

    $css = <<<CSS
.ah-dash{max-width:1200px;margin:0 auto;padding:0 6px;}
.ah-dash__hero{
  background:linear-gradient(90deg,#1f3a5f,#2e6d7a);
  color:#fff;border-radius:14px;padding:18px 18px 22px;
  box-shadow:0 8px 22px rgba(16,24,40,.08);
  position:relative; overflow:hidden;
}
.ah-dash__hero:after{
  content:"";position:absolute;left:-10%;right:-10%;bottom:-40px;height:110px;
  background:rgba(255,255,255,.10);
  border-radius:50%;
  transform:skewX(-8deg);
}
.ah-dash__brand{font-size:22px;font-weight:800;letter-spacing:.2px;position:relative;z-index:1}
.ah-dash__sub{font-size:14px;opacity:.9;margin-top:2px;position:relative;z-index:1}
.ah-dash__stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-top:12px}
.ah-dash__stat{background:#fff;border:1px solid #d9e1ea;border-radius:14px;padding:12px 14px;box-shadow:0 6px 18px rgba(16,24,40,.05)}
.ah-dash__statn{font-size:22px;font-weight:900;color:#1f3a5f;line-height:1}
.ah-dash__statl{font-size:12px;color:#425466;margin-top:4px;text-transform:uppercase;letter-spacing:.4px}
.ah-dash__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:16px}
.ah-dash__card{
  display:block;background:#fff;border:1px solid #d9e1ea;border-radius:14px;
  padding:16px 16px 14px;text-decoration:none !important;color:#1f3a5f;
  box-shadow:0 6px 18px rgba(16,24,40,.05);
}
.ah-dash__card:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(16,24,40,.08)}
.ah-dash__icon{font-size:22px;margin-bottom:8px}
.ah-dash__title{font-size:16px;font-weight:800;margin-bottom:4px}
.ah-dash__desc{font-size:13px;color:#425466}
CSS;

    wp_add_inline_style('admin-havn-dashboard', $css);

    return $html;
}
add_shortcode('admin_havn_dashboard', 'admin_havn_dashboard_shortcode');

function admin_havn_havnekart_shortcode() {
    $base = plugin_dir_url(__FILE__) . 'assets/havnekart-base.png';
    ob_start(); ?>
    <div class="ah-mapwrap">
      <div class="ah-maphero">Havnekart</div>
      <div class="ah-map">
        <img class="ah-map__bg" src="<?php echo esc_url($base); ?>" alt="Havnekart Tennfjord Småbåthavn" />
        <svg class="ah-map__overlay" viewBox="0 0 1536 1024" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">
          <rect x="661" y="540" width="221" height="102" rx="10" fill="#7fbf7f" opacity="0.92"></rect>
          <g transform="translate(690 548)">
            <rect x="0" y="0" width="96" height="74" rx="8" fill="#f4f4f2" stroke="#9aa1a8" stroke-width="3"></rect>
            <line x1="48" y1="6" x2="48" y2="68" stroke="#b9c0c6" stroke-width="3" opacity="0.75"></line>
          </g>
          <g transform="translate(845 500)">
            <rect x="0" y="0" width="86" height="156" rx="8" fill="#be3b33" stroke="#7a201c" stroke-width="4"></rect>
            <line x1="43" y1="8" x2="43" y2="148" stroke="#8f2722" stroke-width="4" opacity="0.85"></line>
          </g>
          <g opacity="0.95">
            <text x="698" y="642" class="ah-map__label">Havnestua</text>
            <text x="834" y="674" class="ah-map__label">Hall</text>
          </g>
        </svg>
      </div>
    </div>
    <style>
      .ah-mapwrap{max-width:1800px;margin:0 auto;padding:8px 0 18px;}
      .ah-maphero{position:sticky;top:0;z-index:20;background:#1f3a5f;color:#fff;border-radius:14px;padding:12px 16px;margin:0 0 14px;font-weight:700;box-shadow:0 8px 22px rgba(16,24,40,.08);}
      body.admin-bar .ah-maphero{top:32px;}
      @media (max-width:782px){body.admin-bar .ah-maphero{top:46px;}}
      .ah-map{position:relative;width:100%;max-width:1800px;margin:0 auto;background:#e8eef2;border:1px solid #d8e0e8;border-radius:18px;overflow:hidden;box-shadow:0 10px 28px rgba(16,24,40,.08);}
      .ah-map__bg{display:block;width:100%;height:auto;}
      .ah-map__overlay{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;}
      .ah-map__label{fill:#ffffff;font-size:24px;font-weight:700;paint-order:stroke;stroke:rgba(0,0,0,.35);stroke-width:4px;stroke-linejoin:round;}
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('admin_havn_havnekart', 'admin_havn_havnekart_shortcode');



function admin_havn_portal_ajax_guard() {
    if (!admin_havn_portal_allowed()) {
        wp_send_json_error(['message' => 'Ikke tilgang.'], 403);
    }
    // Prefer nonce, but avoid a "blank list" UX if a front-end cache/minifier strips
    // or stales it. Writes remain protected.
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'admin_havn_portal')) {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        if (strpos($action, 'update_') !== false || strpos($action, 'save_') !== false || strpos($action, 'archive_') !== false || strpos($action, 'send_') !== false) {
            wp_send_json_error(['message' => 'Ugyldig token. Last siden på nytt og prøv igjen.'], 403);
        }
        // Read-only actions (list/get) may proceed for logged-in users with read access.
    }
}

function admin_havn_portal_find_batplass_by_code($kode) {
    $kode = trim((string)$kode);
    if ($kode === '') return 0;
    $q = new WP_Query([
        'post_type' => 'batplass',
        'post_status' => ['publish','archived','draft','pending','private'],
        'posts_per_page' => 1,
        'meta_query' => [[
            'key' => 'admin_havn_batplasskode_bp',
            'value' => $kode,
            'compare' => '=',
        ]],
        'no_found_rows' => true,
    ]);
    if (!empty($q->posts)) return (int)$q->posts[0]->ID;
    $q2 = new WP_Query([
        'post_type' => 'batplass',
        'post_status' => ['publish','archived','draft','pending','private'],
        'posts_per_page' => 1,
        'meta_query' => [[
            'key' => 'admin_havn_batplasskode',
            'value' => $kode,
            'compare' => '=',
        ]],
        'no_found_rows' => true,
    ]);
    if (!empty($q2->posts)) return (int)$q2->posts[0]->ID;
    return 0;
}

function admin_havn_portal_status_for_batplass_id($batplass_id) {
    if (!$batplass_id) return '';
    $sperret = strtolower(trim((string)get_post_meta($batplass_id, 'admin_havn_sperret', true)));
    if ($sperret === 'ja') return 'Sperret';
    $status = get_post_meta($batplass_id, 'admin_havn_status', true);
    if (!$status) $status = get_post_meta($batplass_id, 'admin_havn_status_bp', true);
    if (!$status) $status = get_post_meta($batplass_id, 'admin_havn_status_batplass', true);
    if (strtolower(trim((string)$status)) === 'til leie') $status = 'Til leige';
    return $status ?: '';
}

// --- Medlem-autocomplete/lookup (brukes på Utleie) ---
add_action('wp_ajax_admin_havn_portal_member_search', function() {
    admin_havn_portal_ajax_guard();
    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    if (mb_strlen($q) < 2) {
        wp_send_json_success(['results' => []]);
    }

    $posts = get_posts([
        'post_type'      => 'medlem',
        'post_status'    => ['publish','private','draft','pending'],
        'posts_per_page' => 10,
        's'              => $q,
        'no_found_rows'  => true,
    ]);
    $out = [];
    foreach ($posts as $p) {
        $fornavn  = get_post_meta($p->ID, 'admin_havn_fornavn', true);
        $etternavn= get_post_meta($p->ID, 'admin_havn_etternavn', true);
        $mednr    = get_post_meta($p->ID, 'admin_havn_medlemsnr', true);
        $full = trim($fornavn . ' ' . $etternavn);
        if (!$full) $full = $p->post_title;
        $label = $full;
        if ($mednr !== '') $label .= ' (Medlemsnr ' . $mednr . ')';
        $out[] = ['id' => (int)$p->ID, 'label' => $label];
    }
    wp_send_json_success(['results' => $out]);
});

add_action('wp_ajax_admin_havn_portal_member_lookup', function() {
    admin_havn_portal_ajax_guard();
    $id = isset($_POST['member_id']) ? intval($_POST['member_id']) : 0;
    if (!$id) {
        wp_send_json_success(['member' => null]);
    }
    $p = get_post($id);
    if (!$p || $p->post_type !== 'medlem') {
        wp_send_json_success(['member' => null]);
    }

    $fornavn  = get_post_meta($id, 'admin_havn_fornavn', true);
    $etternavn= get_post_meta($id, 'admin_havn_etternavn', true);
    $full = trim($fornavn . ' ' . $etternavn);
    if (!$full) $full = $p->post_title;

    $member = [
        'id'         => (int)$id,
        'fullt_navn' => $full,
        'epost'      => (string)get_post_meta($id, 'admin_havn_epost', true),
        'telefon'    => (string)get_post_meta($id, 'admin_havn_telefon', true),
        'adresse'    => trim((string)get_post_meta($id, 'admin_havn_adresse', true) . ' ' . (string)get_post_meta($id, 'admin_havn_postnr', true) . ' ' . (string)get_post_meta($id, 'admin_havn_poststed', true)),
        'history'    => [],
    ];

    $utl = get_posts([
        'post_type'      => 'utleie',
        'post_status'    => ['publish','private','draft','pending'],
        'posts_per_page' => 5,
        'meta_key'       => 'admin_havn_leietaker_member_id',
        'meta_value'     => (string)$id,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]);
    foreach ($utl as $u) {
        $member['history'][] = [
            'fra'         => (string)get_post_meta($u->ID, 'admin_havn_utleie_fra', true),
            'til'         => (string)get_post_meta($u->ID, 'admin_havn_utleie_til', true),
            'batplasskode'=> (string)(get_post_meta($u->ID, 'admin_havn_batplasskode', true) ?: get_post_meta($u->ID, 'admin_havn_batplasskode_bp', true)),
        ];
    }

    wp_send_json_success(['member' => $member]);
});

// --- Leietaker-autocomplete (medlemmer + tidligere utleie) ---
add_action('wp_ajax_admin_havn_portal_leietaker_suggest', function() {
    admin_havn_portal_ajax_guard();
    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        wp_send_json_success(['results' => []]);
    }

    $out = [];
    $seen = [];

    // 1) Members
    $members = get_posts([
        'post_type'      => 'medlem',
        'post_status'    => ['publish','private','draft','pending'],
        'posts_per_page' => 10,
        's'              => $q,
        'no_found_rows'  => true,
    ]);
    foreach ($members as $p) {
        $fornavn   = get_post_meta($p->ID, 'admin_havn_fornavn', true);
        $etternavn = get_post_meta($p->ID, 'admin_havn_etternavn', true);
        $mednr     = get_post_meta($p->ID, 'admin_havn_medlemsnr', true);
        $full = trim($fornavn . ' ' . $etternavn);
        if (!$full) $full = $p->post_title;
        $label = $full;
        if ($mednr !== '') $label .= ' (Medlemsnr ' . $mednr . ')';

        $key = 'm:' . (int)$p->ID;
        $seen[$key] = true;

        $out[] = [
            'kind'       => 'member',
            'member_id'  => (int)$p->ID,
            'label'      => $label,
            'fullt_navn' => $full,
            'telefon'    => (string)get_post_meta($p->ID, 'admin_havn_telefon', true),
            'epost'      => (string)get_post_meta($p->ID, 'admin_havn_epost', true),
            'adresse'    => trim((string)get_post_meta($p->ID, 'admin_havn_adresse', true) . ' ' . (string)get_post_meta($p->ID, 'admin_havn_postnr', true) . ' ' . (string)get_post_meta($p->ID, 'admin_havn_poststed', true)),
        ];
    }

    // 2) Previous utleie by name (for suggestions)
    $utl = get_posts([
        'post_type'      => 'utleie',
        'post_status'    => ['publish','private','draft','pending'],
        'posts_per_page' => 10,
        'meta_query'     => [[
            'key'     => 'admin_havn_leietaker_navn',
            'value'   => $q,
            'compare' => 'LIKE',
        ]],
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]);

    foreach ($utl as $u) {
        $name = trim((string)get_post_meta($u->ID, 'admin_havn_leietaker_navn', true));
        if ($name === '') continue;

        // Don't duplicate member entries; also dedupe by case-insensitive name
        $key = 'e:' . mb_strtolower($name);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $out[] = [
            'kind'       => 'external',
            'member_id'  => 0,
            'label'      => $name . ' (Tidligere leietaker)',
            'fullt_navn' => $name,
            'telefon'    => (string)get_post_meta($u->ID, 'admin_havn_leietaker_telefon', true),
            'epost'      => (string)get_post_meta($u->ID, 'admin_havn_leietaker_epost', true),
            'adresse'    => (string)get_post_meta($u->ID, 'admin_havn_leietaker_adresse', true),
        ];
    }

    wp_send_json_success(['results' => $out]);
});

add_action('wp_ajax_admin_havn_portal_list', function() {
    admin_havn_portal_ajax_guard();
    $view = strtolower(trim((string)($_POST['view'] ?? 'medlemmer')));
    $q = trim((string)($_POST['q'] ?? ''));

    $for_dugnad = ($view === 'dugnad');

    if ($view === 'faktura') {
        if (!function_exists('admin_havn_list_agreements')) {
            wp_send_json_success(['rows' => []]);
        }
        $rows = [];
        $items = admin_havn_list_agreements(300);
        foreach ($items as $it) {
            $no = (int)($it['agreement_no'] ?? 0);
            $type = (string)($it['type'] ?? '');
            $status = (string)($it['status'] ?? '');
            $total = (string)($it['total'] ?? '0');
            $created = (string)($it['created_at'] ?? '');
            $customer = '';
            if (($it['customer_type'] ?? '') === 'member' && !empty($it['member_id'])) {
                $m = get_post((int)$it['member_id']);
                $customer = $m ? $m->post_title : ('Medlem #' . (int)$it['member_id']);
            } else {
                $customer = (string)($it['external_name'] ?? 'Ekstern');
            }
            $hay = strtolower($no . ' ' . $type . ' ' . $status . ' ' . $customer);
            if ($q !== '' && strpos($hay, strtolower($q)) === false) continue;
            $rows[] = [
                'id' => $no,
                'agreement_no' => $no,
                'type' => $type,
                'customer' => $customer,
                'status' => $status,
                'total' => $total,
                'created_at' => $created,
            ];
        }
        wp_send_json_success(['rows' => $rows]);
    }

    if ($view === 'batplasser') {
        $posts = get_posts([
            'post_type' => 'batplass',
            // Include all relevant statuses since CPT is not public and may use private/draft.
            'post_status' => ['publish','private','draft','pending','archived'],
            'posts_per_page' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);
        $rows = [];
        foreach ($posts as $p) {
            $kode = get_post_meta($p->ID, 'admin_havn_batplasskode_bp', true);
            if (!$kode) $kode = get_post_meta($p->ID, 'admin_havn_batplasskode', true);
            $pir = get_post_meta($p->ID, 'admin_havn_pir', true);
            $plassnr = get_post_meta($p->ID, 'admin_havn_plassnr', true);
            $status = admin_havn_portal_status_for_batplass_id($p->ID);
            if ($q !== '') {
                $hay = strtolower($kode . ' ' . $pir . ' ' . $plassnr . ' ' . $status);
                if (strpos($hay, strtolower($q)) === false) continue;
            }
            $rows[] = [
                'id' => $p->ID,
                'batplasskode' => $kode ?: $p->post_title,
                'pir' => $pir,
                'plassnr' => $plassnr,
                'status' => $status ?: '—',
                'status_badge_class' => admin_havn_portal_badge_class($status ?: ''),
                'kan_leies_ut' => (get_post_meta($p->ID,'admin_havn_kan_leies_ut',true) ?: 'Nei'),
            ];
        }
        wp_send_json_success(['rows' => $rows]);
    }

    if ($view === 'utleie') {
        $posts = get_posts([
            'post_type' => 'batplass',
            'post_status' => ['publish','private','draft','pending','archived'],
            'posts_per_page' => 500,
            // Sorter for gruppering i UI
            'meta_key' => 'admin_havn_bredde_m',
            'orderby' => ['meta_value_num' => 'ASC', 'title' => 'ASC'],
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);
        $rows = [];
        foreach ($posts as $p) {
            $kan_leies_ut = (get_post_meta($p->ID,'admin_havn_kan_leies_ut',true) ?: 'Nei');
            $bat_status = admin_havn_portal_status_for_batplass_id($p->ID);
            $is_for_sale = (strtolower(trim((string)$bat_status)) === 'til salgs');
            if (strcasecmp((string)$kan_leies_ut, 'Ja') !== 0 && !$is_for_sale) {
                continue;
            }
            $kode = get_post_meta($p->ID, 'admin_havn_batplasskode_bp', true);
            if (!$kode) $kode = get_post_meta($p->ID, 'admin_havn_batplasskode', true);
            $pir = get_post_meta($p->ID, 'admin_havn_pir', true);
            $plassnr = get_post_meta($p->ID, 'admin_havn_plassnr', true);

            $today = (string)current_time('Y-m-d');
            // Find active or upcoming rental to show availability.
            $uq = new WP_Query([
                'post_type' => 'utleie',
                'post_status' => ['publish'],
                'posts_per_page' => 5,
                'orderby' => 'meta_value',
                'order' => 'ASC',
                'meta_key' => 'admin_havn_utleie_fra',
                'meta_query' => [
                    [
                        'key' => 'admin_havn_utleie_batplass_id',
                        'value' => (string)$p->ID,
                        'compare' => '=',
                    ],
                ],
                'no_found_rows' => true,
            ]);

            $utleid = false;
            $utleid_til = '';
            $utleid_fra = '';
            $neste_res_fra = '';
            if (!empty($uq->posts)) {
                foreach ($uq->posts as $up) {
                    $fra = (string)get_post_meta($up->ID, 'admin_havn_utleie_fra', true);
                    $til = (string)get_post_meta($up->ID, 'admin_havn_utleie_til', true);
                    if ($fra && $til && $fra <= $today && $til >= $today) {
                        $utleid = true;
                        $utleid_fra = $fra;
                        $utleid_til = $til;
                        break;
                    }
                    if ($fra && $fra > $today) {
                        if ($neste_res_fra === '' || $fra < $neste_res_fra) $neste_res_fra = $fra;
                    }
                }
                // If no active rental but there is a future booking, show that.
            }

            if ($q !== '') {
                $hay = strtolower(($kode ?: $p->post_title) . ' ' . $pir . ' ' . $plassnr . ' ' . $bat_status . ' ' . ($utleid ? 'utleid' : 'ledig'));
                if (strpos($hay, strtolower($q)) === false) continue;
            }

            $rows[] = [
                'id' => $p->ID,
                'batplasskode' => $kode ?: $p->post_title,
                'bredde' => (string)get_post_meta($p->ID, 'admin_havn_bredde_m', true),
                'pir' => $pir,
                'plassnr' => $plassnr,
                'batplass_status' => $bat_status ?: '—',
                'utleie_status' => $utleid ? ('Utleid til ' . $utleid_til) : ($neste_res_fra ? ('Reservert fra ' . $neste_res_fra) : 'Ledig for utleie'),
                'utleie_badge_text' => $utleid ? 'Utleid' : ($neste_res_fra ? 'Reservert' : 'Ledig'),
                'utleie_badge_class' => admin_havn_portal_badge_class($utleid ? 'utleid' : ($neste_res_fra ? 'reservert' : 'ledig for utleie')),
                'utleid_fra' => $utleid_fra,
                'utleid_til' => $utleid_til,
                'neste_res_fra' => $neste_res_fra,
            ];
        }
        wp_send_json_success(['rows' => $rows]);
    }


    $posts = get_posts([
        'post_type' => 'medlem',
        'post_status' => ['publish','private','draft','pending','archived'],
        'posts_per_page' => 200,
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);
    $rows = [];
    $aar = (int)current_time('Y');
    foreach ($posts as $p) {
        $fornavn = get_post_meta($p->ID, 'admin_havn_fornavn', true);
        $etternavn = get_post_meta($p->ID, 'admin_havn_etternavn', true);
        $fullt = trim($fornavn . ' ' . $etternavn);
        if ($fullt === '') $fullt = $p->post_title;
        $medlemsnr = get_post_meta($p->ID, 'admin_havn_medlemsnr', true);
        $batplasskode = get_post_meta($p->ID, 'admin_havn_batplasskode', true);
        $kjoept_bredde = get_post_meta($p->ID, 'admin_havn_kjoept_bredde', true);
        $bp_id = admin_havn_portal_find_batplass_by_code($batplasskode);
        $status = $bp_id ? admin_havn_portal_status_for_batplass_id($bp_id) : '';
        if ($q !== '') {
            $hay = strtolower($fullt . ' ' . $medlemsnr . ' ' . $batplasskode . ' ' . $status . ' ' . $kjoept_bredde);
            if (strpos($hay, strtolower($q)) === false) continue;
        }
        $rows[] = [
            'id' => $p->ID,
            'fullt_navn' => $fullt,
            'medlemsnr' => $medlemsnr,
            'batplasskode' => $batplasskode,
            'kjoept_bredde' => $kjoept_bredde,
            'status' => $status ?: '—',
            'status_badge_class' => admin_havn_portal_badge_class($status ?: ''),
                'kan_leies_ut' => (get_post_meta($p->ID,'admin_havn_kan_leies_ut',true) ?: 'Nei'),
        ];

        if ($for_dugnad) {
            // Summer timer for valgt år (enkelt og robust)
            $sum = 0.0;
            $dq = new WP_Query([
                'post_type' => 'dugnadstime',
                'post_status' => ['publish','private','draft','pending','archived'],
                'posts_per_page' => 500,
                'meta_query' => [
                    [
                        'key' => 'admin_havn_dugnad_medlem_id',
                        'value' => (string)$p->ID,
                        'compare' => '=',
                    ],
                ],
                'no_found_rows' => true,
            ]);
            if (!empty($dq->posts)) {
                foreach ($dq->posts as $dp) {
                    $dato = (string)get_post_meta($dp->ID, 'admin_havn_dugnad_dato', true);
                    $y = (int)substr($dato ?: '', 0, 4);
                    if ($y && $y !== $aar) continue;
                    $t = (string)get_post_meta($dp->ID, 'admin_havn_dugnad_timer', true);
                    $sum += (float)str_replace(',', '.', $t);
                }
            }
            $rows[count($rows)-1]['sum_timer'] = $sum;
        }
    }
    wp_send_json_success(['rows' => $rows]);
});

add_action('wp_ajax_admin_havn_portal_get_medlem', function() {
    admin_havn_portal_ajax_guard();
    $id = (int)($_POST['id'] ?? 0);
    $p = get_post($id);
    if (!$p || $p->post_type !== 'medlem') wp_send_json_error(['message' => 'Ugyldig medlem.'], 404);
    $get = function($k) use ($id) { return (string)get_post_meta($id, $k, true); };
    $fornavn = $get('admin_havn_fornavn');
    $etternavn = $get('admin_havn_etternavn');
    $fullt = trim($fornavn . ' ' . $etternavn);
    if ($fullt === '') $fullt = $p->post_title;
    $batplasskode = $get('admin_havn_batplasskode');
    $bp_id = admin_havn_portal_find_batplass_by_code($batplasskode);
    $bp_status = $bp_id ? admin_havn_portal_status_for_batplass_id($bp_id) : '';
    wp_send_json_success([
        'id' => $id,
        'fullt_navn' => $fullt,
        'medlemsnr' => $get('admin_havn_medlemsnr'),
        'batplasskode' => $batplasskode,
        'batplass_status' => $bp_status ?: '—',
        'batplass_status_badge_class' => admin_havn_portal_badge_class($bp_status ?: ''),
        'admin_havn_fornavn' => $fornavn,
        'admin_havn_etternavn' => $etternavn,
        'admin_havn_medlemskategori' => $get('admin_havn_medlemskategori'),
        'admin_havn_dugnadsplikt' => $get('admin_havn_dugnadsplikt'),
        'admin_havn_epost' => $get('admin_havn_epost'),
        'admin_havn_telefon' => $get('admin_havn_telefon'),
        'admin_havn_adresse' => $get('admin_havn_adresse'),
        'admin_havn_postnr' => $get('admin_havn_postnr'),
        'admin_havn_poststed' => $get('admin_havn_poststed'),
        'admin_havn_batplasskode' => $batplasskode,
        'admin_havn_kjoept_bredde' => $get('admin_havn_kjoept_bredde'),
        'admin_havn_onskes_solgt' => $get('admin_havn_onskes_solgt'),
        'admin_havn_til_salgs_dato' => $get('admin_havn_til_salgs_dato'),
        'admin_havn_avslutt_medlemskap' => $get('admin_havn_avslutt_medlemskap'),
        'admin_havn_avsluttet_dato' => $get('admin_havn_avsluttet_dato'),
    ]);
});

add_action('wp_ajax_admin_havn_portal_update_medlem', function() {
    admin_havn_portal_ajax_guard();
    $id = (int)($_POST['id'] ?? 0);
    $p = get_post($id);
    if (!$p || $p->post_type !== 'medlem') wp_send_json_error(['message' => 'Ugyldig medlem.'], 404);

    $allowed = [
        'admin_havn_fornavn','admin_havn_etternavn','admin_havn_medlemskategori','admin_havn_dugnadsplikt',
        'admin_havn_epost','admin_havn_telefon','admin_havn_adresse','admin_havn_postnr','admin_havn_poststed',
        'admin_havn_batplasskode','admin_havn_kjoept_bredde','admin_havn_onskes_solgt','admin_havn_til_salgs_dato',
        'admin_havn_avslutt_medlemskap','admin_havn_avsluttet_dato'
    ];
    foreach ($allowed as $k) {
        if (!isset($_POST[$k])) continue;

        if ($k === 'admin_havn_kjoept_bredde') {
            $raw = sanitize_text_field(wp_unslash($_POST[$k]));
            $raw = str_replace(' ', '', $raw);
            $raw = str_replace(',', '.', $raw);
            $val = '';
            if ($raw !== '' && is_numeric($raw)) {
                $val = rtrim(rtrim(number_format((float)$raw, 3, '.', ''), '0'), '.');
            }
            update_post_meta($id, $k, $val);
            continue;
        }

        update_post_meta($id, $k, sanitize_text_field(wp_unslash($_POST[$k])));
    }
    $fornavn = get_post_meta($id, 'admin_havn_fornavn', true);
    $etternavn = get_post_meta($id, 'admin_havn_etternavn', true);
    $title = trim($fornavn . ' ' . $etternavn);
    if ($title !== '' && $title !== $p->post_title) {
        wp_update_post(['ID' => $id, 'post_title' => $title]);
    }
    wp_send_json_success(['ok' => true]);
});

// ============================
// DUGNAD (styreportal)
// ============================

add_action('wp_ajax_admin_havn_portal_get_dugnad', function() {
    admin_havn_portal_ajax_guard();
    $id = (int)($_POST['id'] ?? 0);
    $p = get_post($id);
    if (!$p || $p->post_type !== 'medlem') wp_send_json_error(['message' => 'Ugyldig medlem.'], 404);

    $fornavn = (string)get_post_meta($id, 'admin_havn_fornavn', true);
    $etternavn = (string)get_post_meta($id, 'admin_havn_etternavn', true);
    $fullt = trim($fornavn . ' ' . $etternavn);
    if ($fullt === '') $fullt = $p->post_title;

    $aar = (int)current_time('Y');
    $dq = new WP_Query([
        'post_type' => 'dugnadstime',
        'post_status' => ['publish','private','draft','pending','archived'],
        'posts_per_page' => 500,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => [[
            'key' => 'admin_havn_dugnad_medlem_id',
            'value' => (string)$id,
            'compare' => '=',
        ]],
        'no_found_rows' => true,
    ]);

    $entries = [];
    $sum = 0.0;
    foreach (($dq->posts ?? []) as $dp) {
        $dato = (string)get_post_meta($dp->ID, 'admin_havn_dugnad_dato', true);
        $y = (int)substr($dato ?: '', 0, 4);
        if ($y && $y !== $aar) continue;
        $timer = (string)get_post_meta($dp->ID, 'admin_havn_dugnad_timer', true);
        $t = (float)str_replace(',', '.', $timer);
        $sum += $t;
        $entries[] = [
            'dato' => $dato,
            'timer' => $timer,
            'notat' => (string)get_post_meta($dp->ID, 'admin_havn_dugnad_notat', true),
        ];
    }

    wp_send_json_success([
        'id' => $id,
        'fullt_navn' => $fullt,
        'medlemsnr' => (string)get_post_meta($id,'admin_havn_medlemsnr',true),
        'aar' => $aar,
        'sum_timer' => $sum,
        'default_dato' => current_time('Y-m-d'),
        'entries' => $entries,
    ]);
});

add_action('wp_ajax_admin_havn_portal_add_dugnad', function() {
    admin_havn_portal_ajax_guard();
    $id = (int)($_POST['id'] ?? 0);
    $p = get_post($id);
    if (!$p || $p->post_type !== 'medlem') wp_send_json_error(['message' => 'Ugyldig medlem.'], 404);

    $dato = sanitize_text_field(wp_unslash($_POST['dugnad_dato'] ?? ''));
    $timer_raw = sanitize_text_field(wp_unslash($_POST['dugnad_timer'] ?? ''));
    $notat = sanitize_text_field(wp_unslash($_POST['dugnad_notat'] ?? ''));

    $timer = (float)str_replace(',', '.', $timer_raw);
    if ($dato === '') wp_send_json_error(['message' => 'Dato mangler.'], 400);
    if ($timer <= 0) wp_send_json_error(['message' => 'Timer må være større enn 0.'], 400);

    $title = $p->post_title . ' - ' . $dato . ' - ' . $timer_raw . ' t';
    $did = wp_insert_post([
        'post_type' => 'dugnadstime',
        'post_title' => $title,
        'post_status' => 'publish',
    ], true);
    if (is_wp_error($did) || !$did) wp_send_json_error(['message' => 'Kunne ikke registrere dugnad.'], 500);

    update_post_meta($did, 'admin_havn_dugnad_medlem_id', (string)$id);
    update_post_meta($did, 'admin_havn_dugnad_medlem', $p->post_title);
    update_post_meta($did, 'admin_havn_dugnad_dato', $dato);
    update_post_meta($did, 'admin_havn_dugnad_timer', $timer_raw);
    update_post_meta($did, 'admin_havn_dugnad_notat', $notat);

    wp_send_json_success(['ok' => true]);
});

add_action('wp_ajax_admin_havn_portal_get_batplass', function() {
    admin_havn_portal_ajax_guard();
    $id = (int)($_POST['id'] ?? 0);
    $p = get_post($id);
    if (!$p || $p->post_type !== 'batplass') wp_send_json_error(['message' => 'Ugyldig båtplass.'], 404);
    $get = function($k) use ($id) { return (string)get_post_meta($id, $k, true); };
    $kode = $get('admin_havn_batplasskode_bp');
    if (!$kode) $kode = $get('admin_havn_batplasskode');
    $status = admin_havn_portal_status_for_batplass_id($id);
    wp_send_json_success([
        'id' => $id,
        'batplasskode' => $kode ?: $p->post_title,
        'pir' => $get('admin_havn_pir'),
        'plassnr' => $get('admin_havn_plassnr'),
        'status' => $status ?: '—',
        'status_badge_class' => admin_havn_portal_badge_class($status ?: ''),
                'kan_leies_ut' => (get_post_meta($p->ID,'admin_havn_kan_leies_ut',true) ?: 'Nei'),
        'admin_havn_batplasskode_bp' => $kode,
        'admin_havn_pir' => $get('admin_havn_pir'),
        'admin_havn_plassnr' => $get('admin_havn_plassnr'),
        'admin_havn_status' => $get('admin_havn_status'),
        'admin_havn_sperret' => $get('admin_havn_sperret'),
        'admin_havn_bredde_m' => $get('admin_havn_bredde_m'),
        'admin_havn_utrigger_m' => $get('admin_havn_utrigger_m'),
        'admin_havn_lang_utrigger' => $get('admin_havn_lang_utrigger'),
        'admin_havn_2x_gangriggar' => $get('admin_havn_2x_gangriggar'),
        'admin_havn_kwh' => $get('admin_havn_kwh'),
        'admin_havn_kan_leies_ut' => ($get('admin_havn_kan_leies_ut') ?: 'Nei'),
        'admin_havn_notat' => $get('admin_havn_notat'),
    ]);
});

add_action('wp_ajax_admin_havn_portal_update_batplass', function() {
    admin_havn_portal_ajax_guard();
    $id = (int)($_POST['id'] ?? 0);
    $p = get_post($id);
    if (!$p || $p->post_type !== 'batplass') wp_send_json_error(['message' => 'Ugyldig båtplass.'], 404);
    $allowed = [
        'admin_havn_batplasskode_bp','admin_havn_pir','admin_havn_plassnr','admin_havn_status','admin_havn_sperret',
        'admin_havn_bredde_m','admin_havn_utrigger_m','admin_havn_lang_utrigger','admin_havn_2x_gangriggar',
        'admin_havn_kwh','admin_havn_kan_leies_ut','admin_havn_notat'
    ];
    foreach ($allowed as $k) {
        if (isset($_POST[$k])) update_post_meta($id, $k, sanitize_text_field(wp_unslash($_POST[$k])));
    }
    $kode = get_post_meta($id, 'admin_havn_batplasskode_bp', true);
    if ($kode && $kode !== $p->post_title) {
        wp_update_post(['ID' => $id, 'post_title' => $kode]);
    }
    wp_send_json_success(['ok' => true]);
});



/* ============================
   UTLEIE (PORTAL AJAX)
============================ */

function admin_havn_portal_get_active_utleie_id_for_batplass($batplass_id) {
    $q = new WP_Query([
        'post_type' => 'utleie',
        'post_status' => ['publish'],
        'posts_per_page' => 1,
        'meta_query' => [[
            'key' => 'admin_havn_utleie_batplass_id',
            'value' => (string)$batplass_id,
            'compare' => '=',
        ]],
        'no_found_rows' => true,
    ]);
    if (!empty($q->posts)) return (int)$q->posts[0]->ID;
    return 0;
}

add_action('wp_ajax_admin_havn_portal_get_utleie', function() {
    admin_havn_portal_ajax_guard();
    $batplass_id = (int)($_POST['id'] ?? 0);
    $p = get_post($batplass_id);
    if (!$p || $p->post_type !== 'batplass') wp_send_json_error(['message' => 'Ugyldig båtplass.'], 404);

    $getbp = function($k) use ($batplass_id) { return (string)get_post_meta($batplass_id, $k, true); };
    $kode = $getbp('admin_havn_batplasskode_bp');
    if (!$kode) $kode = $getbp('admin_havn_batplasskode');
    $pir = $getbp('admin_havn_pir');
    $plassnr = $getbp('admin_havn_plassnr');
    $bat_status = admin_havn_portal_status_for_batplass_id($batplass_id);

    $utleie_id = admin_havn_portal_get_active_utleie_id_for_batplass($batplass_id);
    $isutleid = (bool)$utleie_id;

    $getu = function($k) use ($utleie_id) {
        return $utleie_id ? (string)get_post_meta($utleie_id, $k, true) : '';
    };

    $agreement_no = $utleie_id ? (string)get_post_meta($utleie_id, 'admin_havn_agreement_no', true) : '';

    wp_send_json_success([
        'id' => $batplass_id,
        'batplasskode' => $kode ?: $p->post_title,
        'pir' => $pir,
        'plassnr' => $plassnr,
        'batplass_status' => $bat_status ?: '—',
        'batplass_status_badge_class' => admin_havn_portal_badge_class($bat_status ?: ''),
        'utleie_id' => $utleie_id ?: '',
        'agreement_no' => $agreement_no,
        'utleie_badge_text' => $isutleid ? 'Utleid' : 'Ledig',
        'utleie_badge_class' => admin_havn_portal_badge_class($isutleid ? 'utleid' : 'ledig for utleie'),

        'admin_havn_leietaker_navn' => $getu('admin_havn_leietaker_navn'),
        'admin_havn_leietaker_member_id' => $getu('admin_havn_leietaker_member_id'),
        'admin_havn_leietaker_telefon' => $getu('admin_havn_leietaker_telefon'),
        'admin_havn_leietaker_adresse' => $getu('admin_havn_leietaker_adresse'),
        'admin_havn_leietaker_epost' => $getu('admin_havn_leietaker_epost'),
        'admin_havn_utleie_fra' => $getu('admin_havn_utleie_fra'),
        'admin_havn_utleie_til' => $getu('admin_havn_utleie_til'),
        'admin_havn_utleie_fakturert' => ($getu('admin_havn_utleie_fakturert') ?: 'Nei'),
        'admin_havn_utleie_belop' => $getu('admin_havn_utleie_belop'),
        'admin_havn_utleie_faktura_sendt' => $getu('admin_havn_utleie_faktura_sendt'),
        'admin_havn_utleie_notat' => $getu('admin_havn_utleie_notat'),
    ]);
});



add_action('wp_ajax_admin_havn_portal_utleie_timeline', function() {
    admin_havn_portal_ajax_guard();

    $start = isset($_POST['start']) ? sanitize_text_field((string)$_POST['start']) : '';
    $end   = isset($_POST['end']) ? sanitize_text_field((string)$_POST['end']) : '';
    if (!$start || !$end) {
        wp_send_json_error(['message' => 'Mangler start/slutt.'], 400);
    }
    $ts_start = strtotime($start);
    $ts_end = strtotime($end);
    if (!$ts_start || !$ts_end || $ts_end <= $ts_start) {
        wp_send_json_error(['message' => 'Ugyldig dato-intervall.'], 400);
    }

    // Båtplasser som kan leies ut
    $spots = get_posts([
        'post_type' => 'batplass',
        'post_status' => ['publish','private','draft','pending','archived'],
        'posts_per_page' => 500,
        // Sorter først på bredde, deretter tittel/kode for stabil grouping i UI
        'meta_key' => 'admin_havn_bredde_m',
        'orderby' => ['meta_value_num' => 'ASC', 'title' => 'ASC'],
        'order' => 'ASC',
        // Kun plasser som kan leies ut
        'meta_query' => [
            [
                'key' => 'admin_havn_kan_leies_ut',
                'value' => 'Ja',
                'compare' => '='
            ]
        ],
        'no_found_rows' => true,
    ]);
    $spot_rows = [];
    $spot_ids = [];
    foreach ($spots as $sp) {
        $kode = (string)get_post_meta($sp->ID, 'admin_havn_batplasskode_bp', true);
        if (!$kode) $kode = (string)get_post_meta($sp->ID, 'admin_havn_batplasskode', true);
        if (!$kode) $kode = $sp->post_title;
        $bredde = (string)get_post_meta($sp->ID, 'admin_havn_bredde_m', true);
        $spot_rows[] = ['id' => (int)$sp->ID, 'code' => $kode, 'width' => $bredde];
        $spot_ids[] = (int)$sp->ID;
    }

    // Utleier som overlapper intervallet
    $q = new WP_Query([
        'post_type' => 'utleie',
        'post_status' => ['publish','private','draft','pending','archived'],
        'posts_per_page' => 2000,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'admin_havn_utleie_fra',
                'value' => $end,
                'compare' => '<=',
                'type' => 'CHAR'
            ],
            [
                'key' => 'admin_havn_utleie_til',
                'value' => $start,
                'compare' => '>=',
                'type' => 'CHAR'
            ],
        ],
        'no_found_rows' => true,
    ]);

    $leases = [];
    if (!empty($q->posts)) {
        foreach ($q->posts as $up) {
            $bid = (int)get_post_meta($up->ID, 'admin_havn_utleie_batplass_id', true);
            if ($spot_ids && !in_array($bid, $spot_ids, true)) continue;
            $fra = (string)get_post_meta($up->ID, 'admin_havn_utleie_fra', true);
            $til = (string)get_post_meta($up->ID, 'admin_havn_utleie_til', true);
            if (!$fra || !$til) continue;
            $name = (string)get_post_meta($up->ID, 'admin_havn_leietaker_navn', true);
            if (!$name) $name = 'Leietaker';
            $leases[] = [
                'id' => (int)$up->ID,
                'batplass_id' => $bid,
                'from' => $fra,
                'to' => $til,
                'name' => $name,
            ];
        }
    }

    wp_send_json_success([
        'ok' => true,
        'start' => $start,
        'end' => $end,
        'spots' => $spot_rows,
        'leases' => $leases,
    ]);
});
add_action('wp_ajax_admin_havn_portal_save_utleie', function() {
    admin_havn_portal_ajax_guard();
    $batplass_id = (int)($_POST['id'] ?? 0);
    $p = get_post($batplass_id);
    if (!$p || $p->post_type !== 'batplass') wp_send_json_error(['message' => 'Ugyldig båtplass.'], 404);

    $utleie_id = isset($_POST['utleie_id']) ? (int)$_POST['utleie_id'] : 0;
    if ($utleie_id) {
        $up = get_post($utleie_id);
        if (!$up || $up->post_type !== 'utleie') $utleie_id = 0;
    }
    if (!$utleie_id) {
        $utleie_id = wp_insert_post([
            'post_type' => 'utleie',
            'post_status' => 'publish',
            'post_title' => 'Utleie ' . ($batplass_id),
        ], true);
        if (is_wp_error($utleie_id) || !$utleie_id) {
            $m = is_wp_error($utleie_id) ? $utleie_id->get_error_message() : 'Ukjent feil.';
            wp_send_json_error(['message' => 'Kunne ikke opprette utleie: ' . $m], 403);
        }
        update_post_meta($utleie_id, 'admin_havn_utleie_batplass_id', (string)$batplass_id);
    }

    $allowed = [
        'admin_havn_leietaker_member_id',
        'admin_havn_leietaker_navn','admin_havn_leietaker_telefon','admin_havn_leietaker_adresse','admin_havn_leietaker_epost',
        'admin_havn_utleie_fra','admin_havn_utleie_til','admin_havn_utleie_fakturert','admin_havn_utleie_belop','admin_havn_utleie_notat'
    ];
    foreach ($allowed as $k) {
        if (isset($_POST[$k])) {
            $val = wp_unslash($_POST[$k]);
            if (in_array($k, ['admin_havn_utleie_notat'], true)) {
                update_post_meta($utleie_id, $k, sanitize_textarea_field($val));
            } else {
                update_post_meta($utleie_id, $k, sanitize_text_field($val));
            }
        }
    }

    // Keep title informative
    $kode = get_post_meta($batplass_id, 'admin_havn_batplasskode_bp', true);
    if (!$kode) $kode = get_post_meta($batplass_id, 'admin_havn_batplasskode', true);
    $tname = sanitize_text_field(wp_unslash($_POST['admin_havn_leietaker_navn'] ?? ''));
    $new_title = 'Utleie ' . ($kode ?: $batplass_id) . ($tname ? ' - ' . $tname : '');
    wp_update_post(['ID' => $utleie_id, 'post_title' => $new_title]);

    // Create agreement/invoice record once (agreement_no == fakturanr/avtalenr)
    // This only creates a record for later follow-up (sending/payment can be added later).
    $existing_no = (int)get_post_meta($utleie_id, 'admin_havn_agreement_no', true);
    $belop_raw = (string)get_post_meta($utleie_id, 'admin_havn_utleie_belop', true);
    $belop_num = floatval(str_replace([',', ' '], ['.', ''], $belop_raw));

    if (!$existing_no && $belop_num > 0 && function_exists('admin_havn_create_agreement')) {
        $customer_type = 'external';
        $member_id = 0;

        // Best-effort: link to member if we find matching e-post
        $email = (string)get_post_meta($utleie_id, 'admin_havn_leietaker_epost', true);
        if ($email) {
            $q = new WP_Query([
                'post_type' => 'medlem',
                'posts_per_page' => 1,
                'post_status' => ['publish','private','draft'],
                'meta_query' => [[ 'key' => 'E-post', 'value' => $email, 'compare' => '=' ]],
            ]);
            if ($q->have_posts()) {
                $member_id = (int)$q->posts[0]->ID;
                $customer_type = 'member';
            }
        }

        $navn = (string)get_post_meta($utleie_id, 'admin_havn_leietaker_navn', true);
        $tlf  = (string)get_post_meta($utleie_id, 'admin_havn_leietaker_telefon', true);
        $adr  = (string)get_post_meta($utleie_id, 'admin_havn_leietaker_adresse', true);
        $fra  = (string)get_post_meta($utleie_id, 'admin_havn_utleie_fra', true);
        $til  = (string)get_post_meta($utleie_id, 'admin_havn_utleie_til', true);

        $descr = 'Utleie båtplass ' . ($kode ?: $batplass_id);
        if ($fra || $til) {
            $descr .= ' (' . ($fra ?: '—') . ' til ' . ($til ?: '—') . ')';

            // Merk faktura dersom perioden er kortere enn minste leietid (pris beregnes som minimum).
            if ($fra && $til) {
                $fromDt = DateTime::createFromFormat('Y-m-d', $fra);
                $toDt   = DateTime::createFromFormat('Y-m-d', $til);
                if ($fromDt && $toDt && $toDt >= $fromDt) {
                    $year = intval($fromDt->format('Y'));
                    $rates = admin_havn_get_rental_rates();
                    $rows = array_values(array_filter($rates, function($r) use ($year){
                        return intval($r['year']) === intval($year) && $r['object'] === 'boat';
                    }));
                    if ($rows) {
                        $pick = function(DateTime $d) use ($rows, $year) {
                            foreach ($rows as $r) {
                                $fromParts = explode('-', $r['from_md']);
                                $toParts   = explode('-', $r['to_md']);
                                $fromDate = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, intval($fromParts[0]), intval($fromParts[1])));
                                $toDate   = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, intval($toParts[0]), intval($toParts[1])));
                                if (!$fromDate || !$toDate) continue;
                                if ($toDate < $fromDate) continue;
                                $cmp = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, intval($d->format('m')), intval($d->format('d'))));
                                if ($cmp >= $fromDate && $cmp <= $toDate) return $r;
                            }
                            return null;
                        };
                        $startRate = $pick($fromDt);
                        if ($startRate) {
                            $days = intval($toDt->diff($fromDt)->days) + 1;
                            $minDays = intval($startRate['min_weeks']) * 7;
                            if ($minDays > 0 && $days < $minDays) {
                                $mw = intval($startRate['min_weeks']);
                                $descr .= ' – pris beregnet som minste leietid (' . $mw . ' uker)';
                            }
                        }
                    }
                }
            }

            $descr .= ')';
        }

        $res = admin_havn_create_agreement([
            'type' => 'utleie_batplass',
            'customer_type' => $customer_type,
            'member_id' => $member_id ?: null,
            'external_name' => $customer_type==='external' ? $navn : null,
            'external_email' => $customer_type==='external' ? $email : null,
            'external_phone' => $customer_type==='external' ? $tlf : null,
            'external_address' => $customer_type==='external' ? $adr : null,
            'source_type' => 'utleie',
            'source_id' => $utleie_id,
            'status' => 'generated',
        ], [[
            'description' => $descr,
            'qty' => 1,
            'unit_price' => $belop_num,
            'amount' => $belop_num,
            'meta' => ['batplass_id' => $batplass_id, 'batplasskode' => $kode, 'fra' => $fra, 'til' => $til],
        ]]);

        if (is_wp_error($res)) {
            wp_send_json_error(['message' => 'Kunne ikke generere faktura: ' . $res->get_error_message()], 500);
        }
        if (is_array($res) && !empty($res['agreement_no'])) {
            update_post_meta($utleie_id, 'admin_havn_agreement_no', (string)intval($res['agreement_no']));
            // Mark as invoiced locally
            update_post_meta($utleie_id, 'admin_havn_utleie_fakturert', 'Ja');
        } else {
            wp_send_json_error(['message' => 'Kunne ikke generere faktura (ukjent feil).'], 500);
        }
    }

    $final_no = (string)get_post_meta($utleie_id, 'admin_havn_agreement_no', true);
    wp_send_json_success(['ok' => true, 'utleie_id' => $utleie_id, 'agreement_no' => $final_no]);
});

// Utleie -> Faktura: Oppretter/oppdaterer utleie og legger fakturagrunnlaget i Faktura-modulen (status: draft).
// Selve faktureringen (statusendring og evt utsendelse) håndteres i Faktura.
add_action('wp_ajax_admin_havn_portal_submit_utleie_to_faktura', function() {
    admin_havn_portal_ajax_guard();
    require_once __DIR__ . '/includes/agreements.php';

    $utleie_id = (int)($_POST['utleie_id'] ?? 0);
    $batplass_id = (int)($_POST['batplass_id'] ?? 0);
    $navn = sanitize_text_field($_POST['admin_havn_leietaker_navn'] ?? '');
    $telefon = sanitize_text_field($_POST['admin_havn_leietaker_telefon'] ?? '');
    $epost = sanitize_text_field($_POST['admin_havn_leietaker_epost'] ?? '');
    $adresse = sanitize_text_field($_POST['admin_havn_leietaker_adresse'] ?? '');
    $fra = sanitize_text_field($_POST['admin_havn_utleie_fra'] ?? '');
    $til = sanitize_text_field($_POST['admin_havn_utleie_til'] ?? '');
    $belop = sanitize_text_field($_POST['admin_havn_utleie_belop'] ?? '');
    $notat = sanitize_textarea_field($_POST['admin_havn_utleie_notat'] ?? '');

    if (!$batplass_id) wp_send_json_error(['message' => 'Velg båtplass.'], 400);
    if ($fra && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fra)) wp_send_json_error(['message' => 'Ugyldig fra-dato.'], 400);
    if ($til && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $til)) wp_send_json_error(['message' => 'Ugyldig til-dato.'], 400);

    // Opprett/oppdater utleie-post
    if ($utleie_id > 0) {
        $p = get_post($utleie_id);
        if (!$p || $p->post_type !== 'utleie') wp_send_json_error(['message' => 'Ugyldig utleie.'], 404);
        wp_update_post(['ID' => $utleie_id, 'post_title' => 'Utleie ' . $batplass_id, 'post_status' => 'publish']);
    } else {
        $utleie_id = wp_insert_post(['post_type' => 'utleie', 'post_title' => 'Utleie ' . $batplass_id, 'post_status' => 'publish']);
    }

    update_post_meta($utleie_id, 'admin_havn_utleie_batplass_id', $batplass_id);
    update_post_meta($utleie_id, 'admin_havn_leietaker_navn', $navn);
    update_post_meta($utleie_id, 'admin_havn_leietaker_telefon', $telefon);
    update_post_meta($utleie_id, 'admin_havn_leietaker_epost', $epost);
    update_post_meta($utleie_id, 'admin_havn_leietaker_adresse', $adresse);
    update_post_meta($utleie_id, 'admin_havn_utleie_fra', $fra);
    update_post_meta($utleie_id, 'admin_havn_utleie_til', $til);
    update_post_meta($utleie_id, 'admin_havn_utleie_belop', $belop);
    update_post_meta($utleie_id, 'admin_havn_utleie_notat', $notat);

    // Avtale i fakturamodulen
    $agreement_no = (string)get_post_meta($utleie_id, 'admin_havn_agreement_no', true);
    if ($agreement_no === '') {
        $kode = (string)get_post_meta($batplass_id, 'admin_havn_batplasskode_bp', true);
        if (!$kode) $kode = (string)get_post_meta($batplass_id, 'admin_havn_batplasskode', true);
        $title = 'Utleie båtplass ' . ($kode ?: '#'.$batplass_id);

        $agreement_no = (string)admin_havn_agreements_create([
            'type' => 'utleie',
            'title' => $title,
            'customer_name' => $navn,
            'customer_email' => $epost,
            'customer_phone' => $telefon,
            'customer_address' => $adresse,
            'source_post_type' => 'utleie',
            'source_post_id' => $utleie_id,
            'status' => 'draft',
            'period_from' => $fra,
            'period_to' => $til,
            'amount' => $belop,
        ]);
        update_post_meta($utleie_id, 'admin_havn_agreement_no', $agreement_no);
    } else {
        // Oppdater avtalen (forutsatt at den fortsatt er i draft)
        admin_havn_agreements_update((int)$agreement_no, [
            'customer_name' => $navn,
            'customer_email' => $epost,
            'customer_phone' => $telefon,
            'customer_address' => $adresse,
            'period_from' => $fra,
            'period_to' => $til,
            'amount' => $belop,
        ]);
    }

    wp_send_json_success(['ok' => true, 'utleie_id' => $utleie_id, 'agreement_no' => $agreement_no]);
});

add_action('wp_ajax_admin_havn_portal_archive_utleie', function() {
    admin_havn_portal_ajax_guard();
    $utleie_id = (int)($_POST['utleie_id'] ?? 0);
    $p = get_post($utleie_id);
    if (!$p || $p->post_type !== 'utleie') wp_send_json_error(['message' => 'Ugyldig utleie.'], 404);
    wp_update_post(['ID' => $utleie_id, 'post_status' => 'archived']);
    wp_send_json_success(['ok' => true]);
});

add_action('wp_ajax_admin_havn_portal_send_utleie_invoice', function() {
    admin_havn_portal_ajax_guard();
    $utleie_id = (int)($_POST['utleie_id'] ?? 0);
    $p = get_post($utleie_id);
    if (!$p || $p->post_type !== 'utleie') wp_send_json_error(['message' => 'Ugyldig utleie.'], 404);

    $to = (string)get_post_meta($utleie_id, 'admin_havn_leietaker_epost', true);
    if (!is_email($to)) wp_send_json_error(['message' => 'Leietaker har ikke gyldig e-post.'], 400);

    $batplass_id = (int)get_post_meta($utleie_id, 'admin_havn_utleie_batplass_id', true);
    $kode = $batplass_id ? (string)get_post_meta($batplass_id, 'admin_havn_batplasskode_bp', true) : '';
    if (!$kode && $batplass_id) $kode = (string)get_post_meta($batplass_id, 'admin_havn_batplasskode', true);

    $navn = (string)get_post_meta($utleie_id, 'admin_havn_leietaker_navn', true);
    $fra = (string)get_post_meta($utleie_id, 'admin_havn_utleie_fra', true);
    $til = (string)get_post_meta($utleie_id, 'admin_havn_utleie_til', true);
    $belop = (string)get_post_meta($utleie_id, 'admin_havn_utleie_belop', true);

    $subject = 'Faktura – Utleie båtplass ' . ($kode ?: '');
    $lines = [];
    $lines[] = 'Hei' . ($navn ? ' ' . $navn : '') . ',';
    $lines[] = '';
    $lines[] = 'Dette er fakturagrunnlag for utleie av båtplass ' . ($kode ?: '') . '.';
    if ($fra || $til) $lines[] = 'Periode: ' . ($fra ?: '—') . ' til ' . ($til ?: '—');
    if ($belop !== '') $lines[] = 'Beløp: ' . $belop;
    $lines[] = '';
    $lines[] = 'Vennlig hilsen';
    $lines[] = get_bloginfo('name');

    $message = implode("\n", $lines);

    $sent = wp_mail($to, $subject, $message);
    if (!$sent) wp_send_json_error(['message' => 'Kunne ikke sende e-post (wp_mail feilet).'], 500);

    update_post_meta($utleie_id, 'admin_havn_utleie_fakturert', 'Ja');
    update_post_meta($utleie_id, 'admin_havn_utleie_faktura_sendt', current_time('Y-m-d'));

    wp_send_json_success(['ok' => true]);
});

/* ============================
   FAKTURA / AVTALE (PORTAL)
============================ */

add_action('wp_ajax_admin_havn_portal_get_agreement', function() {
    admin_havn_portal_ajax_guard();
    $no = (int)($_POST['id'] ?? 0);
    if ($no <= 0) wp_send_json_error(['message' => 'Ugyldig avtalenr.'], 400);
    $a = admin_havn_get_agreement($no);
    if (!$a) wp_send_json_error(['message' => 'Fant ikke avtale.'], 404);

    // Resolve customer label
    $customer = '';
    if (($a['customer_type'] ?? '') === 'member' && !empty($a['member_id'])) {
        $m = get_post((int)$a['member_id']);
        $customer = $m ? $m->post_title : ('Medlem #' . (int)$a['member_id']);
    } else {
        $customer = (string)($a['external_name'] ?? 'Ekstern');
    }

    $a['customer_label'] = $customer;
    wp_send_json_success($a);
});

add_action('wp_ajax_admin_havn_portal_update_agreement_status', function() {
    admin_havn_portal_ajax_guard();
    if (!function_exists('admin_havn_update_agreement_status')) {
        wp_send_json_error(['message' => 'Fakturamodul er ikke tilgjengelig.'], 500);
    }
    $no = (int)($_POST['agreement_no'] ?? 0);
    $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));
    if ($no <= 0) wp_send_json_error(['message' => 'Ugyldig avtalenr.'], 400);
    if (!$status) wp_send_json_error(['message' => 'Mangler status.'], 400);

    // Ikke tillat "tilbakeføring" av status (forhindrer at man kan gå bakover i historikken).
    // Tillater alltid overgang til void/archived, ellers kun fremover.
    $a = function_exists('admin_havn_get_agreement') ? admin_havn_get_agreement($no) : null;
    if ($a && isset($a['status'])) {
        $cur = (string)$a['status'];
        $order = [
            'draft'     => 0,
            'generated' => 1,
            'sent'      => 2,
            'paid'      => 3,
            'void'      => 98,
            'archived'  => 99,
        ];
        $to = (string)$status;
        $curO = $order[$cur] ?? 1;
        $toO  = $order[$to]  ?? 1;
        if ($to !== 'void' && $to !== 'archived' && $toO < $curO) {
            wp_send_json_error(['message' => 'Status kan ikke endres bakover.'], 400);
        }
        // Når betalt/annullert, lås videre endringer (unntatt arkiv)
        if (($cur === 'paid' || $cur === 'void') && $to !== $cur && $to !== 'archived') {
            wp_send_json_error(['message' => 'Status er låst etter Betalt/Annullert (kun Arkiv er tillatt).'], 400);
        }
    }
    $ok = admin_havn_update_agreement_status($no, $status);
    if (!$ok) wp_send_json_error(['message' => 'Kunne ikke oppdatere status.'], 400);

    // Synk status tilbake til kilde (utleie/medlem osv.)
    $a2 = function_exists('admin_havn_get_agreement') ? admin_havn_get_agreement($no) : null;
    if ($a2 && !empty($a2['source_post_type']) && !empty($a2['source_post_id'])) {
        $spt = (string)$a2['source_post_type'];
        $sid = (int)$a2['source_post_id'];
        if ($spt === 'utleie' && $sid > 0) {
            // Lås utleie når faktura er generert/sendt/betalt
            if (in_array($status, ['generated','sent','paid'], true)) {
                update_post_meta($sid, 'admin_havn_utleie_fakturert', 'Ja');
            }
            if ($status === 'sent') {
                update_post_meta($sid, 'admin_havn_utleie_faktura_sendt', current_time('Y-m-d'));
            }
        }
    }
    wp_send_json_success(['ok' => true]);
});

/* ============================
   META BOXES
============================ */

function admin_havn_add_meta_boxes() {
    add_meta_box('admin_havn_medlem_meta', 'Medlemsdetaljer', 'admin_havn_render_medlem_meta', 'medlem', 'normal', 'high');
    add_meta_box('admin_havn_batplass_meta', 'Båtplassdetaljer', 'admin_havn_render_batplass_meta', 'batplass', 'normal', 'high');
    add_meta_box('admin_havn_dugnad_meta', 'Dugnad', 'admin_havn_render_dugnad_meta', 'dugnadstime', 'normal', 'high');
}
add_action('add_meta_boxes', 'admin_havn_add_meta_boxes');

function admin_havn_render_text($name, $value, $placeholder = '') {
    printf(
        '<input type="text" class="regular-text" name="%s" value="%s" placeholder="%s" />',
        esc_attr($name),
        esc_attr($value),
        esc_attr($placeholder)
    );
}

function admin_havn_render_select_yesno($name, $value) {
    $value = strtolower(trim((string)$value));
    $options = [ '' => '—', 'Ja' => 'Ja', 'Nei' => 'Nei' ];
    echo '<select name="' . esc_attr($name) . '">';
    foreach ($options as $k => $label) {
        $selected = (strtolower($k) === $value) ? 'selected' : '';
        echo '<option value="' . esc_attr($k) . '" ' . $selected . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
}

function admin_havn_render_date($name, $value) {
    printf('<input type="date" name="%s" value="%s" />', esc_attr($name), esc_attr($value));
}

function admin_havn_render_medlem_meta($post) {
    wp_nonce_field('admin_havn_save_meta', 'admin_havn_meta_nonce');

    $m = function($key) use ($post) { return get_post_meta($post->ID, $key, true); };

    echo '<table class="form-table"><tbody>';

    // Medlemsnr (vises, men kan ikke redigeres).
    $mn = $m('admin_havn_medlemsnr');
    echo '<tr><th><label>Medlemsnr</label></th><td>';
        printf('<input type="text" class="regular-text" value="%s" readonly />', esc_attr($mn));
        echo '<p class="description">Medlemsnr settes ved import eller automatisk ved lagring hvis feltet mangler. Kan ikke redigeres.</p>';
    echo '</td></tr>';

    echo '<tr><th><label>Fornavn</label></th><td>'; admin_havn_render_text('admin_havn_fornavn', $m('admin_havn_fornavn')); echo '</td></tr>';
    echo '<tr><th><label>Etternavn</label></th><td>'; admin_havn_render_text('admin_havn_etternavn', $m('admin_havn_etternavn')); echo '</td></tr>';
    echo '<tr><th><label>Medlemskategori</label></th><td>'; admin_havn_render_text('admin_havn_medlemskategori', $m('admin_havn_medlemskategori'), 'A eller B'); echo '</td></tr>';
    echo '<tr><th><label>Dugnadsplikt</label></th><td>'; admin_havn_render_select_yesno('admin_havn_dugnadsplikt', $m('admin_havn_dugnadsplikt')); echo '</td></tr>';
    echo '<tr><th><label>E-post</label></th><td>'; admin_havn_render_text('admin_havn_epost', $m('admin_havn_epost')); echo '</td></tr>';
    echo '<tr><th><label>Telefon</label></th><td>'; admin_havn_render_text('admin_havn_telefon', $m('admin_havn_telefon')); echo '</td></tr>';
    echo '<tr><th><label>Adresse</label></th><td>'; admin_havn_render_text('admin_havn_adresse', $m('admin_havn_adresse')); echo '</td></tr>';
    echo '<tr><th><label>Postnr</label></th><td>'; admin_havn_render_text('admin_havn_postnr', $m('admin_havn_postnr')); echo '</td></tr>';
    echo '<tr><th><label>Poststed</label></th><td>'; admin_havn_render_text('admin_havn_poststed', $m('admin_havn_poststed')); echo '</td></tr>';

    echo '<tr><th><label>Båtplass</label></th><td>'; admin_havn_render_text('admin_havn_batplasskode', $m('admin_havn_batplasskode'), 'f.eks 1-27'); echo '<p class="description">Kobling til båtplassregister.</p></td></tr>';

    echo '<tr><th><label>Kjøpt bredde (m)</label></th><td>';
        admin_havn_render_text('admin_havn_kjoept_bredde', $m('admin_havn_kjoept_bredde'), 'f.eks 3,5');
        echo '<p class="description">Brukes som grunnlag for årsavgift. Kan avvike fra tildelt båtplass.</p>';
    echo '</td></tr>';
    $batplassstatus = admin_havn_get_batplass_status_for_medlem($post->ID);
    echo '<tr><th><label>Båtplassstatus</label></th><td><strong>' . esc_html($batplassstatus ?: '—') . '</strong><p class="description">Vises fra båtplassregisteret (display).</p></td></tr>';

    echo '<tr><th><label>Ønskes solgt</label></th><td>'; admin_havn_render_select_yesno('admin_havn_onskes_solgt', $m('admin_havn_onskes_solgt')); echo '</td></tr>';
    echo '<tr><th><label>Til salgs registrert dato</label></th><td>'; admin_havn_render_date('admin_havn_til_salgs_dato', $m('admin_havn_til_salgs_dato')); echo '<p class="description">Fylles automatisk når "Ønskes solgt" settes til Ja, men kan overstyres.</p></td></tr>';

    echo '<tr><th><label>Avslutt medlemskap</label></th><td>'; admin_havn_render_select_yesno('admin_havn_avslutt_medlemskap', $m('admin_havn_avslutt_medlemskap')); echo '<p class="description">Setter medlem i Arkiv når Ja.</p></td></tr>';
    echo '<tr><th><label>Medlemskap avsluttet dato</label></th><td>'; admin_havn_render_date('admin_havn_avsluttet_dato', $m('admin_havn_avsluttet_dato')); echo '</td></tr>';

    echo '</tbody></table>';
}

function admin_havn_render_batplass_meta($post) {
    wp_nonce_field('admin_havn_save_meta', 'admin_havn_meta_nonce');
    $m = function($key) use ($post) { return get_post_meta($post->ID, $key, true); };

    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label>Båtplasskode</label></th><td>'; admin_havn_render_text('admin_havn_batplasskode_bp', $m('admin_havn_batplasskode_bp'), 'f.eks 1-27'); echo '</td></tr>';
    echo '<tr><th><label>Pir</label></th><td>'; admin_havn_render_text('admin_havn_pir', $m('admin_havn_pir'), '1'); echo '</td></tr>';
    echo '<tr><th><label>Plassnr</label></th><td>'; admin_havn_render_text('admin_havn_plassnr', $m('admin_havn_plassnr'), '27'); echo '</td></tr>';

    echo '<tr><th><label>Status</label></th><td>';
        $status = $m('admin_havn_status');
        $choices = ['Opptatt','Til salgs','Til leige','Ledig','Solgt'];
        echo '<select name="admin_havn_status">';
        foreach ($choices as $c) {
            $sel = (strcasecmp($c, $status) === 0) ? 'selected' : '';
            echo '<option value="' . esc_attr($c) . '" ' . $sel . '>' . esc_html($c) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Hvis medlem settes til "Ønskes solgt = Ja" settes status automatisk til "Til salgs".</p>';
    echo '</td></tr>';

    echo '<tr><th><label>Sperret</label></th><td>'; admin_havn_render_select_yesno('admin_havn_sperret', $m('admin_havn_sperret')); echo '</td></tr>';
    echo '<tr><th><label>Bredde (m)</label></th><td>'; admin_havn_render_text('admin_havn_bredde_m', $m('admin_havn_bredde_m'), '3'); echo '</td></tr>';
    echo '<tr><th><label>Utrigger-lengde (m)</label></th><td>'; admin_havn_render_text('admin_havn_utrigg_len_m', $m('admin_havn_utrigg_len_m'), '6'); echo '</td></tr>';
    echo '<tr><th><label>Lang utriggar</label></th><td>'; admin_havn_render_select_yesno('admin_havn_lang_utriggar', $m('admin_havn_lang_utriggar')); echo '</td></tr>';
    echo '<tr><th><label>2x gangriggar</label></th><td>'; admin_havn_render_select_yesno('admin_havn_gangriggar_2x', $m('admin_havn_gangriggar_2x')); echo '</td></tr>';
    echo '<tr><th><label>Antall kWh til fakturering</label></th><td>'; admin_havn_render_text('admin_havn_kwh_fakturering', $m('admin_havn_kwh_fakturering'), ''); echo '</td></tr>';
    echo '<tr><th><label>Notat</label></th><td><textarea name="admin_havn_notat" rows="4" class="large-text">' . esc_textarea($m('admin_havn_notat')) . '</textarea></td></tr>';
    echo '</tbody></table>';
}

function admin_havn_render_dugnad_meta($post) {
    wp_nonce_field('admin_havn_save_meta', 'admin_havn_meta_nonce');
    $m = function($key) use ($post) { return get_post_meta($post->ID, $key, true); };

    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label>Medlem</label></th><td>'; admin_havn_render_text('admin_havn_dugnad_medlem', $m('admin_havn_dugnad_medlem'), 'Navn eller e-post'); echo '</td></tr>';
    echo '<tr><th><label>Dato</label></th><td>'; admin_havn_render_date('admin_havn_dugnad_dato', $m('admin_havn_dugnad_dato')); echo '</td></tr>';
    echo '<tr><th><label>Timer</label></th><td>'; admin_havn_render_text('admin_havn_dugnad_timer', $m('admin_havn_dugnad_timer'), '2'); echo '</td></tr>';
    echo '<tr><th><label>Notat</label></th><td><textarea name="admin_havn_dugnad_notat" rows="3" class="large-text">' . esc_textarea($m('admin_havn_dugnad_notat')) . '</textarea></td></tr>';
    echo '</tbody></table>';
}

/* ============================
   SAVE META + AUTOMATION RULES
============================ */

function admin_havn_save_meta($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['admin_havn_meta_nonce']) || !wp_verify_nonce($_POST['admin_havn_meta_nonce'], 'admin_havn_save_meta')) return;

    $ptype = get_post_type($post_id);
    if (!in_array($ptype, ['medlem','batplass','dugnadstime'], true)) return;

    $upd = function($key, $value) use ($post_id) {
        if ($value === null) return;
        update_post_meta($post_id, $key, $value);
    };

    if ($ptype === 'medlem') {
        // Medlemsnr kan ikke redigeres manuelt her (settes ved import / auto-assign on save).

        $fornavn = sanitize_text_field($_POST['admin_havn_fornavn'] ?? '');
        $etternavn = sanitize_text_field($_POST['admin_havn_etternavn'] ?? '');
        $fullt = trim(($fornavn . ' ' . $etternavn));

        $upd('admin_havn_fornavn', $fornavn);
        $upd('admin_havn_etternavn', $etternavn);
        $upd('admin_havn_medlemskategori', sanitize_text_field($_POST['admin_havn_medlemskategori'] ?? ''));
        $upd('admin_havn_dugnadsplikt', sanitize_text_field($_POST['admin_havn_dugnadsplikt'] ?? ''));
        $upd('admin_havn_epost', sanitize_email($_POST['admin_havn_epost'] ?? ''));
        $upd('admin_havn_telefon', sanitize_text_field($_POST['admin_havn_telefon'] ?? ''));
        $upd('admin_havn_adresse', sanitize_text_field($_POST['admin_havn_adresse'] ?? ''));
        $upd('admin_havn_postnr', sanitize_text_field($_POST['admin_havn_postnr'] ?? ''));
        $upd('admin_havn_poststed', sanitize_text_field($_POST['admin_havn_poststed'] ?? ''));

        $kode = sanitize_text_field($_POST['admin_havn_batplasskode'] ?? '');
        $upd('admin_havn_batplasskode', $kode);

        // Kjøpt bredde (m) – tillat komma som desimal.
        $kb_raw = sanitize_text_field(wp_unslash($_POST['admin_havn_kjoept_bredde'] ?? ''));
        $kb_raw = str_replace(' ', '', $kb_raw);
        $kb_raw = str_replace(',', '.', $kb_raw);
        $kb_val = '';
        if ($kb_raw !== '' && is_numeric($kb_raw)) {
            $kb_val = rtrim(rtrim(number_format((float)$kb_raw, 3, '.', ''), '0'), '.');
        }
        $upd('admin_havn_kjoept_bredde', $kb_val);

        $onskes_solgt = sanitize_text_field($_POST['admin_havn_onskes_solgt'] ?? '');
        $til_salgs_dato = sanitize_text_field($_POST['admin_havn_til_salgs_dato'] ?? '');
        $upd('admin_havn_onskes_solgt', $onskes_solgt);

        if (strcasecmp($onskes_solgt, 'Ja') === 0 && empty($til_salgs_dato)) {
            $til_salgs_dato = current_time('Y-m-d');
        }
        $upd('admin_havn_til_salgs_dato', $til_salgs_dato);

        if (strcasecmp($onskes_solgt, 'Ja') === 0 && $kode) {
            admin_havn_set_batplass_status_by_kode($kode, 'Til salgs');
        }

        $avslutt = sanitize_text_field($_POST['admin_havn_avslutt_medlemskap'] ?? '');
        $avsluttet_dato = sanitize_text_field($_POST['admin_havn_avsluttet_dato'] ?? '');
        $upd('admin_havn_avslutt_medlemskap', $avslutt);

        if (strcasecmp($avslutt, 'Ja') === 0 && empty($avsluttet_dato)) {
            $avsluttet_dato = current_time('Y-m-d');
        }
        $upd('admin_havn_avsluttet_dato', $avsluttet_dato);

        if (!empty($fullt) && get_post_field('post_title', $post_id) !== $fullt) {
            remove_action('save_post', 'admin_havn_save_meta');
            wp_update_post(['ID' => $post_id, 'post_title' => $fullt]);
            add_action('save_post', 'admin_havn_save_meta');
        }

        if (strcasecmp($avslutt, 'Ja') === 0 && get_post_status($post_id) !== 'archived') {
            remove_action('save_post', 'admin_havn_save_meta');
            wp_update_post(['ID' => $post_id, 'post_status' => 'archived']);
            add_action('save_post', 'admin_havn_save_meta');
        }
    }

    if ($ptype === 'batplass') {
        $kode = sanitize_text_field($_POST['admin_havn_batplasskode_bp'] ?? '');
        $upd('admin_havn_batplasskode_bp', $kode);
        $upd('admin_havn_pir', sanitize_text_field($_POST['admin_havn_pir'] ?? ''));
        $upd('admin_havn_plassnr', sanitize_text_field($_POST['admin_havn_plassnr'] ?? ''));

        $old_status = get_post_meta($post_id, 'admin_havn_status', true);
        $new_status = sanitize_text_field($_POST['admin_havn_status'] ?? '');
        $upd('admin_havn_status', $new_status);

        $upd('admin_havn_sperret', sanitize_text_field($_POST['admin_havn_sperret'] ?? ''));
        $upd('admin_havn_bredde_m', sanitize_text_field($_POST['admin_havn_bredde_m'] ?? ''));
        $upd('admin_havn_utrigg_len_m', sanitize_text_field($_POST['admin_havn_utrigg_len_m'] ?? ''));
        $upd('admin_havn_lang_utriggar', sanitize_text_field($_POST['admin_havn_lang_utriggar'] ?? ''));
        $upd('admin_havn_gangriggar_2x', sanitize_text_field($_POST['admin_havn_gangriggar_2x'] ?? ''));
        $upd('admin_havn_kwh_fakturering', sanitize_text_field($_POST['admin_havn_kwh_fakturering'] ?? ''));
        $upd('admin_havn_notat', sanitize_textarea_field($_POST['admin_havn_notat'] ?? ''));

        if (!empty($kode) && get_post_field('post_title', $post_id) !== $kode) {
            remove_action('save_post', 'admin_havn_save_meta');
            wp_update_post(['ID' => $post_id, 'post_title' => $kode]);
            add_action('save_post', 'admin_havn_save_meta');
        }

        // Option C: when a boat slip is marked as "Solgt" => member becomes B (if membership isn't ended).
        if (strcasecmp($old_status, 'Solgt') !== 0 && strcasecmp($new_status, 'Solgt') === 0 && $kode) {
            admin_havn_handle_batplass_sold($kode);
        }
    }

    if ($ptype === 'dugnadstime') {
        $medlem = sanitize_text_field($_POST['admin_havn_dugnad_medlem'] ?? '');
        $dato = sanitize_text_field($_POST['admin_havn_dugnad_dato'] ?? '');
        $timer = sanitize_text_field($_POST['admin_havn_dugnad_timer'] ?? '');
        $notat = sanitize_textarea_field($_POST['admin_havn_dugnad_notat'] ?? '');

        $upd('admin_havn_dugnad_medlem', $medlem);
        $upd('admin_havn_dugnad_dato', $dato);
        $upd('admin_havn_dugnad_timer', $timer);
        $upd('admin_havn_dugnad_notat', $notat);

        $title = trim($medlem . ' - ' . ($dato ?: '') . ' - ' . ($timer ?: '') . ' t');
        if ($title && get_post_field('post_title', $post_id) !== $title) {
            remove_action('save_post', 'admin_havn_save_meta');
            wp_update_post(['ID' => $post_id, 'post_title' => $title]);
            add_action('save_post', 'admin_havn_save_meta');
        }
    }
}
add_action('save_post', 'admin_havn_save_meta');

/* ============================
   ADMIN UI: SPLIT VIEW + INLINE EDITOR (MEDLEM)
============================ */

function admin_havn_is_medlem_list_screen() {
    if (!is_admin()) return false;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) return false;
    return ($screen->base === 'edit' && $screen->post_type === 'medlem');
}

function admin_havn_is_list_screen_for($post_type) {
    if (!is_admin()) return false;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) return false;
    return ($screen->base === 'edit' && $screen->post_type === $post_type);
}

function admin_havn_status_badge_class($status) {
    $s = mb_strtolower(trim((string)$status));
    if ($s === 'ledig') return 'is-ledig';
    if ($s === 'til salgs') return 'is-tilsalgs';
    if ($s === 'til leige' || $s === 'til leie') return 'is-tilleige';
    if ($s === 'sperret') return 'is-sperret';
    if ($s === 'opptatt' || $s === 'solgt') return 'is-opptatt';
    return '';
}

function admin_havn_status_badge_html($status) {
    $status = trim((string)$status);
    if ($status === '') return '';
    $cls = admin_havn_status_badge_class($status);
    return '<span class="ah-status-badge ' . esc_attr($cls) . '">' . esc_html($status) . '</span>';
}

// Status-farger (diffuse) for Medlemmer + Båtplasser
add_action('admin_enqueue_scripts', function() {
    if (!admin_havn_is_list_screen_for('medlem') && !admin_havn_is_list_screen_for('batplass')) return;

    $css = <<<CSS
.ah-status-badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #dcdcde;background:#f6f7f7;font-size:12px;line-height:18px;white-space:nowrap}
.ah-status-badge.is-ledig{border-color:#bfe5cf;background:#e9f7ef;color:#1d6b3a}
.ah-status-badge.is-opptatt{border-color:#b8d0ff;background:#e7f0ff;color:#1e40af}
.ah-status-badge.is-tilsalgs{border-color:#ffc6a3;background:#fff1e8;color:#9a4d00}
.ah-status-badge.is-sperret{border-color:#ffd79a;background:#fff7e6;color:#8a6d1d}
.ah-status-badge.is-tilleige{border-color:#a7e7de;background:#e7fbf7;color:#0f5f57}
CSS;

    wp_register_style('admin-havn-status-badges', false);
    wp_enqueue_style('admin-havn-status-badges');
    wp_add_inline_style('admin-havn-status-badges', $css);
});


/* Fjern rad-actions (server-side) for Medlemmer-listen */
add_filter('post_row_actions', function($actions, $post){
    if ($post && $post->post_type === 'medlem') {
        unset($actions['edit']);
        unset($actions['inline hide-if-no-js']); // Hurtigrediger
        unset($actions['trash']);
        unset($actions['view']);
    }
    return $actions;
}, 10, 2);

add_action('admin_enqueue_scripts', function($hook) {
    if (!admin_havn_is_medlem_list_screen()) return;

    // CSS
    $css = <<<CSS
/* 2-kolonne layout (uten å ødelegge WP-headeren) */
body.post-type-medlem #ah-split{display:flex;gap:16px;align-items:flex-start}
body.post-type-medlem #ah-left{flex:1 1 auto;min-width:760px}
body.post-type-medlem #ah-sidepanel{flex:0 0 520px;min-width:420px;max-width:780px;position:sticky;top:32px;height:calc(100vh - 64px);background:#fff;border:1px solid #dcdcde;border-radius:10px;overflow:hidden}
#ah-sidepanel .ah-head{padding:12px 14px;border-bottom:1px solid #dcdcde;display:flex;gap:10px;align-items:flex-start;justify-content:space-between}
#ah-sidepanel .ah-title{font-size:14px;line-height:1.2;margin:0}
#ah-sidepanel .ah-meta{font-size:12px;color:#50575e;margin-top:4px}
#ah-sidepanel .ah-body{padding:10px 12px;overflow:auto;height:calc(100% - 58px)}
#ah-sidepanel .ah-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 10px}
#ah-sidepanel .ah-grid .ah-full{grid-column:1 / -1}
#ah-sidepanel label{font-weight:600;display:block;margin-bottom:2px}
#ah-sidepanel input[type=text],#ah-sidepanel input[type=email],#ah-sidepanel input[type=date],#ah-sidepanel select,#ah-sidepanel textarea{width:100%}
#ah-sidepanel input[type=text],#ah-sidepanel input[type=email],#ah-sidepanel input[type=date],#ah-sidepanel select{min-height:28px;padding-top:2px;padding-bottom:2px}
#ah-sidepanel textarea{min-height:58px}
#ah-sidepanel .ah-actions{display:flex;gap:8px;align-items:center;margin-top:12px}
#ah-sidepanel .ah-status{margin-left:auto;font-size:12px;color:#50575e}
#ah-sidepanel .ah-pill{display:inline-block;padding:2px 8px;border:1px solid #dcdcde;border-radius:999px;font-size:12px;color:#1d2327;background:#f6f7f7}

/* Skjul rad-actions (Rediger / Hurtigrediger / Papirkurv) */
body.post-type-medlem .row-actions{display:none !important}
body.post-type-medlem tr.type-medlem{cursor:pointer}
body.post-type-medlem tr.type-medlem:hover td{background:#f6f7f7}

#ah-sidepanel .ah-badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
#ah-sidepanel .ah-badge{display:inline-flex;align-items:center;gap:6px;padding:2px 8px;border-radius:999px;border:1px solid #dcdcde;background:#f6f7f7;font-size:12px}
#ah-sidepanel .ah-badge strong{font-weight:700}
#ah-sidepanel .ah-badge.is-ledig{border-color:#bfe5cf;background:#e9f7ef;color:#1d6b3a}
#ah-sidepanel .ah-badge.is-opptatt{border-color:#b8d0ff;background:#e7f0ff;color:#1e40af}
#ah-sidepanel .ah-badge.is-tilsalgs{border-color:#ffc6a3;background:#fff1e8;color:#9a4d00}
#ah-sidepanel .ah-badge.is-sperret{border-color:#ffd79a;background:#fff7e6;color:#8a6d1d}
#ah-sidepanel .ah-badge.is-tilleige{border-color:#a7e7de;background:#e7fbf7;color:#0f5f57}
#ah-sidepanel .ah-toast{display:none;margin:0 0 10px 0}
body.post-type-medlem tr.type-medlem.is-selected td{background:#e7f5ff !important}

CSS;

    wp_register_style('admin-havn-medlem-split', false);
    wp_enqueue_style('admin-havn-medlem-split');
    wp_add_inline_style('admin-havn-medlem-split', $css);

    // JS
    wp_register_script('admin-havn-medlem-split', false, ['jquery'], false, true);
    wp_enqueue_script('admin-havn-medlem-split');
    wp_add_inline_script('admin-havn-medlem-split', 'window.AdminHavnMedlem = ' . wp_json_encode([
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('admin_havn_medlem_inline'),
    ]) . ';');

    $js = <<<JS
(function($){
  function ensurePanel(){
    if($('#ah-sidepanel').length) return;

    // Bygg en egen split-container slik at WP-header (tittel, faner, søk) blir liggende normalt.
    var $wrap = $('body.post-type-medlem .wrap');
    var $form = $wrap.children('form#posts-filter');
    if(!$form.length) return;

    if(!$('#ah-split').length){
      var $split = $('<div id="ah-split"><div id="ah-left"></div></div>');
      $split.insertBefore($form);
      $form.appendTo($split.find('#ah-left'));
    }

    var panel = $(
      '<div id="ah-sidepanel">'+
        '<div class="ah-head">'+
          '<div>'+ 
            '<h3 class="ah-title" id="ah-title">Velg en medlem</h3>'+ 
            '<div class="ah-meta" id="ah-meta">Klikk på en rad for å redigere i panelet</div><div class="ah-badges" id="ah-badges" style="display:none;"><span class="ah-badge" id="ah-badge-batplass">Båtplass: <strong id="ah-batplass">—</strong></span><span class="ah-badge" id="ah-badge-status">Status: <strong id="ah-batplassstatus">—</strong></span></div>'+ 
          '</div>'+ 
          '<div style="display:flex;gap:8px;align-items:center;">'+
            '<a id="ah-open" class="button" target="_blank" style="display:none;">Åpne</a>'+
          '</div>'+
        '</div>'+
        '<div class="ah-body">'+
          '<div id="ah-empty" class="notice notice-info" style="margin:0;"><p>Velg en medlem i listen til venstre.</p></div><div id="ah-toast" class="notice notice-success ah-toast"><p id="ah-toast-msg"></p></div>'+
          '<form id="ah-form" style="display:none;">'+
            '<input type="hidden" name="post_id" value="" />'+
            '<div class="ah-grid">'+
              '<div class="ah-full"><span class="ah-pill">Medlemsnr: <strong id="ah-medlemsnr">—</strong></span></div>'+
              '<div><label>Fornavn</label><input type="text" name="admin_havn_fornavn" /></div>'+
              '<div><label>Etternavn</label><input type="text" name="admin_havn_etternavn" /></div>'+
              '<div><label>Medlemskategori</label><input type="text" name="admin_havn_medlemskategori" placeholder="A eller B" /></div>'+
              '<div><label>Dugnadsplikt</label><select name="admin_havn_dugnadsplikt"><option value="">—</option><option>Ja</option><option>Nei</option></select></div>'+
              '<div class="ah-full"><label>E-post</label><input type="email" name="admin_havn_epost" /></div>'+
              '<div><label>Telefon</label><input type="text" name="admin_havn_telefon" /></div>'+
              '<div><label>Postnr</label><input type="text" name="admin_havn_postnr" /></div>'+
              '<div class="ah-full"><label>Adresse</label><input type="text" name="admin_havn_adresse" /></div>'+
              '<div class="ah-full"><label>Poststed</label><input type="text" name="admin_havn_poststed" /></div>'+
              '<div><label>Båtplasskode</label><input type="text" name="admin_havn_batplasskode" placeholder="f.eks 1-27" /></div>'+
              '<div><label>Båtplassstatus</label><input type="text" name="_batplassstatus" readonly /></div>'+
              '<div><label>Ønskes solgt</label><select name="admin_havn_onskes_solgt"><option value="">—</option><option>Ja</option><option>Nei</option></select></div>'+
              '<div><label>Til salgs dato</label><input type="date" name="admin_havn_til_salgs_dato" /></div>'+
              '<div><label>Avslutt medlemskap</label><select name="admin_havn_avslutt_medlemskap"><option value="">—</option><option>Ja</option><option>Nei</option></select></div>'+
              '<div><label>Avsluttet dato</label><input type="date" name="admin_havn_avsluttet_dato" /></div>'+
            '</div>'+
            '<div class="ah-actions">'+
              '<button type="submit" class="button button-primary">Lagre</button>'+
              '<button type="button" id="ah-reload" class="button">Last på nytt</button>'+
              '<span class="ah-status" id="ah-status"></span>'+
            '</div>'+
          '</form>'+
        '</div>'+
      '</div>'
    );
    $('#ah-split').append(panel);
  }

  var dirty = false;
  function setStatus(msg){ $('#ah-status').text(msg||''); }
  function toast(msg, type){
    var $t = $('#ah-toast');
    $t.removeClass('notice-success notice-error notice-warning');
    $t.addClass(type || 'notice-success');
    $('#ah-toast-msg').text(msg||'');
    $t.show();
    setTimeout(function(){ $t.fadeOut(200); }, 2200);
  }
  function setBadges(batplass, status){
    $('#ah-badges').show();
    $('#ah-batplass').text(batplass||'—');
    $('#ah-batplassstatus').text(status||'—');
    var $bs = $('#ah-badge-status');
    $bs.removeClass('is-ledig is-opptatt is-tilsalgs is-sperret is-tilleige');
    var s = (status||'').toLowerCase();
    if(s === 'ledig') $bs.addClass('is-ledig');
    else if(s === 'til salgs') $bs.addClass('is-tilsalgs');
    else if(s === 'til leige' || s === 'til leie') $bs.addClass('is-tilleige');
    else if(s === 'sperret') $bs.addClass('is-sperret');
    else if(s === 'opptatt' || s === 'solgt') $bs.addClass('is-opptatt');
  }

  function loadMember(postId){
    ensurePanel();
    setStatus('Henter...');
    $('#ah-empty').hide();
    $('#ah-badges').hide();
    $('#ah-toast').hide();
    $('#ah-form').hide();
    $('#ah-open').hide();
    $.post(window.AdminHavnMedlem.ajaxUrl, {action:'admin_havn_get_medlem', nonce:window.AdminHavnMedlem.nonce, post_id:postId})
      .done(function(res){
        if(!res || !res.success){
          setStatus((res && res.data && res.data.message) ? res.data.message : 'Kunne ikke hente data');
          $('#ah-empty').show();
          return;
        }
        var d = res.data;
        $('#ah-title').text(d.full_name || ('Medlem #' + postId));
        $('#ah-meta').text('ID: ' + postId);
        setBadges(d.admin_havn_batplasskode, d.batplassstatus);
        dirty = false;
        $('#ah-medlemsnr').text(d.admin_havn_medlemsnr || '—');

        var $f = $('#ah-form');
        $f.find('input[name=post_id]').val(postId);
        $f.find('input[name=admin_havn_fornavn]').val(d.admin_havn_fornavn||'');
        $f.find('input[name=admin_havn_etternavn]').val(d.admin_havn_etternavn||'');
        $f.find('input[name=admin_havn_medlemskategori]').val(d.admin_havn_medlemskategori||'');
        $f.find('select[name=admin_havn_dugnadsplikt]').val(d.admin_havn_dugnadsplikt||'');
        $f.find('input[name=admin_havn_epost]').val(d.admin_havn_epost||'');
        $f.find('input[name=admin_havn_telefon]').val(d.admin_havn_telefon||'');
        $f.find('input[name=admin_havn_adresse]').val(d.admin_havn_adresse||'');
        $f.find('input[name=admin_havn_postnr]').val(d.admin_havn_postnr||'');
        $f.find('input[name=admin_havn_poststed]').val(d.admin_havn_poststed||'');
        $f.find('input[name=admin_havn_batplasskode]').val(d.admin_havn_batplasskode||'');
        $f.find('input[name=_batplassstatus]').val(d.batplassstatus||'');
        $f.find('select[name=admin_havn_onskes_solgt]').val(d.admin_havn_onskes_solgt||'');
        $f.find('input[name=admin_havn_til_salgs_dato]').val(d.admin_havn_til_salgs_dato||'');
        $f.find('select[name=admin_havn_avslutt_medlemskap]').val(d.admin_havn_avslutt_medlemskap||'');
        $f.find('input[name=admin_havn_avsluttet_dato]').val(d.admin_havn_avsluttet_dato||'');

        var editUrl = window.AdminHavnMedlem.ajaxUrl.replace('admin-ajax.php','post.php') + '?post=' + postId + '&action=edit';
        $('#ah-open').attr('href', editUrl).show();

        $('#ah-form').show();
        setStatus('');
      })
      .fail(function(){
        setStatus('Feil ved henting');
        $('#ah-empty').show();
      });
  }

  function updateRow(postId, data){
    var $tr = $('#post-' + postId);
    if(!$tr.length) return;
    if(data.full_name){ $tr.find('a.row-title').text(data.full_name); }
    var map = {
      'medlemsnr': data.admin_havn_medlemsnr,
      'medlemskategori': data.admin_havn_medlemskategori,
      'dugnadsplikt': data.admin_havn_dugnadsplikt,
      'epost': data.admin_havn_epost,
      'telefon': data.admin_havn_telefon,
      'batplass': data.admin_havn_batplasskode,
      'batplassstatus': data.batplassstatus,
      'onskes_solgt': data.admin_havn_onskes_solgt,
      'til_salgs_dato': data.admin_havn_til_salgs_dato,
      'avslutt': data.admin_havn_avslutt_medlemskap
    };
    Object.keys(map).forEach(function(col){
      var v = map[col];
      if(typeof v === 'undefined') return;
      $tr.find('td.'+col).text(v||'');
    });
  }

  $(function(){
    if(!$('body.post-type-medlem').length) return;
    ensurePanel();

    // HARD STOP: fang navigasjon tidlig (click/mousedown/pointerdown) slik at vi aldri navigerer bort fra listen.
// Noen tema/utvidelser navigerer på mousedown/pointerdown, så vi stopper det også.
function ahBlockNav(ev){
  try{
    if(!document.body.classList.contains('post-type-medlem')) return;
    var t = ev.target;
    if(!t) return;

    // La avkryssing fungere normalt
    if(t.matches && t.matches('input[type=checkbox]')) return;
    if(t.closest && t.closest('label')) return;

    // Finn lenker i tabell-body
    var a = t.closest ? t.closest('a') : null;
    if(!a) return;
    var table = a.closest ? a.closest('table.wp-list-table') : null;
    if(!table) return;
    if(!(a.closest && a.closest('tbody'))) return;

    // Ikke blokk sortering i thead/tfoot
    if(a.closest('thead') || a.closest('tfoot')) return;

    ev.preventDefault();
    ev.stopPropagation();
    if(ev.stopImmediatePropagation) ev.stopImmediatePropagation();
  }catch(e){}
}
['pointerdown','mousedown','click'].forEach(function(type){
  document.addEventListener(type, ahBlockNav, true);
});

// Viktig: WP-lista har lenker. Hvis de får kjøre default, blir du sendt til rediger-siden.
    // Vi stopper default og bruker panelet i stedet.
    $('table.wp-list-table').on('click', 'a', function(e){
      // Ikke blokker sortering i header/footer.
      if($(this).closest('thead, tfoot').length) return;
      // checkbox/label skal fungere
      if($(e.target).is('input[type=checkbox]') || $(e.target).closest('label').length) return;
      // Inne i selve tabell-body vil vi ikke navigere bort.
      if($(this).closest('tbody').length){
        e.preventDefault();
      }
    });

    $('table.wp-list-table tbody').on('click', 'tr.type-medlem', function(e){
      e.preventDefault();
      e.stopPropagation();
      if($(e.target).is('input[type=checkbox]') || $(e.target).closest('label').length) return;
      // Hvis klikk kom fra en lenke, stopp navigasjon.
      if($(e.target).closest('a').length){
        e.preventDefault();
      }
      var id = (this.id||'').replace('post-','');
      if(!id) return;
      if(dirty){
        if(!confirm('Du har ulagrede endringer. Vil du bytte medlem uten å lagre?')) return;
      }
      $('tr.type-medlem').removeClass('is-selected');
      $(this).addClass('is-selected');
      loadMember(id);
    });

        $('#ah-sidepanel').on('input change', '#ah-form :input', function(){
      if(!$('#ah-form').is(':visible')) return;
      if($(this).attr('name') === '_batplassstatus' || $(this).prop('readonly')) return;
      dirty = true;
      setStatus('Ulagrede endringer');
    });

$('#ah-sidepanel').on('click', '#ah-reload', function(){
      var id = $('#ah-form input[name=post_id]').val();
      if(id) loadMember(id);
    });

    $('#ah-sidepanel').on('submit', '#ah-form', function(e){
      e.preventDefault();
      var $f = $(this);
      var postId = $f.find('input[name=post_id]').val();
      if(!postId) return;
      setStatus('Lagrer...');
      var payload = $f.serializeArray();
      payload.push({name:'action', value:'admin_havn_save_medlem'});
      payload.push({name:'nonce', value:window.AdminHavnMedlem.nonce});
      $.post(window.AdminHavnMedlem.ajaxUrl, $.param(payload))
        .done(function(res){
          if(!res || !res.success){
            setStatus((res && res.data && res.data.message) ? res.data.message : 'Kunne ikke lagre');
            return;
          }
          var d = res.data;
          dirty = false;
          setStatus('Lagret');
          toast('Lagret', 'notice-success');
          $('#ah-title').text(d.full_name || ('Medlem #' + postId));
          $('#ah-meta').text('ID: ' + postId);
        setBadges(d.admin_havn_batplasskode, d.batplassstatus);
        dirty = false;
          $('#ah-medlemsnr').text(d.admin_havn_medlemsnr || '—');
          $f.find('input[name=_batplassstatus]').val(d.batplassstatus||'');
          updateRow(postId, d);
          if((d.admin_havn_avslutt_medlemskap||'').toLowerCase() === 'ja'){
            setTimeout(function(){ window.location.reload(); }, 400);
          }
        })
        .fail(function(){ setStatus('Feil ved lagring'); });
    });

    // Auto-velg første medlem ved lasting (hvis ingen valgt)
    setTimeout(function(){
      if($('#ah-form:visible').length) return;
      var $first = $('table.wp-list-table tbody tr.type-medlem').first();
      if($first.length){
        $first.addClass('is-selected');
        loadMember(($first.attr('id')||'').replace('post-',''));
      }
    }, 50);

    // Tastaturnavigasjon: opp/ned for å flytte mellom rader
    $(document).on('keydown', function(e){
      if(e.target && (/input|textarea|select/i).test(e.target.tagName)) return;
      if(e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      var $rows = $('table.wp-list-table tbody tr.type-medlem');
      if(!$rows.length) return;
      var $sel = $rows.filter('.is-selected').first();
      var idx = $sel.length ? $rows.index($sel) : -1;
      if(e.key === 'ArrowDown') idx = Math.min(idx+1, $rows.length-1);
      else idx = Math.max(idx-1, 0);
      var $n = $rows.eq(idx);
      if(!$n.length) return;
      e.preventDefault();
      if(dirty && !confirm('Du har ulagrede endringer. Vil du bytte medlem uten å lagre?')) return;
      $rows.removeClass('is-selected');
      $n.addClass('is-selected');
      loadMember(($n.attr('id')||'').replace('post-',''));
      var top = $n.offset().top - 120;
      if(top < $(window).scrollTop() || top > $(window).scrollTop() + $(window).height() - 200){
        $(window).scrollTop(top);
      }
    });

  });
})(jQuery);
JS;

    wp_add_inline_script('admin-havn-medlem-split', $js);
});

add_filter('post_row_actions', function($actions, $post) {
    if ($post && $post->post_type === 'medlem') {
        unset($actions['edit']);
        unset($actions['inline hide-if-no-js']);
        unset($actions['trash']);
        unset($actions['view']);
    }
    return $actions;
}, 10, 2);

add_action('wp_ajax_admin_havn_get_medlem', function() {
    if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Ingen tilgang']);
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'admin_havn_medlem_inline')) wp_send_json_error(['message' => 'Ugyldig nonce']);

    $post_id = absint($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error(['message' => 'Mangler post_id']);
    if (get_post_type($post_id) !== 'medlem') wp_send_json_error(['message' => 'Ugyldig type']);
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error(['message' => 'Ingen tilgang']);

    $get = fn($k) => get_post_meta($post_id, $k, true);
    $fornavn = $get('admin_havn_fornavn');
    $etternavn = $get('admin_havn_etternavn');
    $full = trim($fornavn . ' ' . $etternavn);
    $bp_info = admin_havn_get_batplass_info_by_kode($get('admin_havn_batplasskode'));
    $data = [
        'post_id' => $post_id,
        'full_name' => $full,
        'admin_havn_medlemsnr' => $get('admin_havn_medlemsnr'),
        'admin_havn_fornavn' => $fornavn,
        'admin_havn_etternavn' => $etternavn,
        'admin_havn_medlemskategori' => $get('admin_havn_medlemskategori'),
        'admin_havn_dugnadsplikt' => $get('admin_havn_dugnadsplikt'),
        'admin_havn_epost' => $get('admin_havn_epost'),
        'admin_havn_telefon' => $get('admin_havn_telefon'),
        'admin_havn_adresse' => $get('admin_havn_adresse'),
        'admin_havn_postnr' => $get('admin_havn_postnr'),
        'admin_havn_poststed' => $get('admin_havn_poststed'),
        'admin_havn_batplasskode' => $get('admin_havn_batplasskode'),
        'batplassstatus' => $bp_info['effective_status'] ?? '',
        'batplass_sperret' => $bp_info['sperret'] ?? '',
        'admin_havn_onskes_solgt' => $get('admin_havn_onskes_solgt'),
        'admin_havn_til_salgs_dato' => $get('admin_havn_til_salgs_dato'),
        'admin_havn_avslutt_medlemskap' => $get('admin_havn_avslutt_medlemskap'),
        'admin_havn_avsluttet_dato' => $get('admin_havn_avsluttet_dato'),
    ];
    wp_send_json_success($data);
});

function admin_havn_inline_save_medlem_apply($post_id, $in) {
    $upd = function($key, $value) use ($post_id) {
        update_post_meta($post_id, $key, $value);
    };

    $fornavn = sanitize_text_field($in['admin_havn_fornavn'] ?? '');
    $etternavn = sanitize_text_field($in['admin_havn_etternavn'] ?? '');
    $fullt = trim($fornavn . ' ' . $etternavn);

    $upd('admin_havn_fornavn', $fornavn);
    $upd('admin_havn_etternavn', $etternavn);
    $upd('admin_havn_medlemskategori', sanitize_text_field($in['admin_havn_medlemskategori'] ?? ''));
    $upd('admin_havn_dugnadsplikt', sanitize_text_field($in['admin_havn_dugnadsplikt'] ?? ''));
    $upd('admin_havn_epost', sanitize_email($in['admin_havn_epost'] ?? ''));
    $upd('admin_havn_telefon', sanitize_text_field($in['admin_havn_telefon'] ?? ''));
    $upd('admin_havn_adresse', sanitize_text_field($in['admin_havn_adresse'] ?? ''));
    $upd('admin_havn_postnr', sanitize_text_field($in['admin_havn_postnr'] ?? ''));
    $upd('admin_havn_poststed', sanitize_text_field($in['admin_havn_poststed'] ?? ''));

    $kode = sanitize_text_field($in['admin_havn_batplasskode'] ?? '');
    $upd('admin_havn_batplasskode', $kode);

    $onskes_solgt = sanitize_text_field($in['admin_havn_onskes_solgt'] ?? '');
    $til_salgs_dato = sanitize_text_field($in['admin_havn_til_salgs_dato'] ?? '');
    $upd('admin_havn_onskes_solgt', $onskes_solgt);
    if (strcasecmp($onskes_solgt, 'Ja') === 0 && empty($til_salgs_dato)) {
        $til_salgs_dato = current_time('Y-m-d');
    }
    $upd('admin_havn_til_salgs_dato', $til_salgs_dato);
    if (strcasecmp($onskes_solgt, 'Ja') === 0 && $kode) {
        admin_havn_set_batplass_status_by_kode($kode, 'Til salgs');
    }

    $avslutt = sanitize_text_field($in['admin_havn_avslutt_medlemskap'] ?? '');
    $avsluttet_dato = sanitize_text_field($in['admin_havn_avsluttet_dato'] ?? '');
    $upd('admin_havn_avslutt_medlemskap', $avslutt);
    if (strcasecmp($avslutt, 'Ja') === 0 && empty($avsluttet_dato)) {
        $avsluttet_dato = current_time('Y-m-d');
    }
    $upd('admin_havn_avsluttet_dato', $avsluttet_dato);

    if (!empty($fullt) && get_post_field('post_title', $post_id) !== $fullt) {
        wp_update_post(['ID' => $post_id, 'post_title' => $fullt]);
    }

    if (strcasecmp($avslutt, 'Ja') === 0 && get_post_status($post_id) !== 'archived') {
        wp_update_post(['ID' => $post_id, 'post_status' => 'archived']);
    }

    return true;
}

add_action('wp_ajax_admin_havn_save_medlem', function() {
    if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Ingen tilgang']);
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'admin_havn_medlem_inline')) wp_send_json_error(['message' => 'Ugyldig nonce']);

    $post_id = absint($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error(['message' => 'Mangler post_id']);
    if (get_post_type($post_id) !== 'medlem') wp_send_json_error(['message' => 'Ugyldig type']);
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error(['message' => 'Ingen tilgang']);

    // NB: Medlemsnr kan ikke oppdateres via inline editor.
    admin_havn_inline_save_medlem_apply($post_id, $_POST);

    // Returner oppdatert snapshot
    $get = fn($k) => get_post_meta($post_id, $k, true);
    $fornavn = $get('admin_havn_fornavn');
    $etternavn = $get('admin_havn_etternavn');
    $full = trim($fornavn . ' ' . $etternavn);

    $bp_info = admin_havn_get_batplass_info_by_kode($get('admin_havn_batplasskode'));
    $data = [
        'post_id' => $post_id,
        'full_name' => $full,
        'admin_havn_medlemsnr' => $get('admin_havn_medlemsnr'),
        'admin_havn_fornavn' => $fornavn,
        'admin_havn_etternavn' => $etternavn,
        'admin_havn_medlemskategori' => $get('admin_havn_medlemskategori'),
        'admin_havn_dugnadsplikt' => $get('admin_havn_dugnadsplikt'),
        'admin_havn_epost' => $get('admin_havn_epost'),
        'admin_havn_telefon' => $get('admin_havn_telefon'),
        'admin_havn_adresse' => $get('admin_havn_adresse'),
        'admin_havn_postnr' => $get('admin_havn_postnr'),
        'admin_havn_poststed' => $get('admin_havn_poststed'),
        'admin_havn_batplasskode' => $get('admin_havn_batplasskode'),
        'batplassstatus' => $bp_info['effective_status'] ?? '',
        'batplass_sperret' => $bp_info['sperret'] ?? '',
        'admin_havn_onskes_solgt' => $get('admin_havn_onskes_solgt'),
        'admin_havn_til_salgs_dato' => $get('admin_havn_til_salgs_dato'),
        'admin_havn_avslutt_medlemskap' => $get('admin_havn_avslutt_medlemskap'),
        'admin_havn_avsluttet_dato' => $get('admin_havn_avsluttet_dato'),
    ];
    wp_send_json_success($data);
});

/* ============================
   HELPERS: LINKING + RULES
============================ */

function admin_havn_find_batplass_by_kode($kode) {
    $kode = trim((string)$kode);
    if ($kode === '') return null;

    $q = new WP_Query([
        'post_type' => 'batplass',
        'post_status' => ['publish','archived'],
        'posts_per_page' => 1,
        'title' => $kode,
    ]);
    if (!empty($q->posts)) return $q->posts[0];

    $q = new WP_Query([
        'post_type' => 'batplass',
        'post_status' => ['publish','archived'],
        'posts_per_page' => 1,
        'meta_query' => [[
            'key' => 'admin_havn_batplasskode_bp',
            'value' => $kode,
            'compare' => '=',
        ]],
    ]);

    return !empty($q->posts) ? $q->posts[0] : null;
}

function admin_havn_set_batplass_status_by_kode($kode, $status) {
    $bp = admin_havn_find_batplass_by_kode($kode);
    if (!$bp) return;
    update_post_meta($bp->ID, 'admin_havn_status', $status);
}

function admin_havn_get_batplass_status_for_medlem($medlem_id) {
    $kode = get_post_meta($medlem_id, 'admin_havn_batplasskode', true);
    if (!$kode) return '';
    $info = admin_havn_get_batplass_info_by_kode($kode);
    return $info['effective_status'] ?? '';
}

/**
 * Returnerer statusinfo for båtplass-kode, inkl. sperret og "effective status".
 * effective_status = "Sperret" hvis sperret=Ja, ellers admin_havn_status.
 */
function admin_havn_get_batplass_info_by_kode($kode) {
    $kode = trim((string)$kode);
    if ($kode === '') return ['status' => '', 'sperret' => '', 'effective_status' => ''];

    $bp = admin_havn_find_batplass_by_kode($kode);
    if (!$bp) return ['status' => '', 'sperret' => '', 'effective_status' => ''];

    $status = (string)get_post_meta($bp->ID, 'admin_havn_status', true);
    $sperret = (string)get_post_meta($bp->ID, 'admin_havn_sperret', true);

    $effective = $status;
    if (strcasecmp(trim($sperret), 'Ja') === 0) {
        $effective = 'Sperret';
    }

    return [
        'status' => $status,
        'sperret' => $sperret,
        'effective_status' => $effective,
        'batplass_id' => $bp->ID,
    ];
}

function admin_havn_handle_batplass_sold($batplasskode) {
    $batplasskode = trim((string)$batplasskode);
    if ($batplasskode === '') return;

    $q = new WP_Query([
        'post_type' => 'medlem',
        'post_status' => ['publish','archived'],
        'posts_per_page' => 50,
        'meta_query' => [[
            'key' => 'admin_havn_batplasskode',
            'value' => $batplasskode,
            'compare' => '=',
        ]],
    ]);

    if (empty($q->posts)) return;

    foreach ($q->posts as $m) {
        $avslutt = get_post_meta($m->ID, 'admin_havn_avslutt_medlemskap', true);
        if (strcasecmp($avslutt, 'Ja') === 0) continue;

        // Option C: set medlem to B when boat place is sold (unless membership is explicitly ended).
        update_post_meta($m->ID, 'admin_havn_medlemskategori', 'B');
        update_post_meta($m->ID, 'admin_havn_batplasskode', ''); // remove active link
    }
}

/* ============================
   ADMIN LIST COLUMNS (SORTABLE)
============================ */

function admin_havn_medlem_columns($cols) {
    $new = [];
    $new['cb'] = $cols['cb'] ?? '';
    $new['title'] = 'Fullt navn';
    $new['medlemsnr'] = 'Medlemsnr';
    $new['medlemskategori'] = 'Kategori';
    $new['dugnadsplikt'] = 'Dugnadsplikt';
    $new['epost'] = 'E-post';
    $new['telefon'] = 'Telefon';
    $new['batplass'] = 'Båtplass';
    $new['batplassstatus'] = 'Båtplassstatus';
    $new['onskes_solgt'] = 'Ønskes solgt';
    $new['til_salgs_dato'] = 'Til salgs dato';
    $new['avslutt'] = 'Avsluttet';
    return $new;
}
add_filter('manage_medlem_posts_columns', 'admin_havn_medlem_columns');

function admin_havn_medlem_column_content($col, $post_id) {
    switch ($col) {
        case 'medlemsnr': echo esc_html(get_post_meta($post_id,'admin_havn_medlemsnr',true)); break;
        case 'medlemskategori': echo esc_html(get_post_meta($post_id,'admin_havn_medlemskategori',true)); break;
        case 'dugnadsplikt': echo esc_html(get_post_meta($post_id,'admin_havn_dugnadsplikt',true)); break;
        case 'epost': echo esc_html(get_post_meta($post_id,'admin_havn_epost',true)); break;
        case 'telefon': echo esc_html(get_post_meta($post_id,'admin_havn_telefon',true)); break;
        case 'batplass': echo esc_html(get_post_meta($post_id,'admin_havn_batplasskode',true)); break;
        case 'batplassstatus': echo admin_havn_status_badge_html(admin_havn_get_batplass_status_for_medlem($post_id)); break;
        case 'onskes_solgt': echo esc_html(get_post_meta($post_id,'admin_havn_onskes_solgt',true)); break;
        case 'til_salgs_dato': echo esc_html(get_post_meta($post_id,'admin_havn_til_salgs_dato',true)); break;
        case 'avslutt': echo esc_html(get_post_meta($post_id,'admin_havn_avslutt_medlemskap',true)); break;
    }
}
add_action('manage_medlem_posts_custom_column', 'admin_havn_medlem_column_content', 10, 2);

function admin_havn_medlem_sortable_columns($cols) {
    $cols['medlemsnr'] = 'medlemsnr';
    $cols['medlemskategori'] = 'medlemskategori';
    $cols['dugnadsplikt'] = 'dugnadsplikt';
    $cols['epost'] = 'epost';
    $cols['telefon'] = 'telefon';
    $cols['batplass'] = 'batplass';
    $cols['onskes_solgt'] = 'onskes_solgt';
    $cols['til_salgs_dato'] = 'til_salgs_dato';
    return $cols;
}
add_filter('manage_edit-medlem_sortable_columns', 'admin_havn_medlem_sortable_columns');

function admin_havn_batplass_columns($cols) {
    $new = [];
    $new['cb'] = $cols['cb'] ?? '';
    $new['title'] = 'Båtplasskode';
    $new['pir'] = 'Pir';
    $new['plassnr'] = 'Plassnr';
    $new['status'] = 'Status';
    $new['bredde'] = 'Bredde (m)';
    $new['utrigg'] = 'Utrigger-lengde (m)';
    $new['sperret'] = 'Sperret';
    return $new;
}
add_filter('manage_batplass_posts_columns', 'admin_havn_batplass_columns');

function admin_havn_batplass_column_content($col, $post_id) {
    switch ($col) {
        case 'pir': echo esc_html(get_post_meta($post_id,'admin_havn_pir',true)); break;
        case 'plassnr': echo esc_html(get_post_meta($post_id,'admin_havn_plassnr',true)); break;
        case 'status':
            $kode = get_post_meta($post_id, 'admin_havn_batplasskode_bp', true);
            if (!$kode) $kode = get_the_title($post_id);
            $info = admin_havn_get_batplass_info_by_kode($kode);
            echo admin_havn_status_badge_html($info['effective_status'] ?? get_post_meta($post_id,'admin_havn_status',true));
            break;
        case 'bredde': echo esc_html(get_post_meta($post_id,'admin_havn_bredde_m',true)); break;
        case 'utrigg': echo esc_html(get_post_meta($post_id,'admin_havn_utrigg_len_m',true)); break;
        case 'sperret': echo esc_html(get_post_meta($post_id,'admin_havn_sperret',true)); break;
    }
}
add_action('manage_batplass_posts_custom_column', 'admin_havn_batplass_column_content', 10, 2);

function admin_havn_pre_get_posts($query) {
    if (!is_admin() || !$query->is_main_query()) return;
    $orderby = $query->get('orderby');
    $pt = $query->get('post_type');

    $map = [
        'medlem' => [
            'medlemsnr' => 'admin_havn_medlemsnr',
            'medlemskategori' => 'admin_havn_medlemskategori',
            'dugnadsplikt' => 'admin_havn_dugnadsplikt',
            'epost' => 'admin_havn_epost',
            'telefon' => 'admin_havn_telefon',
            'batplass' => 'admin_havn_batplasskode',
            'onskes_solgt' => 'admin_havn_onskes_solgt',
            'til_salgs_dato' => 'admin_havn_til_salgs_dato',
        ],
        'batplass' => [
            'pir' => 'admin_havn_pir',
            'plassnr' => 'admin_havn_plassnr',
            'status' => 'admin_havn_status',
        ],
    ];

    if ($pt && isset($map[$pt]) && isset($map[$pt][$orderby])) {
        $query->set('meta_key', $map[$pt][$orderby]);
        $query->set('orderby', 'meta_value');
    }
}
add_action('pre_get_posts', 'admin_havn_pre_get_posts');

/* ============================
   CSV IMPORT
============================ */


/* ============================
   CSV HELPERS
============================ */

function admin_havn_detect_delimiter($sample_line) {
    $delim = ';';
    if (substr_count($sample_line, ';') < substr_count($sample_line, ',')) $delim = ',';
    if (substr_count($sample_line, "\t") > substr_count($sample_line, $delim)) $delim = "\t";
    return $delim;
}

// Normaliser header-nøkkel (trim + lowercase + fjern UTF-8 BOM).
function admin_havn_norm_header_key($key) {
    $k = trim((string)$key);
    // Fjern BOM både som bytes og som unicode-tegn.
    $k = preg_replace('/^\xEF\xBB\xBF/', '', $k);
    $k = preg_replace('/^\x{FEFF}/u', '', $k);
    return mb_strtolower(trim($k));
}

/**
 * Reads a CSV file and returns [delimiter, header(array), rows(array of arrays)].
 * Skips leading lines until it finds an expected header column (case-insensitive).
 */
function admin_havn_read_csv_with_header_seek($filepath, $expected_columns_lower) {
    $handle = fopen($filepath, 'r');
    if ($handle === false) return [null, [], []];

    // Read a sample for delimiter detection.
    $sample = '';
    $peek = fgets($handle);
    if ($peek !== false) $sample = $peek;
    rewind($handle);

    $delim = admin_havn_detect_delimiter($sample ?: ';');

    $header = [];
    $rows = [];

    // Seek header (max 50 lines).
    $max_seek = 50;
    for ($i=0; $i<$max_seek; $i++) {
        $line = fgetcsv($handle, 0, $delim);
        if ($line === false) break;

        $line = array_map('trim', (array)$line);
        if (count($line) < 1) continue;

        $lower = array_map(function($v){ return mb_strtolower(trim($v)); }, $line);

        // If the line contains any expected column, treat it as header.
        $found = false;
        foreach ($expected_columns_lower as $c) {
            if (in_array(mb_strtolower($c), $lower, true)) { $found = true; break; }
        }
        if ($found) {
            $header = $line;
            break;
        }
    }

    if (empty($header)) { fclose($handle); return [$delim, [], []]; }

    while (($row = fgetcsv($handle, 0, $delim)) !== false) {
        $row = (array)$row;
        // Skip empty lines
        if (count($row) === 1 && trim($row[0]) === '') continue;
        // Normalize length
        if (count($row) < count($header)) {
            $row = array_pad($row, count($header), '');
        } elseif (count($row) > count($header)) {
            $row = array_slice($row, 0, count($header));
        }
        $rows[] = $row;
    }

    fclose($handle);
    return [$delim, $header, $rows];
}

/**
 * Finds next medlemsnr (4 digits) based on max existing meta.
 */
function admin_havn_next_medlemsnr() {
    global $wpdb;
    $meta_key = 'admin_havn_medlemsnr';
    // Get max numeric value
    $max = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s",
        $meta_key
    ));
    $next = intval($max) + 1;
    if ($next < 1) $next = 1;
    return str_pad(strval($next), 4, '0', STR_PAD_LEFT);
}



/* ============================
   MEDLEMSNR AUTO
============================ */

function admin_havn_assign_medlemsnr_on_save($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || defined('DOING_AUTOSAVE')) return;
    if ($post->post_type !== 'medlem') return;

    $existing = get_post_meta($post_id, 'admin_havn_medlemsnr', true);
    if (empty($existing)) {
        update_post_meta($post_id, 'admin_havn_medlemsnr', admin_havn_next_medlemsnr());
    }
}
add_action('save_post', 'admin_havn_assign_medlemsnr_on_save', 10, 3);



/* ============================
   CONFIG: RENTAL RATES (per year, per object)
   Stored in option: admin_havn_rental_rates
   Each row:
   [
     'year' => 2026,
     'object' => 'boat' | 'hall' | 'havnestove' | 'slipp',
     'from_md' => 'MM-DD',
     'to_md' => 'MM-DD',
     'unit' => 'week' | 'day' | 'month',
     'rate' => 200.0,
     'min_weeks' => 8
   ]
============================ */

if (!function_exists('admin_havn_get_rental_rates')) {
    function admin_havn_get_rental_rates() {
        $rates = get_option('admin_havn_rental_rates', null);
        if (is_array($rates) && !empty($rates)) return $rates;

        // Default: current year boat rates matching existing rules (vinter/sommer/vinter), minimum 8 uker.
        $year = intval(date('Y'));
        $defaults = [
            ['year'=>$year,'object'=>'boat','from_md'=>'01-01','to_md'=>'04-30','unit'=>'week','rate'=>200,'min_weeks'=>8],
            ['year'=>$year,'object'=>'boat','from_md'=>'05-01','to_md'=>'08-31','unit'=>'week','rate'=>300,'min_weeks'=>8],
            ['year'=>$year,'object'=>'boat','from_md'=>'09-01','to_md'=>'12-31','unit'=>'week','rate'=>200,'min_weeks'=>8],

            // Hall: 50/m2 per kalender-måned (ingen minimum)
            ['year'=>$year,'object'=>'hall','from_md'=>'01-01','to_md'=>'12-31','unit'=>'month','rate'=>50,'min_weeks'=>0],

            // Havnestove: 1500 per dag
            ['year'=>$year,'object'=>'havnestove','from_md'=>'01-01','to_md'=>'12-31','unit'=>'day','rate'=>1500,'min_weeks'=>0],
        ];
        update_option('admin_havn_rental_rates', $defaults, false);
        return $defaults;
    }
}

if (!function_exists('admin_havn_validate_rental_rates')) {
    function admin_havn_validate_rental_rates($rates) {
        $errors = [];

        if (!is_array($rates)) {
            return ['Ugyldig format på leiesatser.'];
        }

        // Normalize
        $norm = [];
        foreach ($rates as $i => $r) {
            if (!is_array($r)) continue;
            $year = isset($r['year']) ? intval($r['year']) : 0;
            $object = isset($r['object']) ? sanitize_key($r['object']) : '';
            $from = isset($r['from_md']) ? trim($r['from_md']) : '';
            $to = isset($r['to_md']) ? trim($r['to_md']) : '';
            $unit = isset($r['unit']) ? sanitize_key($r['unit']) : 'week';
            $rate = isset($r['rate']) ? floatval(str_replace(',','.', $r['rate'])) : 0;
            $minw = isset($r['min_weeks']) ? intval($r['min_weeks']) : 0;

            if ($year < 2000 || $year > 2100) { $errors[] = "Rad ".($i+1).": Ugyldig år."; continue; }
            if (!in_array($object, ['boat','hall','havnestove','slipp','opplag'], true)) { $errors[] = "Rad ".($i+1).": Ugyldig objekt."; continue; }
            if (!preg_match('/^\d{2}-\d{2}$/', $from) || !preg_match('/^\d{2}-\d{2}$/', $to)) { $errors[] = "Rad ".($i+1).": Fra/Til må være MM-DD."; continue; }
            if (!in_array($unit, ['week','day','month'], true)) { $errors[] = "Rad ".($i+1).": Ugyldig enhet."; continue; }
            if ($rate < 0) { $errors[] = "Rad ".($i+1).": Sats kan ikke være negativ."; continue; }

            $norm[] = ['year'=>$year,'object'=>$object,'from_md'=>$from,'to_md'=>$to,'unit'=>$unit,'rate'=>$rate,'min_weeks'=>$minw];
        }
        // Coverage validation per (year, object)
        foreach ($grouped as $k => $rows) {
            [$year, $object] = explode('|', $k, 2);
            $year = intval($year);

            $start = new DateTime($year.'-01-01');
            $end   = new DateTime($year.'-12-31');
            $days = intval($end->diff($start)->days) + 1;
            $covered = array_fill(0, $days, 0);

            foreach ($rows as $r) {
                $fromParts = explode('-', $r['from_md']);
                $toParts   = explode('-', $r['to_md']);
                $fromDate = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, intval($fromParts[0]), intval($fromParts[1])));
                $toDate   = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, intval($toParts[0]), intval($toParts[1])));

                if (!$fromDate || !$toDate) {
                    $errors[] = "År $year ($object): Ugyldig dato i periode ".$r['from_md']." → ".$r['to_md'].".";
                    continue;
                }

                if ($toDate < $fromDate) {
                    $errors[] = "År $year ($object): Til-dato kan ikke være før fra-dato (".$r['from_md']." → ".$r['to_md']."). Bruk to rader for å dekke over årsskifte.";
                    continue;
                }

                $cursor = clone $fromDate;
                while ($cursor <= $toDate) {
                    $idx = intval($cursor->diff($start)->days);
                    if ($idx >= 0 && $idx < $days) {
                        $covered[$idx] += 1;
                    }
                    $cursor->modify('+1 day');
                }
            }

            // Overlap?
            $over = array_search(true, array_map(function($v){return $v>1;}, $covered), true);
            if ($over !== false) {
                $d = (clone $start)->modify("+$over day")->format('m-d');
                $errors[] = "År $year ($object): Overlapp i perioder rundt $d.";
            }

            // Missing?
            $miss = array_search(true, array_map(function($v){return $v<1;}, $covered), true);
            if ($miss !== false) {
                $d1 = (clone $start)->modify("+$miss day")->format('m-d');
                // find end of missing streak
                $j = $miss;
                while ($j < count($covered) && $covered[$j] < 1) $j++;
                $d2 = (clone $start)->modify("+".($j-1)." day")->format('m-d');
                $errors[] = "År $year ($object): Hull i dekning: $d1 → $d2.";
            }
        }

        return $errors;
    }
}

if (!function_exists('admin_havn_save_rental_rates')) {
    function admin_havn_save_rental_rates($rates) {
        $errors = admin_havn_validate_rental_rates($rates);
        if (!empty($errors)) return $errors;
        update_option('admin_havn_rental_rates', array_values($rates), false);
        return [];
    }
}

if (!function_exists('admin_havn_config_page')) {
    function admin_havn_config_page() {
        if (!current_user_can('manage_options')) return;

        $saved_notice = '';
        $error_notice = '';

        // Import defaults (price list + rental rates)
        if (isset($_POST['admin_havn_import_defaults'])) {
            check_admin_referer('admin_havn_config');

            $defaults_year = (int)($_POST['admin_havn_defaults_year'] ?? (int)current_time('Y'));
            if ($defaults_year < 2000 || $defaults_year > 2100) $defaults_year = (int)current_time('Y');

            // Full-year boat rental coverage (winter/summer/winter)
            $default_rates = [
                ['year' => $defaults_year, 'object' => 'Båtplass', 'from' => '01-01', 'to' => '04-30', 'unit' => 'uke', 'rate' => 200, 'min_weeks' => 8],
                ['year' => $defaults_year, 'object' => 'Båtplass', 'from' => '05-01', 'to' => '08-31', 'unit' => 'uke', 'rate' => 300, 'min_weeks' => 8],
                ['year' => $defaults_year, 'object' => 'Båtplass', 'from' => '09-01', 'to' => '12-31', 'unit' => 'uke', 'rate' => 200, 'min_weeks' => 8],

                // Hall: calendar month rate (50 per m2 per month) – booking UI comes later
                ['year' => $defaults_year, 'object' => 'Hall', 'from' => '01-01', 'to' => '12-31', 'unit' => 'måned', 'rate' => 50, 'min_weeks' => 0],
                // Hamnestove: per day
                ['year' => $defaults_year, 'object' => 'Hamnestove', 'from' => '01-01', 'to' => '12-31', 'unit' => 'dag', 'rate' => 1500, 'min_weeks' => 0],
                // Slipp (ikke-medlem) – per day / per gang (stored as day here; UI will support variants later)
                ['year' => $defaults_year, 'object' => 'Slipp m vogn', 'from' => '01-01', 'to' => '12-31', 'unit' => 'dag', 'rate' => 250, 'min_weeks' => 0],
                ['year' => $defaults_year, 'object' => 'Slipp u vogn', 'from' => '01-01', 'to' => '12-31', 'unit' => 'gang', 'rate' => 200, 'min_weeks' => 0],
            ];

            $errs = admin_havn_save_rental_rates($default_rates);
            if (!empty($errs)) {
                $error_notice = implode('<br>', array_map('esc_html', $errs));
            } else {
                // Price list settings (stored as one option for later billing modules)
                $pricelist = [
                    'year' => $defaults_year,
                    'innskot_per_bm' => 12552,
                    'formidlingsgebyr' => 2500,
                    'medlemsavgift_a' => 1000,
                    'medlemsavgift_b' => 1000,
                    'batplassavgift_per_bm' => 900,
                    'tillegg_lang_utriggar' => 250,
                    'tillegg_2x_gangriggar' => 250,
                    'dugnad_fritak_alder' => 65,
                    'dugnad_timer_a' => 10,
                    'dugnad_timer_b' => 5,
                    'dugnad_timesats_frikjop' => 200,
                    'strompris_per_kwh' => 1.8,
                    'opplagsplass_sats' => 0,
                ];
                update_option('admin_havn_pricelist', $pricelist, false);
                $saved_notice = 'Standard prisliste og leiesatser ble importert for ' . $defaults_year . '.';
            }
        }

        if (isset($_POST['admin_havn_save_config'])) {
            check_admin_referer('admin_havn_config');

            // Save pricelist fields (used by annual billing and other invoices)
            $pl = get_option('admin_havn_pricelist', []);
            if (!is_array($pl)) $pl = [];
            $pl['year'] = (int)($_POST['pl_year'] ?? ($pl['year'] ?? (int)current_time('Y')));
            $pl['innskot_per_bm'] = (float)str_replace(',', '.', (string)($_POST['pl_innskot_per_bm'] ?? ($pl['innskot_per_bm'] ?? 12552)));
            $pl['formidlingsgebyr'] = (float)str_replace(',', '.', (string)($_POST['pl_formidlingsgebyr'] ?? ($pl['formidlingsgebyr'] ?? 2500)));
            $pl['medlemsavgift_a'] = (float)str_replace(',', '.', (string)($_POST['pl_medlemsavgift_a'] ?? ($pl['medlemsavgift_a'] ?? 1000)));
            $pl['medlemsavgift_b'] = (float)str_replace(',', '.', (string)($_POST['pl_medlemsavgift_b'] ?? ($pl['medlemsavgift_b'] ?? 1000)));
            $pl['batplassavgift_per_bm'] = (float)str_replace(',', '.', (string)($_POST['pl_batplassavgift_per_bm'] ?? ($pl['batplassavgift_per_bm'] ?? 900)));
            $pl['tillegg_lang_utriggar'] = (float)str_replace(',', '.', (string)($_POST['pl_tillegg_lang_utriggar'] ?? ($pl['tillegg_lang_utriggar'] ?? 250)));
            $pl['tillegg_2x_gangriggar'] = (float)str_replace(',', '.', (string)($_POST['pl_tillegg_2x_gangriggar'] ?? ($pl['tillegg_2x_gangriggar'] ?? 250)));
            $pl['dugnad_fritak_alder'] = (int)($_POST['pl_dugnad_fritak_alder'] ?? ($pl['dugnad_fritak_alder'] ?? 65));
            $pl['dugnad_timer_a'] = (float)str_replace(',', '.', (string)($_POST['pl_dugnad_timer_a'] ?? ($pl['dugnad_timer_a'] ?? 10)));
            $pl['dugnad_timer_b'] = (float)str_replace(',', '.', (string)($_POST['pl_dugnad_timer_b'] ?? ($pl['dugnad_timer_b'] ?? 5)));
            $pl['dugnad_timesats_frikjop'] = (float)str_replace(',', '.', (string)($_POST['pl_dugnad_timesats_frikjop'] ?? ($pl['dugnad_timesats_frikjop'] ?? 200)));
            $pl['strompris_per_kwh'] = (float)str_replace(',', '.', (string)($_POST['pl_strompris_per_kwh'] ?? ($pl['strompris_per_kwh'] ?? 1.8)));
            $pl['opplagsplass_sats'] = (float)str_replace(',', '.', (string)($_POST['pl_opplagsplass_sats'] ?? ($pl['opplagsplass_sats'] ?? 0)));
            update_option('admin_havn_pricelist', $pl, false);

            $rates_json = isset($_POST['admin_havn_rates_json']) ? wp_unslash($_POST['admin_havn_rates_json']) : '';
            $rates = json_decode($rates_json, true);

            if (!is_array($rates)) {
                $error_notice = 'Kunne ikke lese tabellen. Prøv igjen.';
            } else {
                $errs = admin_havn_save_rental_rates($rates);
                if (!empty($errs)) {
                    $error_notice = implode('<br>', array_map('esc_html', $errs));
                } else {
                    $saved_notice = 'Konfigurasjon lagret.';
                }
            }
        }

        $rates = admin_havn_get_rental_rates();
        $pl = get_option('admin_havn_pricelist', []);
        if (!is_array($pl)) $pl = [];

        echo '<div class="wrap"><h1>Konfigurasjon</h1>';
        echo '<p>Her setter du leiesatser per år og periode. Systemet krever at hvert år/objekt dekker hele året uten hull/overlapp.</p>';

        if ($saved_notice) echo '<div class="notice notice-success"><p>'.esc_html($saved_notice).'</p></div>';
        if ($error_notice) echo '<div class="notice notice-error"><p>'.$error_notice.'</p></div>';

        echo '<form method="post" style="margin:14px 0; padding:12px; background:#fff; border:1px solid #ccd0d4; border-radius:8px;">';
        wp_nonce_field('admin_havn_config');
        echo '<h2 style="margin-top:0;">Importer standard prisliste</h2>';
        echo '<p>Dette fyller inn prisliste (innskot/avgifter/dugnad/strøm) og legger inn helårs leiesatser for båtplass (vinter/sommer/vinter) for valgt år.</p>';
        echo '<label>År: <input type="number" name="admin_havn_defaults_year" value="' . esc_attr((int)current_time('Y')) . '" min="2000" max="2100" style="width:90px;"></label> ';
        echo '<button type="submit" name="admin_havn_import_defaults" class="button">Importer</button>';
        echo '</form>';

        echo '<form method="post" id="admin-havn-config-form">';
        wp_nonce_field('admin_havn_config');
        echo '<input type="hidden" name="admin_havn_rates_json" id="admin_havn_rates_json" value="'.esc_attr(wp_json_encode($rates)).'">';

        echo '<h2>Prisliste</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Prisår</th><td><input type="number" name="pl_year" value="' . esc_attr((int)($pl['year'] ?? (int)current_time('Y'))) . '" min="2000" max="2100"></td></tr>';
        echo '<tr><th scope="row">Innskot per breiddemeter</th><td><input type="text" name="pl_innskot_per_bm" value="' . esc_attr($pl['innskot_per_bm'] ?? 12552) . '"> NOK</td></tr>';
        echo '<tr><th scope="row">Formidlingsgebyr ved eigarskifte</th><td><input type="text" name="pl_formidlingsgebyr" value="' . esc_attr($pl['formidlingsgebyr'] ?? 2500) . '"> NOK</td></tr>';
        echo '<tr><th scope="row">Medlemsavgift A</th><td><input type="text" name="pl_medlemsavgift_a" value="' . esc_attr($pl['medlemsavgift_a'] ?? 1000) . '"> NOK</td></tr>';
        echo '<tr><th scope="row">Medlemsavgift B</th><td><input type="text" name="pl_medlemsavgift_b" value="' . esc_attr($pl['medlemsavgift_b'] ?? 1000) . '"> NOK</td></tr>';
        echo '<tr><th scope="row">Båtplassavgift per breiddemeter</th><td><input type="text" name="pl_batplassavgift_per_bm" value="' . esc_attr($pl['batplassavgift_per_bm'] ?? 900) . '"> NOK</td></tr>';
        echo '<tr><th scope="row">Tillegg lang utriggar</th><td><input type="text" name="pl_tillegg_lang_utriggar" value="' . esc_attr($pl['tillegg_lang_utriggar'] ?? 250) . '"> NOK</td></tr>';
        echo '<tr><th scope="row">Tillegg 2x gangriggar</th><td><input type="text" name="pl_tillegg_2x_gangriggar" value="' . esc_attr($pl['tillegg_2x_gangriggar'] ?? 250) . '"> NOK</td></tr>';
        echo '<tr><th scope="row">Dugnad fritak alder</th><td><input type="number" name="pl_dugnad_fritak_alder" value="' . esc_attr((int)($pl['dugnad_fritak_alder'] ?? 65)) . '"> år</td></tr>';
        echo '<tr><th scope="row">Dugnad timer A</th><td><input type="text" name="pl_dugnad_timer_a" value="' . esc_attr($pl['dugnad_timer_a'] ?? 10) . '"> timer</td></tr>';
        echo '<tr><th scope="row">Dugnad timer B</th><td><input type="text" name="pl_dugnad_timer_b" value="' . esc_attr($pl['dugnad_timer_b'] ?? 5) . '"> timer</td></tr>';
        echo '<tr><th scope="row">Dugnad timesats frikjøp</th><td><input type="text" name="pl_dugnad_timesats_frikjop" value="' . esc_attr($pl['dugnad_timesats_frikjop'] ?? 200) . '"> NOK</td></tr>';
        echo '<tr><th scope="row">Strømpris per kWh</th><td><input type="text" name="pl_strompris_per_kwh" value="' . esc_attr($pl['strompris_per_kwh'] ?? 1.8) . '"> NOK</td></tr>';
        echo '<tr><th scope="row">Opplagsplass sats</th><td><input type="text" name="pl_opplagsplass_sats" value="' . esc_attr($pl['opplagsplass_sats'] ?? 0) . '"> NOK</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Leiesatser</h2>';

        echo '<table class="widefat fixed striped" id="ah-rates-table">';
        echo '<thead><tr>';
        echo '<th style="width:70px;">År</th>';
        echo '<th style="width:120px;">Objekt</th>';
        echo '<th style="width:90px;">Fra (MM-DD)</th>';
        echo '<th style="width:90px;">Til (MM-DD)</th>';
        echo '<th style="width:90px;">Enhet</th>';
        echo '<th style="width:110px;">Sats</th>';
        echo '<th style="width:90px;">Min uker</th>';
        echo '<th style="width:60px;"></th>';
        echo '</tr></thead><tbody></tbody></table>';

        echo '<p style="margin-top:12px;"><button type="button" class="button" id="ah-add-rate">Legg til rad</button></p>';
        echo '<p><button type="submit" name="admin_havn_save_config" class="button button-primary">Lagre</button></p>';
        echo '</form>';

        // Admin JS (safe heredoc)
        $adminJs = <<<'JS'
(function(){
  function esc(s){return String(s==null?'':s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');}
  var input = document.getElementById('admin_havn_rates_json');
  var table = document.getElementById('ah-rates-table');
  var tbody = table ? table.querySelector('tbody') : null;
  if(!input || !tbody) return;

  var data;
  try{ data = JSON.parse(input.value||'[]'); }catch(e){ data=[]; }

  function makeSelect(options, val){
    var s = document.createElement('select');
    options.forEach(function(opt){
      var o = document.createElement('option');
      o.value = opt.value; o.textContent = opt.label;
      if(String(val) === String(opt.value)) o.selected = true;
      s.appendChild(o);
    });
    return s;
  }

  function rowTemplate(r){
    var tr = document.createElement('tr');

    function td(){ var x=document.createElement('td'); tr.appendChild(x); return x; }

    var year = document.createElement('input');
    year.type='number'; year.min='2000'; year.max='2100'; year.value = r.year || (new Date()).getFullYear();
    td().appendChild(year);

    var objSel = makeSelect([
      {value:'boat', label:'Båtplass'},
      {value:'hall', label:'Hall'},
      {value:'havnestove', label:'Havnestove'},
      {value:'slipp', label:'Slipp'},
      {value:'opplag', label:'Opplag'}
    ], r.object || 'boat');
    td().appendChild(objSel);

    var from = document.createElement('input');
    from.type='text'; from.placeholder='MM-DD'; from.value = r.from_md || '01-01';
    td().appendChild(from);

    var to = document.createElement('input');
    to.type='text'; to.placeholder='MM-DD'; to.value = r.to_md || '12-31';
    td().appendChild(to);

    var unitSel = makeSelect([
      {value:'week', label:'Uke'},
      {value:'day', label:'Dag'},
      {value:'month', label:'Måned'}
    ], r.unit || 'week');
    td().appendChild(unitSel);

    var rate = document.createElement('input');
    rate.type='number'; rate.step='0.01'; rate.min='0'; rate.value = (r.rate!=null? r.rate : 0);
    td().appendChild(rate);

    var minw = document.createElement('input');
    minw.type='number'; minw.step='1'; minw.min='0'; minw.value = (r.min_weeks!=null? r.min_weeks : 0);
    td().appendChild(minw);

    var del = document.createElement('button');
    del.type='button'; del.className='button-link-delete'; del.textContent='Slett';
    del.addEventListener('click', function(){ tr.remove(); sync(); });
    td().appendChild(del);

    function sync(){ serialize(); }
    [year,objSel,from,to,unitSel,rate,minw].forEach(function(el){ el.addEventListener('input', sync); el.addEventListener('change', sync); });

    tr._get = function(){
      return {
        year: parseInt(year.value||'0',10),
        object: objSel.value,
        from_md: from.value.trim(),
        to_md: to.value.trim(),
        unit: unitSel.value,
        rate: parseFloat(rate.value||'0'),
        min_weeks: parseInt(minw.value||'0',10)
      };
    };
    return tr;
  }

  function serialize(){
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    var out = rows.map(function(tr){ return tr._get ? tr._get() : null; }).filter(Boolean);
    input.value = JSON.stringify(out);
  }

  function render(){
    tbody.innerHTML='';
    (data||[]).forEach(function(r){
      tbody.appendChild(rowTemplate(r));
    });
    serialize();
  }

  document.getElementById('ah-add-rate').addEventListener('click', function(){
    tbody.appendChild(rowTemplate({}));
    serialize();
  });

  render();
})();
JS;

        echo '<script>'.$adminJs.'</script>';
        echo '</div>';
    }
}

/* ============================
   AJAX: CALCULATE RENTAL PRICE
   - boat: store rate per week, compute per day (rate/7)
   - hall: calendar months only (must be whole months)
============================ */

add_action('wp_ajax_admin_havn_calc_rental', 'admin_havn_ajax_calc_rental');
function admin_havn_ajax_calc_rental() {
    check_ajax_referer('admin_havn_portal');

    $object = isset($_POST['object']) ? sanitize_key(wp_unslash($_POST['object'])) : 'boat';
    $from   = isset($_POST['from']) ? sanitize_text_field(wp_unslash($_POST['from'])) : '';
    $to     = isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : '';
    $year   = isset($_POST['year']) ? intval($_POST['year']) : 0;

    if (!$from || !$to) {
        wp_send_json(['ok'=>false,'error'=>'Mangler fra/til dato.']);
    }

    $fromDt = DateTime::createFromFormat('Y-m-d', $from);
    $toDt   = DateTime::createFromFormat('Y-m-d', $to);
    if (!$fromDt || !$toDt) wp_send_json(['ok'=>false,'error'=>'Ugyldig datoformat.']);

    if ($toDt < $fromDt) wp_send_json(['ok'=>false,'error'=>'Til-dato kan ikke være før fra-dato.']);

    if ($year <= 0) $year = intval($fromDt->format('Y'));

    $rates = admin_havn_get_rental_rates();
    $rows = array_values(array_filter($rates, function($r) use ($year,$object){
        return intval($r['year']) === intval($year) && $r['object'] === $object;
    }));

    if (!$rows) {
        wp_send_json(['ok'=>false,'error'=>'Mangler leiesatser for valgt år.']);
    }

    // Helper: get rate row for a date (month/day)
    $pick = function(DateTime $d) use ($rows, $year) {
        $md = $d->format('m-d');
        foreach ($rows as $r) {
            $fromParts = explode('-', $r['from_md']);
            $toParts   = explode('-', $r['to_md']);
            $fromDate = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, intval($fromParts[0]), intval($fromParts[1])));
            $toDate   = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, intval($toParts[0]), intval($toParts[1])));
            if (!$fromDate || !$toDate) continue;
            if ($toDate < $fromDate) continue; // we disallow wrap in config validation
            $cmp = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, intval($d->format('m')), intval($d->format('d'))));
            if ($cmp >= $fromDate && $cmp <= $toDate) return $r;
        }
        return null;
    };

    if ($object === 'hall') {
        // calendar months only
        $firstDay = (clone $fromDt); $firstDay->modify('first day of this month');
        $lastDay  = (clone $toDt);   $lastDay->modify('last day of this month');
        if ($fromDt->format('Y-m-d') !== $firstDay->format('Y-m-d') || $toDt->format('Y-m-d') !== $lastDay->format('Y-m-d')) {
            wp_send_json(['ok'=>false,'error'=>'Hall må leies i hele kalendermåneder (fra 1. til siste dag i måneden).']);
        }
        $months = 0;
        $cursor = new DateTime($fromDt->format('Y-m-01'));
        $endMonth = new DateTime($toDt->format('Y-m-01'));
        while ($cursor <= $endMonth) {
            $months += 1;
            $cursor->modify('+1 month');
        }
        // Find any month rate row (assume one covers full year)
        $r = $pick($fromDt);
        $rate = $r ? floatval($r['rate']) : 0;
        $total = round($months * $rate, 2);
        wp_send_json(['ok'=>true,'total'=>$total,'unit'=>'month','qty'=>$months,'breakdown'=>[
            ['label'=>$months.' mnd × '.number_format($rate,2,',',' ').' = '.number_format($total,2,',',' '), 'amount'=>$total]
        ], 'min_ok'=>true]);
    }

    // Default: boat/havnestove etc - compute per day for week rates if unit=week
    $days = intval($toDt->diff($fromDt)->days) + 1;
    $segments = [];
    $total = 0.0;

    $cursor = clone $fromDt;
    while ($cursor <= $toDt) {
        $r = $pick($cursor);
        if (!$r) {
            wp_send_json(['ok'=>false,'error'=>'Dato '.esc_html($cursor->format('Y-m-d')).' mangler sats i konfigurasjonen.']);
        }
        // group consecutive days with same rate row
        $key = $r['from_md'].'-'.$r['to_md'].'-'.$r['unit'].'-'.$r['rate'].'-'.$r['min_weeks'];
        if (!isset($segments[$key])) {
            $segments[$key] = ['r'=>$r,'days'=>0,'from'=>$cursor->format('Y-m-d'),'to'=>$cursor->format('Y-m-d')];
        }
        $segments[$key]['days'] += 1;
        $segments[$key]['to'] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }

    $breakdown = [];
    foreach ($segments as $seg) {
        $r = $seg['r'];
        $rate = floatval($r['rate']);
        $unit = $r['unit'];
        $amount = 0.0;
        if ($unit === 'week') {
            $dayRate = $rate / 7.0;
            $amount = $seg['days'] * $dayRate;
            $breakdown[] = [
                'label' => $seg['from'].' → '.$seg['to'].' ('.$seg['days'].' dager) × '.number_format($dayRate,2,',',' ').' = '.number_format($amount,2,',',' '),
                'amount' => round($amount,2)
            ];
        } elseif ($unit === 'day') {
            $amount = $seg['days'] * $rate;
            $breakdown[] = [
                'label' => $seg['from'].' → '.$seg['to'].' ('.$seg['days'].' dager) × '.number_format($rate,2,',',' ').' = '.number_format($amount,2,',',' '),
                'amount' => round($amount,2)
            ];
        } else {
            // month for non-hall not supported yet
            $amount = 0;
        }
        $total += $amount;
    }

    $total = round($total, 2);

    // Minimum weeks rule: use start-date's period row min_weeks
    $startRate = $pick($fromDt);
    $minDays = $startRate ? (intval($startRate['min_weeks']) * 7) : 0;
    $minOk = ($minDays <= 0) ? true : ($days >= $minDays);

    // Hvis perioden er kortere enn minste leietid: beregn pris som minimumsperiode.
    // Vi legger på "ekstra" dager til minDays med samme dagsats som start-perioden.
    $minApplied = false;
    $billedDays = $days;
    if (!$minOk && $minDays > 0) {
        $extraDays = $minDays - $days;
        $billedDays = $minDays;
        $minApplied = true;

        $rate = floatval($startRate['rate']);
        $unit = $startRate['unit'];
        $dayRate = ($unit === 'week') ? ($rate / 7.0) : $rate;
        $extraAmount = round($extraDays * $dayRate, 2);
        if ($extraAmount > 0) {
            $breakdown[] = [
                'label' => 'Minste leietid: +'.$extraDays.' dager × '.number_format($dayRate,2,',',' ').' = '.number_format($extraAmount,2,',',' '),
                'amount' => $extraAmount,
            ];
            $total = round($total + $extraAmount, 2);
        }
    }

    wp_send_json([
        'ok'=>true,
        'total'=>$total,
        'unit'=>'day',
        'qty'=>$days,
        'min_ok'=>$minOk,
        'min_days'=>$minDays,
        'min_applied'=>$minApplied,
        'billed_days'=>$billedDays,
        'breakdown'=>$breakdown
    ]);
}



function admin_havn_import_page() {

    echo '<div class="wrap"><h1>Importer (CSV)</h1>';

    echo '<p><strong>Importer kun CSV (ikke .xlsx).</strong> Norsk Excel bruker ofte semikolon (;) som skilletegn – det støttes. Vi støtter også komma og tab.</p>';

    echo '<h2>Format / header</h2>';

    echo '<p><strong>1. Medlemmer</strong> (minst disse kolonnene):</p>';
    echo '<code>Fornavn;Etternavn;Medlemsnr;Medlemskategori;Dugnads plikt;E-post;Telefon;Adresse;Postnr;Poststed;Båtplass;Ønskes solgt;Til salgs registrert dato;Avslutt medlemskap;Medlemskap avsluttet dato</code>';
    echo '<p class="description">"Fullt navn" blir laget automatisk (Fornavn + Etternavn) og brukes som visningsfelt. Medlemsnr er 4 siffer (f.eks. 0001). Hvis Medlemsnr mangler, settes neste ledige automatisk.</p>';

    echo '<p style="margin-top:15px;"><strong>2. Båtplasser</strong> (minst disse kolonnene):</p>';
    echo '<code>Båtplasskode;Pir;Plassnr;Status;Sperret;Bredde (m);Utrigger-lengde (m);Lang utriggar;2x gangriggar;Antall kWh til fakturering;Notat</code>';

    echo '<p style="margin-top:15px;"><strong>3. Dugnadstimer</strong> (kan komme mange linjer pr år):</p>';
    echo '<code>Medlem;Dato;Timer;Notat</code>';

    $notice = '';

    /* ============================
       HANDLE IMPORT: MEDLEMMER
    ============================ */
    if (isset($_POST['admin_havn_import_medlemmer']) && !empty($_FILES['csv_medlemmer']['tmp_name'])) {

        $file = $_FILES['csv_medlemmer']['tmp_name'];

        list($delim, $header, $rows) = admin_havn_read_csv_with_header_seek($file, ['Fornavn','Fullt navn','E-post','Epost']);
        if (empty($header)) {
            $notice = '<div class="notice notice-error"><p>Fant ikke header for Medlemmer. Sjekk at CSV inneholder riktig header-linje.</p></div>';
        } else {
            $created = 0; $updated = 0;

            foreach ($rows as $row) {

                $data = array_combine($header, $row);
                if (!$data) continue;

                // Normalize keys to lower-case
                $h = [];
                foreach ($data as $k => $v) {
                    $h[admin_havn_norm_header_key($k)] = trim((string)$v);
                }

                $fornavn = sanitize_text_field($h['fornavn'] ?? '');
                $etternavn = sanitize_text_field($h['etternavn'] ?? '');
                $fullt = sanitize_text_field($h['fullt navn'] ?? trim($fornavn . ' ' . $etternavn));
                $epost = sanitize_email($h['e-post'] ?? ($h['epost'] ?? ''));
                $medlemsnr = sanitize_text_field($h['medlemsnr'] ?? ($h['medlems nr'] ?? ($h['medlemms nr'] ?? ($h['medlemmsnr'] ?? ''))));

                $existing_id = 0;

                // Try match by medlemsnr first
                if ($medlemsnr !== '') {
                    $q = new WP_Query([
                        'post_type' => 'medlem',
                        'post_status' => ['publish','archived'],
                        'posts_per_page' => 1,
                        'meta_query' => [[
                            'key' => 'admin_havn_medlemsnr',
                            'value' => $medlemsnr,
                            'compare' => '=',
                        ]],
                    ]);
                    if (!empty($q->posts)) $existing_id = $q->posts[0]->ID;
                }

                // Then by email
                if (!$existing_id && $epost) {
                    $q = new WP_Query([
                        'post_type' => 'medlem',
                        'post_status' => ['publish','archived'],
                        'posts_per_page' => 1,
                        'meta_query' => [[
                            'key' => 'admin_havn_epost',
                            'value' => $epost,
                            'compare' => '=',
                        ]],
                    ]);
                    if (!empty($q->posts)) $existing_id = $q->posts[0]->ID;
                }

                // Then by full name (title)
                if (!$existing_id && $fullt) {
                    $q = new WP_Query([
                        'post_type' => 'medlem',
                        'post_status' => ['publish','archived'],
                        'posts_per_page' => 1,
                        'title' => $fullt,
                    ]);
                    if (!empty($q->posts)) $existing_id = $q->posts[0]->ID;
                }

                $postarr = [
                    'post_type' => 'medlem',
                    'post_title' => $fullt ?: 'Medlem',
                    'post_status' => 'publish',
                ];
                if ($existing_id) $postarr['ID'] = $existing_id;

                $mid = wp_insert_post($postarr);

                if ($mid && !is_wp_error($mid)) {
                    if ($existing_id) $updated++; else $created++;

                    // Medlemsnr: if missing, auto-assign next
                    if (empty($medlemsnr)) $medlemsnr = admin_havn_next_medlemsnr();
                    // Force 4 digits if numeric
                    if (ctype_digit($medlemsnr)) $medlemsnr = str_pad($medlemsnr, 4, '0', STR_PAD_LEFT);
                    update_post_meta($mid, 'admin_havn_medlemsnr', $medlemsnr);

                    update_post_meta($mid, 'admin_havn_fornavn', $fornavn);
                    update_post_meta($mid, 'admin_havn_etternavn', $etternavn);
                    update_post_meta($mid, 'admin_havn_medlemskategori', sanitize_text_field($h['medlemskategori'] ?? ''));
                    update_post_meta($mid, 'admin_havn_dugnadsplikt', sanitize_text_field($h['dugnads plikt'] ?? ($h['dugnadspliktig'] ?? ($h['dugnadsplikt'] ?? ''))));
                    update_post_meta($mid, 'admin_havn_epost', $epost);
                    update_post_meta($mid, 'admin_havn_telefon', sanitize_text_field($h['telefon'] ?? ''));
                    update_post_meta($mid, 'admin_havn_adresse', sanitize_text_field($h['adresse'] ?? ''));
                    update_post_meta($mid, 'admin_havn_postnr', sanitize_text_field($h['postnr'] ?? ''));
                    update_post_meta($mid, 'admin_havn_poststed', sanitize_text_field($h['poststed'] ?? ''));
                    update_post_meta($mid, 'admin_havn_batplasskode', sanitize_text_field($h['båtplass'] ?? ($h['batplass'] ?? ($h['båtplasskode'] ?? ($h['batplasskode'] ?? '')))));

                    // Kjøpt bredde (m) – valgfri kolonne i import.
                    $kb_raw = (string)($h['kjøpt bredde'] ?? ($h['kjopt bredde'] ?? ($h['kjøpt bredde (m)'] ?? ($h['kjopt bredde (m)'] ?? ''))));
                    $kb_raw = str_replace(' ', '', $kb_raw);
                    $kb_raw = str_replace(',', '.', $kb_raw);
                    $kb_val = '';
                    if ($kb_raw !== '' && is_numeric($kb_raw)) {
                        $kb_val = rtrim(rtrim(number_format((float)$kb_raw, 3, '.', ''), '0'), '.');
                    }
                    update_post_meta($mid, 'admin_havn_kjoept_bredde', $kb_val);

                    $onskes = sanitize_text_field($h['ønskes solgt'] ?? ($h['onskes solgt'] ?? ''));
                    update_post_meta($mid, 'admin_havn_onskes_solgt', $onskes);
                    $tsd = sanitize_text_field($h['til salgs registrert dato'] ?? ($h['til salgs dato'] ?? ''));
                    if (strcasecmp($onskes, 'Ja') === 0 && empty($tsd)) $tsd = current_time('Y-m-d');
                    update_post_meta($mid, 'admin_havn_til_salgs_dato', $tsd);

                    $avslutt = sanitize_text_field($h['avslutt medlemskap'] ?? ($h['medlemskap avsluttet'] ?? ''));
                    update_post_meta($mid, 'admin_havn_avslutt_medlemskap', $avslutt);
                    $ad = sanitize_text_field($h['medlemskap avsluttet dato'] ?? ($h['avsluttet dato'] ?? ''));
                    if (strcasecmp($avslutt, 'Ja') === 0 && empty($ad)) $ad = current_time('Y-m-d');
                    update_post_meta($mid, 'admin_havn_avsluttet_dato', $ad);

                    if (strcasecmp($avslutt, 'Ja') === 0) {
                        wp_update_post(['ID' => $mid, 'post_status' => 'archived']);
                    } else {
                        // Ensure active if not avsluttet
                        if (get_post_status($mid) === 'archived') {
                            wp_update_post(['ID' => $mid, 'post_status' => 'publish']);
                        }
                    }
                }
            }

            $notice = '<div class="notice notice-success"><p>Medlemmer importert: ' . intval($created) . ' opprettet, ' . intval($updated) . ' oppdatert.</p></div>';
        }
    }

    /* ============================
       HANDLE IMPORT: BÅTPLASSER
    ============================ */
    if (isset($_POST['admin_havn_import_batplasser']) && !empty($_FILES['csv_batplasser']['tmp_name'])) {

        $file = $_FILES['csv_batplasser']['tmp_name'];

                list($delim, $header, $rows) = admin_havn_read_csv_with_header_seek($file, ['Båtplasskode','Batplasskode','Pir','Plassnr','Status']);


        // Hvis header har en ekstra første kolonne "båtplass" (seksjonslabel), dropp den for korrekt mapping.
        if (!empty($header)) {
            $h0 = mb_strtolower(trim((string)$header[0]));
            $h0 = preg_replace('/^\xEF\xBB\xBF/', '', $h0);
            if (in_array($h0, ['båtplass','batplass','baatplass','batplasser'], true)) {
                array_shift($header);
                foreach ($rows as $ri => $rv) {
                    if (is_array($rv) && count($rv) > 0) {
                        array_shift($rows[$ri]);
                    }
                }
            }
        }


        // Fallback: allow CSV without header (first column looks like 1-27 etc.)
        if (empty($header)) {
            $fallback_header = ['Båtplasskode','Pir','Plassnr','Status','Sperret','Bredde (m)','Utrigger-lengde (m)','Lang utriggar','2x gangriggar','Antal KW til Fakturering'];

            $fh = fopen($file, 'r');
            if ($fh !== false) {
                // find first non-empty line to detect delimiter
                $firstLine = '';
                while (!feof($fh)) {
                    $pos = ftell($fh);
                    $line = fgets($fh);
                    if ($line === false) break;
                    if (trim($line) === '') continue;
                    $firstLine = $line;
                    fseek($fh, $pos);
                    break;
                }

                if ($firstLine !== '') {
                    $delim = admin_havn_detect_delimiter($firstLine);
                    $rows = [];
                    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
                        if (!$r) continue;
                        // skip completely empty rows
                        $allEmpty = true;
                        foreach ($r as $cell) { if (trim((string)$cell) !== '') { $allEmpty = false; break; } }
                        if ($allEmpty) continue;

                        // if this row is actually a header row, skip it
                        $firstCell = trim((string)($r[0] ?? ''));
                        if (mb_strtolower($firstCell) === 'båtplasskode' || mb_strtolower($firstCell) === 'batplasskode') {
                            continue;
                        }
                        $rows[] = $r;
                    }
                    $header = $fallback_header;
                }
                fclose($fh);
            }
        }

        if (empty($header) || empty($rows)) {
            $notice = '<div class="notice notice-error"><p>Fant ingen båtplassrader å importere. Sjekk at CSV har innhold (med eller uten header).</p></div>';
        } else {
            $created = 0; $updated = 0;

            foreach ($rows as $row) {
                $data = array_combine($header, $row);
                if (!$data) continue;

                $h = [];
                foreach ($data as $k => $v) {
                    $h[admin_havn_norm_header_key($k)] = trim((string)$v);
                }

                $kode = sanitize_text_field($h['båtplasskode'] ?? ($h['batplasskode'] ?? ''));
                if (!$kode) continue;

                $existing = admin_havn_find_batplass_by_kode($kode);

                $postarr = [
                    'post_type' => 'batplass',
                    'post_title' => $kode,
                    'post_status' => 'publish',
                ];
                if ($existing) $postarr['ID'] = $existing->ID;

                $bid = wp_insert_post($postarr);

                if ($bid && !is_wp_error($bid)) {
                    if ($existing) $updated++; else $created++;

                    update_post_meta($bid, 'admin_havn_batplasskode_bp', $kode);
                    update_post_meta($bid, 'admin_havn_pir', sanitize_text_field($h['pir'] ?? ''));
                    update_post_meta($bid, 'admin_havn_plassnr', sanitize_text_field($h['plassnr'] ?? ''));
                    update_post_meta($bid, 'admin_havn_status', sanitize_text_field($h['status'] ?? ''));
                    update_post_meta($bid, 'admin_havn_sperret', sanitize_text_field($h['sperret'] ?? ''));
                    update_post_meta($bid, 'admin_havn_bredde_m', sanitize_text_field($h['bredde (m)'] ?? ($h['bredde'] ?? '')));
                    update_post_meta($bid, 'admin_havn_utrigg_len_m', sanitize_text_field($h['utrigger-lengde (m)'] ?? ($h['uttrigger-lengde (m)'] ?? ($h['utrigg'] ?? ''))));
                    update_post_meta($bid, 'admin_havn_lang_utriggar', sanitize_text_field($h['lang utriggar'] ?? ''));
                    update_post_meta($bid, 'admin_havn_gangriggar_2x', sanitize_text_field($h['2x gangriggar'] ?? ''));
                    update_post_meta($bid, 'admin_havn_kwh_fakturering', sanitize_text_field($h['antall kwh til fakturering'] ?? ($h['antal kw til fakturering'] ?? ($h['kwh'] ?? ''))));
                    update_post_meta($bid, 'admin_havn_notat', sanitize_text_field($h['notat'] ?? ''));
                }
            }

            $notice = '<div class="notice notice-success"><p>Båtplasser importert: ' . intval($created) . ' opprettet, ' . intval($updated) . ' oppdatert.</p></div>';
        }
    }

    /* ============================
       HANDLE IMPORT: DUGNADSTIMER
    ============================ */
    if (isset($_POST['admin_havn_import_dugnad']) && !empty($_FILES['csv_dugnad']['tmp_name'])) {

        $file = $_FILES['csv_dugnad']['tmp_name'];

        list($delim, $header, $rows) = admin_havn_read_csv_with_header_seek($file, ['Medlem','Dato','Timer']);
        if (empty($header)) {
            $notice = '<div class="notice notice-error"><p>Fant ikke header for Dugnadstimer. Sjekk at CSV inneholder riktig header-linje.</p></div>';
        } else {
            $created = 0;

            foreach ($rows as $row) {
                $data = array_combine($header, $row);
                if (!$data) continue;

                $h = [];
                foreach ($data as $k => $v) {
                    $h[admin_havn_norm_header_key($k)] = trim((string)$v);
                }

                $medlem = sanitize_text_field($h['medlem'] ?? '');
                $dato = sanitize_text_field($h['dato'] ?? '');
                $timer = sanitize_text_field($h['timer'] ?? '');
                $notat = sanitize_text_field($h['notat'] ?? '');

                if ($medlem === '' && $timer === '') continue;

                $title = trim($medlem . ' - ' . ($dato ?: current_time('Y-m-d')) . ' - ' . $timer . ' t');
                $did = wp_insert_post([
                    'post_type' => 'dugnadstime',
                    'post_title' => $title ?: 'Dugnad',
                    'post_status' => 'publish',
                ]);

                if ($did && !is_wp_error($did)) {
                    $created++;
                    update_post_meta($did, 'admin_havn_dugnad_medlem', $medlem);
                    update_post_meta($did, 'admin_havn_dugnad_dato', $dato);
                    update_post_meta($did, 'admin_havn_dugnad_timer', $timer);
                    update_post_meta($did, 'admin_havn_dugnad_notat', $notat);
                }
            }

            $notice = '<div class="notice notice-success"><p>Dugnadstimer importert: ' . intval($created) . ' linjer.</p></div>';
        }
    }

    if ($notice) echo $notice;

    echo '<hr style="margin:20px 0;">';

    echo '<h2>Importer filer</h2>';

    // Medlemmer form
    echo '<h3>1) Medlemmer</h3>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<input type="file" name="csv_medlemmer" accept=".csv,text/csv" required /> ';
    echo '<button type="submit" name="admin_havn_import_medlemmer" class="button button-primary">Importer Medlemmer</button>';
    echo '</form>';

    // Båtplasser form
    echo '<h3 style="margin-top:18px;">2) Båtplasser</h3>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<input type="file" name="csv_batplasser" accept=".csv,text/csv" required /> ';
    echo '<button type="submit" name="admin_havn_import_batplasser" class="button button-primary">Importer Båtplasser</button>';
    echo '</form>';

    // Dugnad form
    echo '<h3 style="margin-top:18px;">3) Dugnadstimer</h3>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<input type="file" name="csv_dugnad" accept=".csv,text/csv" required /> ';
    echo '<button type="submit" name="admin_havn_import_dugnad" class="button button-primary">Importer Dugnadstimer</button>';
    echo '</form>';

    echo '</div>';
}

require_once plugin_dir_path(__FILE__) . 'modules/havnekart/havnekart.php';
require_once plugin_dir_path(__FILE__) . 'modules/havnekart/havnekart-admin.php';
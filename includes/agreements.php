<?php
if (!defined('ABSPATH')) exit;

// Agreement/Invoice tables
function admin_havn_install_db() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $agreements = $wpdb->prefix . 'ah_agreement';
    $lines      = $wpdb->prefix . 'ah_agreement_line';
    $series     = $wpdb->prefix . 'ah_number_series';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql1 = "CREATE TABLE $agreements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        agreement_no BIGINT UNSIGNED NOT NULL,
        type VARCHAR(60) NOT NULL,
        customer_type VARCHAR(20) NOT NULL,
        member_id BIGINT UNSIGNED NULL,
        external_name VARCHAR(190) NULL,
        external_email VARCHAR(190) NULL,
        external_phone VARCHAR(50) NULL,
        external_address VARCHAR(255) NULL,
        source_type VARCHAR(60) NULL,
        source_id BIGINT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'generated',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        due_date DATE NULL,
        sent_date DATE NULL,
        paid_date DATE NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'NOK',
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        meta LONGTEXT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY agreement_no (agreement_no),
        KEY type (type),
        KEY customer_type (customer_type),
        KEY member_id (member_id),
        KEY status (status)
    ) $charset_collate;";

    $sql2 = "CREATE TABLE $lines (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        agreement_id BIGINT UNSIGNED NOT NULL,
        description VARCHAR(255) NOT NULL,
        qty DECIMAL(12,2) NOT NULL DEFAULT 1,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        meta LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY agreement_id (agreement_id)
    ) $charset_collate;";

    $sql3 = "CREATE TABLE $series (
        series_key VARCHAR(60) NOT NULL,
        next_no BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY(series_key)
    ) $charset_collate;";

    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);

    // Single global series: agreement_no == invoice_no, starts at 90000
    $exists = $wpdb->get_var($wpdb->prepare("SELECT next_no FROM $series WHERE series_key=%s", 'agreement'));
    if ($exists === null) {
        $wpdb->insert($series, ['series_key' => 'agreement', 'next_no' => 90000], ['%s','%d']);
    }
}

function admin_havn_next_agreement_no() {
    global $wpdb;
    $series = $wpdb->prefix . 'ah_number_series';

    // Atomic increment then read previous value
    $wpdb->query($wpdb->prepare("UPDATE $series SET next_no = next_no + 1 WHERE series_key=%s", 'agreement'));
    $no = $wpdb->get_var($wpdb->prepare("SELECT next_no - 1 FROM $series WHERE series_key=%s", 'agreement'));
    return intval($no);
}

function admin_havn_create_agreement($args, $lines) {
    global $wpdb;
    $agreements = $wpdb->prefix . 'ah_agreement';
    $aline      = $wpdb->prefix . 'ah_agreement_line';

    $agreement_no = admin_havn_next_agreement_no();

    $defaults = [
        'type' => 'unknown',
        'customer_type' => 'external',
        'member_id' => null,
        'external_name' => null,
        'external_email' => null,
        'external_phone' => null,
        'external_address' => null,
        'source_type' => null,
        'source_id' => null,
        'status' => 'generated',
        'due_date' => null,
        'meta' => null,
    ];
    $a = array_merge($defaults, $args);

    $total = 0.0;
    foreach ($lines as $ln) {
        $total += floatval($ln['amount']);
    }

    $wpdb->insert($agreements, [
        'agreement_no' => $agreement_no,
        'type' => $a['type'],
        'customer_type' => $a['customer_type'],
        'member_id' => $a['member_id'],
        'external_name' => $a['external_name'],
        'external_email' => $a['external_email'],
        'external_phone' => $a['external_phone'],
        'external_address' => $a['external_address'],
        'source_type' => $a['source_type'],
        'source_id' => $a['source_id'],
        'status' => $a['status'],
        'due_date' => $a['due_date'],
        'total' => $total,
        'meta' => $a['meta'] ? wp_json_encode($a['meta']) : null,
    ]);

    $agreement_id = intval($wpdb->insert_id);

    $i = 0;
    foreach ($lines as $ln) {
        $wpdb->insert($aline, [
            'agreement_id' => $agreement_id,
            'description' => (string)$ln['description'],
            'qty' => floatval($ln['qty']),
            'unit_price' => floatval($ln['unit_price']),
            'amount' => floatval($ln['amount']),
            'sort_order' => $i++,
            'meta' => isset($ln['meta']) ? wp_json_encode($ln['meta']) : null,
        ]);
    }

    return [
        'agreement_id' => $agreement_id,
        'agreement_no' => $agreement_no,
        'total' => $total,
    ];
}

function admin_havn_get_agreement_by_no($agreement_no) {
    global $wpdb;
    $agreements = $wpdb->prefix . 'ah_agreement';
    $lines      = $wpdb->prefix . 'ah_agreement_line';

    $a = $wpdb->get_row($wpdb->prepare("SELECT * FROM $agreements WHERE agreement_no=%d", $agreement_no), ARRAY_A);
    if (!$a) return null;
    $a['lines'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM $lines WHERE agreement_id=%d ORDER BY sort_order ASC", intval($a['id'])), ARRAY_A);
    return $a;
}

function admin_havn_list_agreements($limit = 200) {
    global $wpdb;
    $agreements = $wpdb->prefix . 'ah_agreement';
    $limit = max(1, min(500, intval($limit)));
    return $wpdb->get_results("SELECT agreement_no,type,customer_type,member_id,external_name,status,total,created_at FROM $agreements ORDER BY agreement_no DESC LIMIT $limit", ARRAY_A);
}

function admin_havn_update_agreement_status($agreement_no, $status) {
    global $wpdb;
    $agreements = $wpdb->prefix . 'ah_agreement';
    $allowed = ['draft','generated','sent','paid','void','archived'];
    if (!in_array($status, $allowed, true)) return false;

    $data = ['status' => $status];
    if ($status === 'sent') $data['sent_date'] = current_time('Y-m-d');
    if ($status === 'paid') $data['paid_date'] = current_time('Y-m-d');

    return (bool)$wpdb->update($agreements, $data, ['agreement_no' => intval($agreement_no)]);
}


// Ensure tables exist even after updates (safe dbDelta)
add_action('plugins_loaded', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'ah_agreement';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) {
        admin_havn_install_db();
    }
});


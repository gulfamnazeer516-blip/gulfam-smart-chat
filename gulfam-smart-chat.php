<?php
/**
 * Plugin Name: Gulfam Smart WhatsApp Chat & Lead Capture
 * Description: Configurable WhatsApp chat widget with optional lead capture and an admin lead inbox.
 * Version: 1.3.0
 * Author: Muhammad Gulfam
 * License: GPL-2.0-or-later
 * Text Domain: gulfam-smart-chat
 */
if (!defined('ABSPATH')) exit;

define('GSC_VERSION', '1.3.0');
define('GSC_URL', plugin_dir_url(__FILE__));

function gsc_install_database() {
    global $wpdb;
    $table = $wpdb->prefix . 'gsc_leads';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(150) NOT NULL,
        email varchar(190) NOT NULL,
        phone varchar(25) NOT NULL DEFAULT '',
        message text NOT NULL,
        admin_note text NOT NULL,
        contact_status varchar(30) NOT NULL DEFAULT 'not_contacted',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id)
    ) $charset;");
    update_option('gsc_db_version', GSC_VERSION);
}
register_activation_hook(__FILE__, 'gsc_install_database');
add_action('plugins_loaded', function () {
    if (get_option('gsc_db_version') !== GSC_VERSION) gsc_install_database();
});

function gsc_settings() {
    return wp_parse_args(get_option('gsc_settings', []), [
        'enabled' => '1', 'phone' => '', 'title' => 'Chat with us',
        'welcome' => 'Hi! How can we help you?', 'button' => 'Chat on WhatsApp',
        'position' => 'right', 'color' => '#25D366', 'lead_form' => '1'
    ]);
}
add_action('admin_menu', function () {
    add_menu_page('Gulfam Smart Chat', 'Gulfam Smart Chat', 'manage_options', 'gsc-settings', 'gsc_admin_settings', 'dashicons-format-chat', 81);
    add_submenu_page('gsc-settings', 'Leads', 'Leads', 'manage_options', 'gsc-leads', 'gsc_admin_leads');
});
add_action('admin_init', function () {
    register_setting('gsc_group', 'gsc_settings', ['type' => 'array', 'sanitize_callback' => 'gsc_sanitize']);
});
function gsc_sanitize($input) {
    $input = is_array($input) ? $input : [];
    $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
    return [
        'enabled' => !empty($input['enabled']) ? '1' : '0',
        'phone' => substr($phone, 0, 18),
        'title' => sanitize_text_field($input['title'] ?? ''),
        'welcome' => sanitize_textarea_field($input['welcome'] ?? ''),
        'button' => sanitize_text_field($input['button'] ?? ''),
        'position' => ($input['position'] ?? '') === 'left' ? 'left' : 'right',
        'color' => sanitize_hex_color($input['color'] ?? '') ?: '#25D366',
        'lead_form' => !empty($input['lead_form']) ? '1' : '0'
    ];
}
function gsc_admin_settings() {
    if (!current_user_can('manage_options')) return;
    $s = gsc_settings(); ?>
    <div class="wrap"><h1>Gulfam Smart Chat — Settings</h1><p>Enter a WhatsApp number with country code, without + or spaces (example: 923001234567).</p>
    <form method="post" action="options.php"><?php settings_fields('gsc_group'); ?>
    <table class="form-table" role="presentation">
    <?php
    $fields = [
        'phone' => ['WhatsApp number', 'text'], 'title' => ['Widget title', 'text'],
        'welcome' => ['Welcome message', 'textarea'], 'button' => ['Button label', 'text'],
        'color' => ['Accent color', 'color']
    ];
    foreach ($fields as $key => $f) {
        echo '<tr><th scope="row"><label for="gsc_' . esc_attr($key) . '">' . esc_html($f[0]) . '</label></th><td>';
        if ($f[1] === 'textarea') echo '<textarea class="large-text" rows="3" id="gsc_' . esc_attr($key) . '" name="gsc_settings[' . esc_attr($key) . ']">' . esc_textarea($s[$key]) . '</textarea>';
        else echo '<input class="regular-text" type="' . esc_attr($f[1]) . '" id="gsc_' . esc_attr($key) . '" name="gsc_settings[' . esc_attr($key) . ']" value="' . esc_attr($s[$key]) . '">';
        echo '</td></tr>';
    } ?>
    <tr><th>Position</th><td><select name="gsc_settings[position]"><option value="right" <?php selected($s['position'], 'right'); ?>>Right</option><option value="left" <?php selected($s['position'], 'left'); ?>>Left</option></select></td></tr>
    <tr><th>Options</th><td><label><input type="checkbox" name="gsc_settings[enabled]" value="1" <?php checked($s['enabled'], '1'); ?>> Enable widget</label><br><label><input type="checkbox" name="gsc_settings[lead_form]" value="1" <?php checked($s['lead_form'], '1'); ?>> Enable lead form</label></td></tr>
    </table><?php submit_button(); ?></form></div>
<?php }
function gsc_admin_leads() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'gsc_leads';
    $page = max(1, absint($_GET['paged'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $leads = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset));
    $statuses = ['not_contacted' => 'Not Contacted', 'contacted' => 'Contacted', 'follow_up' => 'Follow-up Required'];
    echo '<div class="wrap"><h1>Captured Leads</h1><p>Click a phone number to open WhatsApp or an email to compose in Gmail. Save private notes and follow-up status for each lead.</p>';
    if (isset($_GET['gsc_notice'])) {
        $notice = sanitize_key(wp_unslash($_GET['gsc_notice']));
        $labels = ['saved' => 'Lead updated.', 'deleted' => 'Lead deleted.', 'error' => 'Could not update the lead.'];
        if (isset($labels[$notice])) echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($labels[$notice]) . '</p></div>';
    }
    echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Date</th><th>Name</th><th>Email</th><th>Phone / WhatsApp</th><th>Message</th><th>Status &amp; Admin Note</th><th>Delete</th></tr></thead><tbody>';
    foreach ($leads as $lead) {
        $id = absint($lead->id);
        $digits = preg_replace('/\D+/', '', (string) $lead->phone);
        // Local-format numbers cannot be reliably converted to international WhatsApp numbers without a country code.
        $wa = strlen($digits) >= 8 ? 'https://wa.me/' . $digits : '';
        $gmail = 'https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode($lead->email);
        $form_id = 'gsc-lead-' . $id;
        echo '<tr><td>' . esc_html($lead->created_at) . '</td><td>' . esc_html($lead->name) . '</td>';
        echo '<td><a target="_blank" rel="noopener noreferrer" href="' . esc_url($gmail) . '">' . esc_html($lead->email) . '</a></td>';
        echo '<td>' . ($wa ? '<a target="_blank" rel="noopener noreferrer" href="' . esc_url($wa) . '">' . esc_html($lead->phone) . '</a>' : esc_html($lead->phone)) . '</td>';
        echo '<td>' . nl2br(esc_html($lead->message)) . '</td>';
        echo '<td><form id="' . esc_attr($form_id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="gsc_save_lead"><input type="hidden" name="lead_id" value="' . $id . '">';
        wp_nonce_field('gsc_edit_lead_' . $id);
        echo '<label class="screen-reader-text" for="gsc-status-' . $id . '">Contact status</label><select id="gsc-status-' . $id . '" name="contact_status">';
        foreach ($statuses as $value => $label) echo '<option value="' . esc_attr($value) . '" ' . selected($lead->contact_status, $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select><p><label class="screen-reader-text" for="gsc-note-' . $id . '">Admin note</label><textarea id="gsc-note-' . $id . '" name="admin_note" rows="3" maxlength="5000" placeholder="Private admin note..." style="width:100%;min-width:180px">' . esc_textarea($lead->admin_note) . '</textarea></p><button class="button button-primary" type="submit">Save Note &amp; Status</button></form></td>';
        echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Permanently delete this lead?\');"><input type="hidden" name="action" value="gsc_delete_lead"><input type="hidden" name="lead_id" value="' . $id . '">';
        wp_nonce_field('gsc_delete_lead_' . $id);
        echo '<button type="submit" class="button button-link-delete">Delete</button></form></td></tr>';
    }
    if (!$leads) echo '<tr><td colspan="7">No leads yet.</td></tr>';
    echo '</tbody></table></div>';
    echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => max(1, (int) ceil($count / $limit))]);
    echo '<p><em>WhatsApp requires international-format numbers, such as 923001234567. Local numbers beginning with 0 may not open the correct chat.</em></p></div>';
}
function gsc_handle_lead_action() {
    if (!current_user_can('manage_options')) wp_die('Permission denied.', 403);
    $id = absint($_POST['lead_id'] ?? 0);
    $action = sanitize_key(wp_unslash($_POST['action'] ?? ''));
    if (!$id || !in_array($action, ['gsc_save_lead', 'gsc_delete_lead'], true)) wp_die('Invalid request.', 400);
    check_admin_referer(($action === 'gsc_save_lead' ? 'gsc_edit_lead_' : 'gsc_delete_lead_') . $id);
    global $wpdb;
    $table = $wpdb->prefix . 'gsc_leads';
    if ($action === 'gsc_delete_lead') {
        $result = $wpdb->delete($table, ['id' => $id], ['%d']);
        $notice = $result === false ? 'error' : 'deleted';
    } else {
        $statuses = ['not_contacted', 'contacted', 'follow_up'];
        $status = sanitize_key(wp_unslash($_POST['contact_status'] ?? 'not_contacted'));
        if (!in_array($status, $statuses, true)) $status = 'not_contacted';
        $note = sanitize_textarea_field(wp_unslash($_POST['admin_note'] ?? ''));
        $note = function_exists('mb_substr') ? mb_substr($note, 0, 5000) : substr($note, 0, 5000);
        $result = $wpdb->update($table, ['contact_status' => $status, 'admin_note' => $note], ['id' => $id], ['%s', '%s'], ['%d']);
        $notice = $result === false ? 'error' : 'saved';
    }
    wp_safe_redirect(add_query_arg('gsc_notice', $notice, admin_url('admin.php?page=gsc-leads')));
    exit;
}
add_action('admin_post_gsc_save_lead', 'gsc_handle_lead_action');
add_action('admin_post_gsc_delete_lead', 'gsc_handle_lead_action');
add_action('wp_enqueue_scripts', function () {
    $s = gsc_settings();
    if ($s['enabled'] !== '1' || !$s['phone']) return;
    wp_enqueue_style('gsc-style', GSC_URL . 'assets/css/widget.css', [], GSC_VERSION);
    wp_enqueue_script('gsc-widget', GSC_URL . 'assets/js/widget.js', [], GSC_VERSION, true);
    wp_localize_script('gsc-widget', 'gscConfig', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('gsc_lead'), 'success' => 'Thank you! Your message was saved.', 'error' => 'Could not save your message. Please try again.']);
});
add_action('wp_footer', function () {
    $s = gsc_settings();
    if ($s['enabled'] !== '1' || !$s['phone']) return;
    $wa = 'https://wa.me/' . rawurlencode($s['phone']) . '?text=' . rawurlencode($s['welcome']);
    ?>
    <div id="gsc-widget" class="gsc-<?php echo esc_attr($s['position']); ?>" style="--gsc-color:<?php echo esc_attr($s['color']); ?>">
        <section class="gsc-panel" id="gsc-panel" aria-label="WhatsApp chat" hidden>
            <div class="gsc-header"><strong><?php echo esc_html($s['title']); ?></strong><div class="gsc-window-actions"><button type="button" class="gsc-minimize" aria-label="Minimize chat" title="Minimize">&#8722;</button><button type="button" class="gsc-fullscreen" aria-label="Expand chat to fullscreen" title="Fullscreen" aria-pressed="false">&#9974;</button><button type="button" class="gsc-close" aria-label="Close chat" title="Close">&times;</button></div></div>
            <div class="gsc-body"><p><?php echo esc_html($s['welcome']); ?></p>
            <a class="gsc-wa" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($s['button']); ?> ↗</a>
            <?php if ($s['lead_form'] === '1'): ?>
                <form class="gsc-form"><h3>Leave a message</h3>
                    <label>Name <input type="text" name="name" maxlength="150" required autocomplete="name"></label>
                    <label>Email <input type="email" name="email" maxlength="190" required autocomplete="email"></label>
                    <label>Phone number <input type="tel" name="phone" maxlength="25" required autocomplete="tel" placeholder="e.g. +92 300 1234567"></label>
                    <label>Message <textarea name="message" maxlength="3000" required rows="3"></textarea></label>
                    <label class="gsc-consent"><input type="checkbox" name="consent" required> I agree to have this information stored so I can be contacted.</label>
                    <button type="submit">Send message</button><p class="gsc-status" role="status" aria-live="polite"></p>
                </form>
            <?php endif; ?></div>
        </section>
        <button type="button" class="gsc-toggle" aria-controls="gsc-panel" aria-expanded="false" aria-label="Open WhatsApp chat"><span aria-hidden="true">✆</span><span>Chat</span></button>
    </div>
    <?php
});
add_action('wp_ajax_gsc_submit', 'gsc_submit');
add_action('wp_ajax_nopriv_gsc_submit', 'gsc_submit');
function gsc_submit() {
    if (!check_ajax_referer('gsc_lead', 'nonce', false)) wp_send_json_error(['message' => 'Invalid request.'], 403);
    $s = gsc_settings();
    if ($s['enabled'] !== '1' || $s['lead_form'] !== '1' || !$s['phone']) wp_send_json_error(['message' => 'Form disabled.'], 403);
    if (empty($_POST['consent'])) wp_send_json_error(['message' => 'Consent is required.'], 400);
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
    $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
    if (!$name || strlen($name) > 150 || !is_email($email) || !preg_match('/^\+?[0-9][0-9\s().-]{6,23}$/', $phone) || !$message || strlen($message) > 3000) wp_send_json_error(['message' => 'Please enter valid details.'], 400);
    global $wpdb;
    $saved = $wpdb->insert($wpdb->prefix . 'gsc_leads', ['name' => $name, 'email' => $email, 'phone' => $phone, 'message' => $message, 'created_at' => current_time('mysql')], ['%s', '%s', '%s', '%s', '%s']);
    if (!$saved) wp_send_json_error(['message' => 'Unable to save.'], 500);
    wp_send_json_success(['message' => 'Message saved.']);
}

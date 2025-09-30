<?php
// file: includes/customers/WarrantyClaims.php
namespace ARM\Customer;

if (!defined('ABSPATH')) exit;

/**
 * Customer-facing warranty claims via shortcode.
 * Why: show only the logged-in user's claims; safe replies with schema-tolerant storage.
 */
final class WarrantyClaims
{
    public static function boot(): void
    {
        add_shortcode('arm_warranty_claims', [__CLASS__, 'shortcode']);
    }

    public static function shortcode($atts): string
    {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to view warranty claims.', 'arm-repair-estimates') . '</p>';
        }

        $u = wp_get_current_user();
        $email = (string) $u->user_email;
        if ($email === '') {
            return '<p>' . esc_html__('Your account is missing an email address.', 'arm-repair-estimates') . '</p>';
        }

        global $wpdb;
        $tbl_claims = $wpdb->prefix . 'arm_warranty_claims';
        $tbl_msgs   = $wpdb->prefix . 'arm_warranty_claim_messages';

        // Handle reply postback
        if (!empty($_POST['arm_claim_nonce']) && wp_verify_nonce((string) $_POST['arm_claim_nonce'], 'arm_claim_reply')) {
            $claim_id = (int) ($_GET['claim_id'] ?? 0);
            $message  = trim((string) ($_POST['reply_message'] ?? ''));
            if ($claim_id > 0 && $message !== '') {
                // Ensure ownership by email
                $owned = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl_claims WHERE id=%d AND email=%s", $claim_id, $email));
                if ($owned > 0) {
                    // Prefer messages table; else, update claim last_message/updated_at if columns exist
                    $has_msgs_table = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name=%s", $tbl_msgs
                    )) > 0;

                    if ($has_msgs_table) {
                        $wpdb->insert($tbl_msgs, [
                            'claim_id'   => $claim_id,
                            'actor'      => 'customer',
                            'message'    => wp_kses_post($message),
                            'created_at' => current_time('mysql'),
                        ], ['%d','%s','%s','%s']);
                    } else {
                        // Schema-tolerant update
                        $cols = array_map('strtolower', $wpdb->get_col(
                            $wpdb->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=%s", $tbl_claims)
                        ) ?: []);
                        $data = [];
                        $fmt  = [];
                        if (in_array('last_message', $cols, true)) {
                            $data['last_message'] = wp_kses_post($message); $fmt[] = '%s';
                        }
                        if (in_array('updated_at', $cols, true)) {
                            $data['updated_at'] = current_time('mysql'); $fmt[] = '%s';
                        }
                        if ($data) {
                            $wpdb->update($tbl_claims, $data, ['id' => $claim_id], $fmt, ['%d']);
                        }
                    }
                    echo '<div class="notice notice-success"><p>' . esc_html__('Your reply was sent.', 'arm-repair-estimates') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('You cannot reply to this claim.', 'arm-repair-estimates') . '</p></div>';
                }
            }
        }

        $claim_id = (int) ($_GET['claim_id'] ?? 0);
        if ($claim_id > 0) {
            return self::render_detail($claim_id, $email);
        }
        return self::render_list($email);
    }

    private static function render_list(string $email): string
    {
        global $wpdb;
        $tbl = $wpdb->prefix . 'arm_warranty_claims';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, invoice_id, subject, status, created_at FROM $tbl WHERE email=%s ORDER BY created_at DESC",
            $email
        ));

        ob_start();
        ?>
        <div class="arm-warranty-claims" style="max-width:900px;margin:1rem auto;">
          <h2><?php echo esc_html__('My Warranty Claims', 'arm-repair-estimates'); ?></h2>
          <table class="widefat striped">
            <thead>
              <tr>
                <th><?php esc_html_e('ID', 'arm-repair-estimates'); ?></th>
                <th><?php esc_html_e('Invoice', 'arm-repair-estimates'); ?></th>
                <th><?php esc_html_e('Subject', 'arm-repair-estimates'); ?></th>
                <th><?php esc_html_e('Status', 'arm-repair-estimates'); ?></th>
                <th><?php esc_html_e('Created', 'arm-repair-estimates'); ?></th>
                <th><?php esc_html_e('Action', 'arm-repair-estimates'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($rows): foreach ($rows as $r): 
                  $url = add_query_arg('claim_id', (int) $r->id, get_permalink());
              ?>
                <tr>
                  <td>#<?php echo (int) $r->id; ?></td>
                  <td><?php echo (int) $r->invoice_id; ?></td>
                  <td><?php echo esc_html((string) $r->subject); ?></td>
                  <td><?php echo esc_html((string) $r->status); ?></td>
                  <td><?php echo esc_html((string) $r->created_at); ?></td>
                  <td><a class="button" href="<?php echo esc_url($url); ?>"><?php esc_html_e('View', 'arm-repair-estimates'); ?></a></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="6"><?php esc_html_e('No claims yet.', 'arm-repair-estimates'); ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_detail(int $id, string $email): string
    {
        global $wpdb;
        $tbl  = $wpdb->prefix . 'arm_warranty_claims';
        $msgs = $wpdb->prefix . 'arm_warranty_claim_messages';

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d AND email=%s", $id, $email));
        if (!$row) return '<p>' . esc_html__('Claim not found.', 'arm-repair-estimates') . '</p>';

        $has_msgs_table = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name=%s", $msgs
        )) > 0;

        $convo = $has_msgs_table ? $wpdb->get_results($wpdb->prepare(
            "SELECT actor, message, created_at FROM $msgs WHERE claim_id=%d ORDER BY created_at ASC", $id
        )) : [];

        $back = remove_query_arg('claim_id');
        ob_start();
        ?>
        <div class="arm-warranty-claim" style="max-width:900px;margin:1rem auto;">
          <h2><?php echo esc_html(sprintf(__('Warranty Claim #%d', 'arm-repair-estimates'), (int) $row->id)); ?></h2>
          <p><strong><?php esc_html_e('Subject', 'arm-repair-estimates'); ?>:</strong> <?php echo esc_html((string) $row->subject); ?></p>
          <p><strong><?php esc_html_e('Status', 'arm-repair-estimates'); ?>:</strong> <?php echo esc_html((string) $row->status); ?></p>
          <p><strong><?php esc_html_e('Created', 'arm-repair-estimates'); ?>:</strong> <?php echo esc_html((string) $row->created_at); ?></p>

          <?php if ($convo): ?>
            <h3><?php esc_html_e('Conversation', 'arm-repair-estimates'); ?></h3>
            <ul>
              <?php foreach ($convo as $m): ?>
                <li><em><?php echo esc_html(ucfirst((string) $m->actor)); ?></em> — <small><?php echo esc_html((string) $m->created_at); ?></small><br><?php echo wp_kses_post((string) $m->message); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if (!in_array(strtoupper((string) $row->status), ['RESOLVED','REJECTED'], true)): ?>
            <h3><?php esc_html_e('Post a Reply', 'arm-repair-estimates'); ?></h3>
            <form method="post">
              <?php wp_nonce_field('arm_claim_reply', 'arm_claim_nonce'); ?>
              <p><textarea name="reply_message" rows="5" class="large-text" required></textarea></p>
              <p><button type="submit" class="button button-primary"><?php esc_html_e('Send Reply', 'arm-repair-estimates'); ?></button></p>
            </form>
          <?php endif; ?>

          <p><a class="button" href="<?php echo esc_url($back); ?>">&larr; <?php esc_html_e('Back to My Claims', 'arm-repair-estimates'); ?></a></p>
        </div>
        <?php
        return ob_get_clean();
    }
}

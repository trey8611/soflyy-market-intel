<?php
namespace Soflyy\MarketIntel\Admin;

class EddSnippet {

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $from    = '';
        $to      = '';
        $snippet = '';

        if ( isset( $_GET['smi_edd_from'], $_GET['smi_edd_to'] ) ) {
            check_admin_referer( 'smi_edd_snippet' );
            $from = sanitize_text_field( $_GET['smi_edd_from'] );
            $to   = sanitize_text_field( $_GET['smi_edd_to'] );

            if ( $from && $to ) {
                $snippet = $this->generate_snippet( $from, $to );
            }
        }

        ?>
        <h2>Generate EDD Report Snippet</h2>
        <p>Enter a date range, generate the PHP snippet, save it as <code>tmp.php</code> on the EDD site, run <code>wp eval-file tmp.php</code>, paste the output into the EDD Sales form, then delete the file.</p>

        <form method="get">
            <input type="hidden" name="page" value="soflyy-market-intel">
            <input type="hidden" name="tab"  value="edd-snippet">
            <?php wp_nonce_field( 'smi_edd_snippet' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="smi-edd-from">From</label></th>
                    <td><input type="date" id="smi-edd-from" name="smi_edd_from" value="<?php echo esc_attr( $from ); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="smi-edd-to">To</label></th>
                    <td><input type="date" id="smi-edd-to" name="smi_edd_to" value="<?php echo esc_attr( $to ); ?>" required></td>
                </tr>
            </table>
            <?php submit_button( 'Generate Snippet', 'primary', 'smi_generate_snippet' ); ?>
        </form>

        <?php if ( $snippet ) : ?>
            <h3>Generated snippet — copy and run on EDD site</h3>
            <p>
                <button class="button" onclick="
                    var t = document.getElementById('smi-snippet-output');
                    t.select(); document.execCommand('copy');
                    this.textContent = 'Copied!';
                ">Copy to clipboard</button>
            </p>
            <textarea id="smi-snippet-output" rows="60" readonly><?php echo esc_textarea( $snippet ); ?></textarea>
        <?php endif; ?>
        <?php
    }

    private function generate_snippet( string $from, string $to ): string {
        // Dates are already sanitized by the caller; format-validate here.
        $from = preg_replace( '/[^0-9\-]/', '', $from );
        $to   = preg_replace( '/[^0-9\-]/', '', $to );

        return <<<PHP
<?php
/**
 * EDD aggregate report — aggregates only, no PII.
 * Run: wp eval-file this-file.php
 * Delete this file after use.
 *
 * Aggregates only. No customer identifiers, order IDs, or line-item
 * data are selected. Output is copied manually into the Market Intel
 * plugin's EDD Sales form.
 */

\$from = '{$from}';
\$to   = '{$to}';

global \$wpdb;

// === NEW SALES (first-time purchases) ===
\$new = \$wpdb->get_row( \$wpdb->prepare(
    "SELECT SUM(total) AS gross, SUM(tax) AS tax, COUNT(id) AS cnt
     FROM {\$wpdb->prefix}edd_orders
     WHERE date_created BETWEEN %s AND %s
       AND type = 'sale'
       AND status IN ('complete','partially_refunded')",
    \$from . ' 00:00:00', \$to . ' 23:59:59'
) );

// === RENEWALS (recurring payments; status='edd_subscription') ===
// Confirmed: real renewal payments, parent = prior-period original purchase.
// Additive to gross — NO parent subtraction needed within a window.
\$renew = \$wpdb->get_row( \$wpdb->prepare(
    "SELECT SUM(total) AS gross, SUM(tax) AS tax, COUNT(id) AS cnt
     FROM {\$wpdb->prefix}edd_orders
     WHERE date_created BETWEEN %s AND %s
       AND type = 'sale'
       AND status = 'edd_subscription'",
    \$from . ' 00:00:00', \$to . ' 23:59:59'
) );

// === REFUNDS (separate rows with negative totals) ===
\$refunds = \$wpdb->get_row( \$wpdb->prepare(
    "SELECT COUNT(id) AS cnt, ABS(SUM(total)) AS amount
     FROM {\$wpdb->prefix}edd_orders
     WHERE date_created BETWEEN %s AND %s
       AND type = 'refund'",
    \$from . ' 00:00:00', \$to . ' 23:59:59'
) );

\$new_gross     = (float) \$new->gross;
\$renewal_gross = (float) \$renew->gross;
\$total_gross   = \$new_gross + \$renewal_gross;
\$tax           = (float) \$new->tax + (float) \$renew->tax;
\$refund_amount = (float) \$refunds->amount;
\$net           = \$total_gross - \$refund_amount - \$tax; // total gross − refunds − tax

// PHASE 2 (do not build now): cohort renewal rate.
// Original purchase = parent order (status complete, type sale).
// Renewal = child order (status edd_subscription, type sale, parent = original id).
// Renewal rate for cohort month M = COUNT(distinct parents from M that have a
// child ~12mo later) / COUNT(new sales in M). This yields true churn by cohort.

// === PER-PRODUCT TOTALS — product name only, no customer data ===
\$products = \$wpdb->get_results( \$wpdb->prepare(
    "SELECT
         oi.product_name,
         o.status,
         SUM(oi.amount) AS revenue,
         COUNT(oi.id)   AS orders
     FROM {\$wpdb->prefix}edd_order_items oi
     JOIN {\$wpdb->prefix}edd_orders o ON o.id = oi.order_id
     WHERE o.date_created BETWEEN %s AND %s
       AND o.type = 'sale'
       AND o.status IN ('complete','partially_refunded','edd_subscription')
     GROUP BY oi.product_name, o.status
     ORDER BY oi.product_name, o.status",
    \$from . ' 00:00:00', \$to . ' 23:59:59'
) );

// === ACTIVE LICENSES (today snapshot — do NOT filter by date range) ===
// Note: COUNT(*) WHERE status='active' is a full table scan — no guaranteed
// index on status. Run this off-peak on large edd_licenses tables.
\$sl_table     = \$wpdb->prefix . 'edd_licenses';
\$active_count = 'N/A (EDD SL not detected)';
if ( \$wpdb->get_var( "SHOW TABLES LIKE '{\$sl_table}'" ) === \$sl_table ) {
    \$active_count = (int) \$wpdb->get_var(
        "SELECT COUNT(*) FROM `{\$sl_table}` WHERE status = 'active'"
    );
}

// === OUTPUT ===
echo "\n=== EDD Aggregates {\$from} to {\$to} ===\n";
echo "New sales gross:      \$" . number_format( \$new_gross,        2 ) . " ({\$new->cnt} orders)\n";
echo "Renewal gross:        \$" . number_format( \$renewal_gross,    2 ) . " ({\$renew->cnt} orders)\n";
echo "  └─ renewal revenue (included in gross above)\n";
echo "Total gross:          \$" . number_format( \$total_gross,      2 ) . "\n";
echo "Tax:                  \$" . number_format( \$tax,              2 ) . "\n";
echo "Refund count:         "  .                \$refunds->cnt          . "\n";
echo "Refund amount:        \$" . number_format( \$refund_amount,    2 ) . "\n";
echo "Net:                  \$" . number_format( \$net,              2 ) . "\n";
echo "Active licenses:      "  .                \$active_count          . " (today snapshot)\n";
echo "\nNOTE: Daily/period total will NOT tie exactly to EDD's Earnings widget. EDD applies\n";
echo "its own refund-netting and recurring-revenue date attribution; this snippet sums\n";
echo "gross by payment date_created, which is the intended auditable figure. Renewals\n";
echo "(status edd_subscription) are included in gross and broken out separately.\n";
echo "\n--- Per-product line revenue (pre-tax, gross of refunds; will NOT sum to total gross) ---\n";
// Per-product figures use edd_order_items.amount (pre-tax line revenue) and do not
// deduct refunds, which exist as separate type='refund' rows not joined here.
// Directional product-mix indicators only; intentionally do not reconcile to total gross.
foreach ( \$products as \$p ) {
    \$label = \$p->status === 'edd_subscription' ? 'renewal' : 'new';
    echo \$p->product_name . " [{\$label}]: \$" . number_format( \$p->revenue, 2 ) . " / {\$p->orders} orders\n";
}
echo "\n=== End of report. Delete this file. ===\n";
PHP;
    }
}

<?php
namespace Soflyy\MarketIntel\Admin;

class ManualEntry {

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->maybe_process_post();

        settings_errors( 'smi' );

        $entities = $this->get_entities();

        $this->render_general_section( $entities );
        $this->render_w3techs_section();
        $this->render_google_trends_section();
        $this->render_builtwith_section();
        $this->render_competitor_proxies_section( $entities );
        $this->render_edd_sales_section( $entities );
    }

    private function maybe_process_post(): void {
        if ( empty( $_POST['smi_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $action = sanitize_key( $_POST['smi_action'] );

        check_admin_referer( 'smi_manual_' . $action );

        match ( $action ) {
            'general'           => $this->save_general(),
            'w3techs'           => $this->save_w3techs(),
            'google_trends'     => $this->save_google_trends(),
            'builtwith'         => $this->save_builtwith(),
            'competitor_proxy'  => $this->save_competitor_proxy(),
            'edd_sales'         => $this->save_edd_sales(),
            default             => null,
        };
    }

    private function write_metric( int $entity_id, string $metric_key, ?float $value, ?string $value_text, string $period_date, string $source ): void {
        global $wpdb;
        // Append-only. No UPDATE or DELETE. Correction-row supersession happens on read.
        $wpdb->insert(
            $wpdb->prefix . 'smi_metrics',
            [
                'entity_id'   => $entity_id,
                'metric_key'  => $metric_key,
                'value'       => $value,
                'value_text'  => $value_text,
                'confidence'  => 'manual',
                'source'      => $source,
                'captured_at' => current_time( 'mysql' ),
                'period_date' => $period_date,
            ],
            [ '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    private function source(): string {
        return 'manual:' . wp_get_current_user()->user_login;
    }

    private function get_entities(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name, slug, type FROM `{$wpdb->prefix}smi_entities` ORDER BY type, name",
            ARRAY_A
        );
    }

    private function get_last_metric( string $entity_slug, string $metric_key ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT m.value, m.period_date
             FROM `{$wpdb->prefix}smi_metrics` m
             INNER JOIN `{$wpdb->prefix}smi_entities` e ON e.id = m.entity_id
             WHERE e.slug = %s AND m.metric_key = %s
             ORDER BY m.period_date DESC, m.captured_at DESC
             LIMIT 1",
            $entity_slug, $metric_key
        ), ARRAY_A );
    }

    private function last_hint( ?array $row, bool $large_number = false ): string {
        if ( ! $row ) return '';
        $v = $large_number
            ? number_format( (float) $row['value'] )
            : rtrim( rtrim( number_format( (float) $row['value'], 2 ), '0' ), '.' );
        return '<small style="color:#666;margin-left:6px">last: ' . esc_html( $v ) . ' (' . esc_html( $row['period_date'] ) . ')</small>';
    }

    // ── General metric ────────────────────────────────────────────────────

    private function save_general(): void {
        $entity_id   = (int) ( $_POST['entity_id'] ?? 0 );
        $metric_key  = sanitize_key( $_POST['metric_key'] ?? '' );
        $raw_value   = $_POST['value'] ?? '';
        $value_text  = sanitize_text_field( $_POST['value_text'] ?? '' ) ?: null;
        $period_date = sanitize_text_field( $_POST['period_date'] ?? '' );

        if ( ! $entity_id || ! $metric_key || ! $period_date ) return;

        if ( $raw_value !== '' && ! is_numeric( $raw_value ) ) {
            add_settings_error( 'smi', 'invalid_value', 'Value must be numeric.', 'error' );
            return;
        }

        $value = ( $raw_value !== '' ) ? (float) $raw_value : null;

        $this->write_metric( $entity_id, $metric_key, $value, $value_text, $period_date, $this->source() );
        add_settings_error( 'smi', 'saved', 'Metric saved.', 'success' );
    }

    private function render_general_section( array $entities ): void {
        ?>
        <form method="post">
        <fieldset class="smi-fieldset">
            <legend>General Metric</legend>
            <?php wp_nonce_field( 'smi_manual_general' ); ?>
            <input type="hidden" name="smi_action" value="general">
            <table class="form-table">
                <tr>
                    <th><label for="smi-entity">Entity</label></th>
                    <td>
                        <select name="entity_id" id="smi-entity">
                            <?php foreach ( $entities as $e ) : ?>
                                <option value="<?php echo esc_attr( $e['id'] ); ?>">
                                    <?php echo esc_html( $e['name'] . ' (' . $e['type'] . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="smi-metric-key">Metric key</label></th>
                    <td><input type="text" name="metric_key" id="smi-metric-key" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="smi-value">Value (numeric)</label></th>
                    <td><input type="number" step="any" name="value" id="smi-value" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="smi-value-text">Value (text)</label></th>
                    <td><input type="text" name="value_text" id="smi-value-text" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="smi-period">Period date</label></th>
                    <td><input type="date" name="period_date" id="smi-period" required></td>
                </tr>
            </table>
            <?php submit_button( 'Save Metric' ); ?>
        </fieldset>
        </form>
        <?php
    }

    // ── W3Techs ───────────────────────────────────────────────────────────

    private function save_w3techs(): void {
        $period_date = sanitize_text_field( $_POST['period_date'] ?? '' );
        if ( ! $period_date ) return;

        foreach ( [ 'none-cms', 'wordpress', 'shopify', 'wix', 'squarespace', 'joomla', 'webflow', 'tilda', 'duda', 'drupal', 'godaddy-website-builder', 'adobe-systems', 'google-systems' ] as $slug ) {
            $entity_id = \Soflyy\MarketIntel\Database\Schema::get_entity_id( $slug );
            if ( ! $entity_id ) continue;
            $val = isset( $_POST[ $slug . '_cms_market_share' ] ) && $_POST[ $slug . '_cms_market_share' ] !== ''
                ? (float) $_POST[ $slug . '_cms_market_share' ] : null;
            if ( $val !== null ) {
                $this->write_metric( $entity_id, 'cms_market_share', $val, null, $period_date, $this->source() );
            }
        }

        add_settings_error( 'smi', 'saved', 'W3Techs figures saved.', 'success' );
    }

    private function render_w3techs_section(): void {
        $cms_platforms = [
            'none-cms'                => [ 'None (No CMS)',          'e.g. 30.0' ],
            'wordpress'               => [ 'WordPress',              'e.g. 41.5' ],
            'shopify'                 => [ 'Shopify',                'e.g. 5.2'  ],
            'wix'                     => [ 'Wix',                    'e.g. 4.3'  ],
            'squarespace'             => [ 'Squarespace',            'e.g. 2.5'  ],
            'joomla'                  => [ 'Joomla',                 'e.g. 1.2'  ],
            'webflow'                 => [ 'Webflow',                'e.g. 0.9'  ],
            'tilda'                   => [ 'Tilda',                  'e.g. 0.8'  ],
            'duda'                    => [ 'Duda',                   'e.g. 0.7'  ],
            'drupal'                  => [ 'Drupal',                 'e.g. 0.7'  ],
            'godaddy-website-builder' => [ 'GoDaddy Website Builder','e.g. 0.6'  ],
            'adobe-systems'           => [ 'Adobe Systems',          'e.g. 0.6'  ],
            'google-systems'          => [ 'Google Systems',         'e.g. 0.6'  ],
        ];
        ?>
        <form method="post">
        <fieldset class="smi-fieldset">
            <legend>W3Techs Market Share</legend>
            <ol style="margin-left:1.5em;margin-bottom:1em;line-height:2">
                <li>Open <a href="https://w3techs.com/technologies/history_overview/content_management/ms/y" target="_blank" rel="noopener"><strong>w3techs.com &rarr; CMS yearly history</strong></a> for the historical backfill, or <a href="https://w3techs.com/technologies/overview/content_management" target="_blank" rel="noopener">CMS overview</a> for the current month.</li>
                <li>Find the column headed exactly <strong>"% of all websites"</strong> — the <em>left-hand</em> percentage column, always the smaller number. <strong>Do not use "% of websites using a CMS"</strong> — that column runs 15–25 points higher.</li>
                <li>Enter each platform's value below (numbers only, no % sign — e.g. <code>43.2</code>). For historical years use <code>YYYY-01-01</code> as the period date.</li>
            </ol>
            <?php wp_nonce_field( 'smi_manual_w3techs' ); ?>
            <input type="hidden" name="smi_action" value="w3techs">
            <table class="form-table">
                <tr><th>Period date</th><td><input type="date" name="period_date" required></td></tr>
                <tr><th colspan="2"><strong>CMS % of all websites</strong></th></tr>
                <?php foreach ( $cms_platforms as $slug => [ $name, $ph ] ) :
                    $last = $this->get_last_metric( $slug, 'cms_market_share' );
                ?>
                    <tr>
                        <th><?php echo esc_html( $name ); ?></th>
                        <td>
                            <input type="number" step="0.01" min="0" max="100"
                                   placeholder="<?php echo esc_attr( $ph ); ?>"
                                   name="<?php echo esc_attr( $slug . '_cms_market_share' ); ?>"
                                   style="width:90px">
                            <?php echo $this->last_hint( $last ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button( 'Save W3Techs' ); ?>
        </fieldset>
        </form>
        <?php
    }

    // ── Google Trends ─────────────────────────────────────────────────────

    private function save_google_trends(): void {
        $period_date = sanitize_text_field( $_POST['period_date'] ?? '' );
        if ( ! $period_date ) return;

        $terms = $this->trends_terms();
        foreach ( $terms as $slug => $label ) {
            $entity_id = \Soflyy\MarketIntel\Database\Schema::get_entity_id( $slug );
            if ( ! $entity_id ) continue;
            $val = isset( $_POST[ 'trend_' . $slug ] ) && $_POST[ 'trend_' . $slug ] !== '' ? (float) $_POST[ 'trend_' . $slug ] : null;
            if ( $val !== null ) {
                $this->write_metric( $entity_id, 'search_interest', $val, null, $period_date, $this->source() );
            }
        }
        add_settings_error( 'smi', 'saved', 'Google Trends figures saved.', 'success' );
    }

    private function render_google_trends_section(): void {
        ?>
        <form method="post">
        <fieldset class="smi-fieldset">
            <legend>Google Trends (relative interest 0–100)</legend>
            <p>Google Trends only allows 5 terms per comparison. Use two pre-built queries, both including WordPress as the anchor — it will show <strong>100</strong> in both, keeping all values on the same scale so you can enter them directly without any math.</p>
            <ol style="margin-left:1.5em;margin-bottom:1em;line-height:2">
                <li>Open <a href="https://trends.google.com/trends/explore?q=WordPress,Wix,Shopify,Squarespace,Webflow&date=today%2012-m&geo=" target="_blank" rel="noopener"><strong>Query 1: WordPress, Wix, Shopify, Squarespace, Webflow</strong></a>.</li>
                <li>Hover your cursor over the <strong>rightmost data point</strong> on the chart. A tooltip pops up showing each platform's value from 0–100. Enter those 5 numbers below.</li>
                <li>Open <a href="https://trends.google.com/trends/explore?q=WordPress,Joomla,WP%20All%20Import,WP%20All%20Export&date=today%2012-m&geo=" target="_blank" rel="noopener"><strong>Query 2: WordPress, Joomla, WP All Import, WP All Export</strong></a>.</li>
                <li>Hover the rightmost point again. WordPress will again show 100 — skip it. Enter only Joomla, WP All Import, and WP All Export values below.</li>
            </ol>
            <?php wp_nonce_field( 'smi_manual_google_trends' ); ?>
            <input type="hidden" name="smi_action" value="google_trends">
            <table class="form-table">
                <tr><th>Period date</th><td><input type="date" name="period_date" required></td></tr>
                <?php foreach ( $this->trends_terms() as $slug => $label ) :
                    $last = $this->get_last_metric( $slug, 'search_interest' );
                ?>
                    <tr>
                        <th><?php echo esc_html( $label ); ?></th>
                        <td>
                            <input type="number" min="0" max="100" placeholder="0–100"
                                   name="<?php echo esc_attr( 'trend_' . $slug ); ?>" style="width:80px">
                            <?php echo $this->last_hint( $last ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button( 'Save Google Trends' ); ?>
        </fieldset>
        </form>
        <?php
    }

    private function trends_terms(): array {
        return [
            'wordpress'    => 'WordPress',
            'wix'          => 'Wix',
            'shopify'      => 'Shopify',
            'squarespace'  => 'Squarespace',
            'webflow'      => 'Webflow',
            'joomla'       => 'Joomla',
            'wp-all-import'=> 'WP All Import',
            'wp-all-export'=> 'WP All Export',
        ];
    }

    // ── BuiltWith ─────────────────────────────────────────────────────────

    private function save_builtwith(): void {
        $period_date = sanitize_text_field( $_POST['period_date'] ?? '' );
        if ( ! $period_date ) return;

        foreach ( [ 'woocommerce', 'shopify' ] as $slug ) {
            $entity_id = \Soflyy\MarketIntel\Database\Schema::get_entity_id( $slug );
            if ( ! $entity_id ) continue;
            $val = isset( $_POST[ 'bw_' . $slug ] ) && $_POST[ 'bw_' . $slug ] !== '' ? (float) $_POST[ 'bw_' . $slug ] : null;
            if ( $val !== null ) {
                $this->write_metric( $entity_id, 'builtwith_store_count', $val, null, $period_date, $this->source() );
            }
        }
        add_settings_error( 'smi', 'saved', 'BuiltWith figures saved.', 'success' );
    }

    private function render_builtwith_section(): void {
        $last_wc      = $this->get_last_metric( 'woocommerce', 'builtwith_store_count' );
        $last_shopify = $this->get_last_metric( 'shopify',     'builtwith_store_count' );
        ?>
        <form method="post">
        <fieldset class="smi-fieldset">
            <legend>BuiltWith Store Counts</legend>
            <ol style="margin-left:1.5em;margin-bottom:1em;line-height:2">
                <li>Open <a href="https://trends.builtwith.com/shop/WooCommerce" target="_blank" rel="noopener"><strong>trends.builtwith.com/shop/WooCommerce</strong></a>. Near the top of the page you will see a large number followed by the text <strong>"Live Websites"</strong> (e.g. <code>6,234,567 Live Websites</code>). Enter that number below without commas.</li>
                <li>Open <a href="https://trends.builtwith.com/shop/Shopify" target="_blank" rel="noopener"><strong>trends.builtwith.com/shop/Shopify</strong></a> and do the same.</li>
            </ol>
            <?php wp_nonce_field( 'smi_manual_builtwith' ); ?>
            <input type="hidden" name="smi_action" value="builtwith">
            <table class="form-table">
                <tr><th>Period date</th><td><input type="date" name="period_date" required></td></tr>
                <tr>
                    <th>WooCommerce live websites</th>
                    <td>
                        <input type="number" name="bw_woocommerce" placeholder="e.g. 6234567" style="width:160px">
                        <?php echo $this->last_hint( $last_wc, true ); ?>
                    </td>
                </tr>
                <tr>
                    <th>Shopify live websites</th>
                    <td>
                        <input type="number" name="bw_shopify" placeholder="e.g. 5891234" style="width:160px">
                        <?php echo $this->last_hint( $last_shopify, true ); ?>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save BuiltWith' ); ?>
        </fieldset>
        </form>
        <?php
    }

    // ── Competitor proxies ────────────────────────────────────────────────

    private function save_competitor_proxy(): void {
        $entity_id   = (int) ( $_POST['entity_id'] ?? 0 );
        $period_date = sanitize_text_field( $_POST['period_date'] ?? '' );
        if ( ! $entity_id || ! $period_date ) return;

        foreach ( [ 'pricing_usd', 'version' ] as $field ) {
            $val = sanitize_text_field( $_POST[ $field ] ?? '' );
            if ( $val !== '' ) {
                $numeric = is_numeric( $val ) ? (float) $val : null;
                $text    = is_numeric( $val ) ? null : $val;
                $this->write_metric( $entity_id, $field, $numeric, $text, $period_date, $this->source() );
            }
        }
        add_settings_error( 'smi', 'saved', 'Competitor proxy saved.', 'success' );
    }

    private function render_competitor_proxies_section( array $entities ): void {
        $competitors = array_filter( $entities, fn( $e ) => $e['type'] === 'competitor' );
        ?>
        <form method="post">
        <fieldset class="smi-fieldset">
            <legend>Competitor Proxies (pricing, version)</legend>
            <?php wp_nonce_field( 'smi_manual_competitor_proxy' ); ?>
            <input type="hidden" name="smi_action" value="competitor_proxy">
            <table class="form-table">
                <tr>
                    <th><label>Competitor</label></th>
                    <td>
                        <select name="entity_id">
                            <?php foreach ( $competitors as $e ) : ?>
                                <option value="<?php echo esc_attr( $e['id'] ); ?>"><?php echo esc_html( $e['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr><th>Period date</th><td><input type="date" name="period_date" required></td></tr>
                <tr><th>Pricing (USD)</th><td><input type="number" step="0.01" name="pricing_usd"></td></tr>
                <tr><th>Version</th><td><input type="text" name="version" class="regular-text"></td></tr>
            </table>
            <?php submit_button( 'Save Competitor Proxy' ); ?>
        </fieldset>
        </form>
        <?php
    }

    // ── EDD Sales ─────────────────────────────────────────────────────────

    private function save_edd_sales(): void {
        // EDD sales data is entered here by hand and written to smi_metrics only.
        // There is no REST route, no AJAX handler, and no WP-CLI command that
        // reads or transmits this data. It renders only in the local admin dashboard.

        $period_date = sanitize_text_field( $_POST['period_date'] ?? '' );
        if ( ! $period_date ) return;

        $self_entities = $this->get_self_entities();
        $entity_id     = (int) ( $_POST['entity_id'] ?? ( $self_entities[0]['id'] ?? 0 ) );
        if ( ! $entity_id ) return;

        $source = 'manual:edd-cli';

        $scalar_fields = [
            'edd_new_gross',     'edd_new_count',
            'edd_renewal_gross', 'edd_renewal_count',
            'edd_total_gross',   'edd_tax',
            'edd_refund_amount', 'edd_refund_count',
            'edd_net',
        ];

        foreach ( $scalar_fields as $field ) {
            $raw = sanitize_text_field( $_POST[ $field ] ?? '' );
            if ( $raw === '' ) continue;
            $this->write_metric( $entity_id, $field, (float) $raw, null, $period_date, $source );
        }

        // active_license_count is always today — never the entered period date.
        if ( isset( $_POST['active_license_count'] ) && $_POST['active_license_count'] !== '' ) {
            $this->write_metric( $entity_id, 'active_license_count', (float) $_POST['active_license_count'], null, current_time( 'Y-m-d' ), $source );
        }

        // Per-product repeater
        $product_names    = $_POST['product_name']    ?? [];
        $product_revenues = $_POST['product_revenue'] ?? [];
        $product_orders   = $_POST['product_orders']  ?? [];
        $product_types    = $_POST['product_type']    ?? [];

        if ( ! is_array( $product_names ) ) {
            $product_names = [];
        }

        foreach ( $product_names as $i => $name ) {
            $name = sanitize_text_field( $name );
            if ( ! $name ) continue;
            $revenue  = (float) ( $product_revenues[ $i ] ?? 0 );
            $orders   = (int)   ( $product_orders[ $i ]   ?? 0 );
            $type     = sanitize_key( $product_types[ $i ] ?? 'new' );
            $text     = "{$name} [{$type}]: orders={$orders}";
            $this->write_metric( $entity_id, 'edd_product_revenue_' . sanitize_key( $name ), $revenue, $text, $period_date, $source );
        }

        add_settings_error( 'smi', 'saved', 'EDD sales figures saved.', 'success' );
    }

    private function get_self_entities(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name FROM `{$wpdb->prefix}smi_entities` WHERE type = 'self' ORDER BY name",
            ARRAY_A
        );
    }

    private function render_edd_sales_section( array $entities ): void {
        $self_entities = array_filter( $entities, fn( $e ) => $e['type'] === 'self' );
        ?>
        <form method="post">
        <fieldset class="smi-fieldset">
            <legend>EDD Sales (paste from EDD Snippet output)</legend>
            <?php wp_nonce_field( 'smi_manual_edd_sales' ); ?>
            <input type="hidden" name="smi_action" value="edd_sales">
            <table class="form-table">
                <tr>
                    <th>Product (entity)</th>
                    <td>
                        <select name="entity_id">
                            <?php foreach ( $self_entities as $e ) : ?>
                                <option value="<?php echo esc_attr( $e['id'] ); ?>"><?php echo esc_html( $e['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr><th>Period date (from)</th><td><input type="date" name="period_date" required></td></tr>
                <?php
                $fields = [
                    'edd_new_gross'      => 'New sales gross ($)',
                    'edd_new_count'      => 'New order count',
                    'edd_renewal_gross'  => 'Renewal gross ($)',
                    'edd_renewal_count'  => 'Renewal count',
                    'edd_total_gross'    => 'Total gross ($)',
                    'edd_tax'            => 'Tax ($)',
                    'edd_refund_amount'  => 'Refund amount ($)',
                    'edd_refund_count'   => 'Refund count',
                    'edd_net'            => 'Net ($)',
                ];
                foreach ( $fields as $key => $label ) : ?>
                    <tr>
                        <th><?php echo esc_html( $label ); ?></th>
                        <td><input type="number" step="0.01" name="<?php echo esc_attr( $key ); ?>" class="regular-text"></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th>Active licenses <small>(today snapshot)</small></th>
                    <td><input type="number" name="active_license_count" class="regular-text"></td>
                </tr>
            </table>

            <h4>Per-product breakdown</h4>
            <div id="smi-product-repeater">
                <div class="smi-repeater-row">
                    <input type="text"   name="product_name[]"    placeholder="Product name" style="width:200px">
                    <input type="number" name="product_revenue[]"  placeholder="Revenue"      step="0.01" style="width:120px">
                    <input type="number" name="product_orders[]"   placeholder="Orders"                   style="width:80px">
                    <select name="product_type[]">
                        <option value="new">New</option>
                        <option value="renewal">Renewal</option>
                    </select>
                    <button type="button" class="button smi-remove-row">Remove</button>
                </div>
            </div>
            <button type="button" class="button" id="smi-add-product-row">+ Add product row</button>

            <script>
            document.getElementById('smi-add-product-row').addEventListener('click', function() {
                var repeater = document.getElementById('smi-product-repeater');
                var first    = repeater.querySelector('.smi-repeater-row');
                var clone    = first.cloneNode(true);
                clone.querySelectorAll('input').forEach(function(i) { i.value = ''; });
                repeater.appendChild(clone);
            });
            document.getElementById('smi-product-repeater').addEventListener('click', function(e) {
                if (e.target.classList.contains('smi-remove-row')) {
                    var rows = document.querySelectorAll('.smi-repeater-row');
                    if (rows.length > 1) e.target.closest('.smi-repeater-row').remove();
                }
            });
            </script>

            <?php submit_button( 'Save EDD Sales' ); ?>
        </fieldset>
        </form>
        <?php
    }
}

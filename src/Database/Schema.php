<?php
namespace Soflyy\MarketIntel\Database;

class Schema {

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE {$wpdb->prefix}smi_entities (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type          ENUM('platform','plugin','competitor','self') NOT NULL,
            name          VARCHAR(120) NOT NULL,
            slug          VARCHAR(120) NOT NULL,
            wporg_slug    VARCHAR(120) DEFAULT NULL,
            github_repo   VARCHAR(200) DEFAULT NULL,
            tracked_since DATE NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}smi_metrics (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_id    BIGINT UNSIGNED NOT NULL,
            metric_key   VARCHAR(80) NOT NULL,
            value        DECIMAL(20,4) DEFAULT NULL,
            value_text   VARCHAR(500) DEFAULT NULL,
            confidence   ENUM('ground_truth','high','medium','low','manual') NOT NULL,
            source       VARCHAR(200) NOT NULL,
            captured_at  DATETIME NOT NULL,
            period_date  DATE NOT NULL,
            PRIMARY KEY (id),
            KEY entity_metric_period (entity_id, metric_key, period_date)
        ) $charset;" );

        // Append-only. No UPDATE or DELETE paths exist in this plugin.

        dbDelta( "CREATE TABLE {$wpdb->prefix}smi_collector_log (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            collector    VARCHAR(80) NOT NULL,
            status       ENUM('ok','error','manual_required') NOT NULL,
            rows_written INT UNSIGNED NOT NULL DEFAULT 0,
            error        TEXT DEFAULT NULL,
            ran_at       DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY collector_ran (collector, ran_at)
        ) $charset;" );

        // Repair empty strings stored as '' instead of NULL by earlier seed runs.
        $wpdb->query( "UPDATE `{$wpdb->prefix}smi_entities` SET wporg_slug  = NULL WHERE wporg_slug  = ''" ); // phpcs:ignore
        $wpdb->query( "UPDATE `{$wpdb->prefix}smi_entities` SET github_repo = NULL WHERE github_repo = ''" ); // phpcs:ignore

        update_option( 'smi_db_version', SMI_VERSION );

        self::seed_entities();
    }

    public static function uninstall(): void {
        global $wpdb;
        foreach ( [ 'smi_collector_log', 'smi_metrics', 'smi_entities' ] as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore
        }
        delete_option( 'smi_db_version' );
    }

    private static function seed_entities(): void {
        global $wpdb;
        $today = current_time( 'Y-m-d' );

        $entities = [
            [ 'platform',   'WordPress',              'wordpress',              null,                        null ],
            [ 'platform',   'Wix',                    'wix',                    null,                        null ],
            [ 'platform',   'Shopify',                'shopify',                null,                        null ],
            [ 'platform',   'Squarespace',            'squarespace',            null,                        null ],
            [ 'platform',   'Webflow',                'webflow',                null,                        null ],
            [ 'platform',   'Joomla',                 'joomla',                 null,                        null ],
            [ 'platform',   'Drupal',                 'drupal',                 null,                        null ],
            [ 'platform',   'None (No CMS)',          'none-cms',               null,                        null ],
            [ 'platform',   'Tilda',                  'tilda',                  null,                        null ],
            [ 'platform',   'Duda',                   'duda',                   null,                        null ],
            [ 'platform',   'GoDaddy Website Builder','godaddy-website-builder',null,                        null ],
            [ 'platform',   'Adobe Systems',          'adobe-systems',          null,                        null ],
            [ 'platform',   'Google Systems',         'google-systems',         null,                        null ],
            [ 'self',       'WP All Import',          'wp-all-import',          'wp-all-import',             null ],
            [ 'self',       'WP All Export',          'wp-all-export',          'wp-all-export',             null ],
            [ 'competitor', 'Yoast SEO',              'yoast-seo',              'wordpress-seo',             null ],
            [ 'competitor', 'Rank Math',              'rank-math',              'seo-by-rank-math',          null ],
            [ 'competitor', 'WPForms',                'wpforms',                'wpforms-lite',              null ],
            [ 'competitor', 'Easy Digital Downloads', 'easy-digital-downloads', 'easy-digital-downloads',    'easydigitaldownloads/easy-digital-downloads' ],
            [ 'competitor', 'WooCommerce',            'woocommerce',            'woocommerce',               'woocommerce/woocommerce' ],
        ];

        foreach ( $entities as [ $type, $name, $slug, $wporg, $github ] ) {
            // NULLIF converts the empty string that prepare() produces for PHP null back to SQL NULL.
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO `{$wpdb->prefix}smi_entities`
                 (type, name, slug, wporg_slug, github_repo, tracked_since)
                 VALUES (%s, %s, %s, NULLIF(%s,''), NULLIF(%s,''), %s)",
                $type, $name, $slug, (string) $wporg, (string) $github, $today
            ) );
        }
    }

    /**
     * Canonical read helper. Returns the latest captured_at row per
     * (entity_id, metric_key, period_date). All dashboard and signal
     * read paths MUST use this to honour correction-row supersession.
     *
     * @param string|null $entity_ids  Comma-separated IDs, or null for all.
     * @param string|null $metric_key  Exact metric_key, or null for all.
     * @return string  SQL subquery string (aliased as `m`), ready for JOIN.
     */
    public static function latest_metrics_query(
        ?string $entity_ids = null,
        ?string $metric_key = null
    ): string {
        global $wpdb;

        $inner_where = '1=1';
        $outer_where = '1=1';
        if ( $entity_ids !== null ) {
            // Sanitize to integers only — entity IDs are always numeric, never user strings.
            $safe_ids     = implode( ',', array_map( 'intval', explode( ',', $entity_ids ) ) );
            $inner_where .= " AND entity_id IN ($safe_ids)";
            $outer_where .= " AND m.entity_id IN ($safe_ids)";
        }
        if ( $metric_key !== null ) {
            $inner_where .= $wpdb->prepare( ' AND metric_key = %s', $metric_key );
            $outer_where .= $wpdb->prepare( ' AND m.metric_key = %s', $metric_key );
        }

        return "SELECT m.* FROM `{$wpdb->prefix}smi_metrics` m
                INNER JOIN (
                    SELECT entity_id, metric_key, period_date, MAX(captured_at) AS max_at
                    FROM `{$wpdb->prefix}smi_metrics`
                    WHERE $inner_where
                    GROUP BY entity_id, metric_key, period_date
                ) latest ON m.entity_id = latest.entity_id
                         AND m.metric_key = latest.metric_key
                         AND m.period_date = latest.period_date
                         AND m.captured_at = latest.max_at
                WHERE $outer_where";
    }

    public static function get_entity_id( string $slug ): ?int {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$wpdb->prefix}smi_entities` WHERE slug = %s",
            $slug
        ) );
        return $id ? (int) $id : null;
    }

    public static function get_entities_with_wporg_slug(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, slug, wporg_slug FROM `{$wpdb->prefix}smi_entities` WHERE wporg_slug IS NOT NULL AND wporg_slug != ''", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
    }

    public static function get_entities_with_github_repo(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, slug, github_repo FROM `{$wpdb->prefix}smi_entities` WHERE github_repo IS NOT NULL AND github_repo != ''", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
    }

    public static function metric_exists(
        int $entity_id,
        string $metric_key,
        string $period_date
    ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM `{$wpdb->prefix}smi_metrics`
             WHERE entity_id = %d AND metric_key = %s AND period_date = %s
             LIMIT 1",
            $entity_id, $metric_key, $period_date
        ) );
    }
}

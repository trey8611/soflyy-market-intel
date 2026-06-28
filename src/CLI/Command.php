<?php
namespace Soflyy\MarketIntel\CLI;

use Soflyy\MarketIntel\Plugin;

class Command {

    /**
     * Run one or all due collectors.
     *
     * ## OPTIONS
     * [--collector=<name>]
     * : Slug of a specific collector to run.
     *
     * ## EXAMPLES
     *   wp smi collect
     *   wp smi collect --collector=wporg
     *
     * @subcommand collect
     */
    public function collect( array $args, array $assoc ): void {
        $slug    = $assoc['collector'] ?? null;
        $results = Plugin::instance()->registry->dispatch( $slug ?: null );

        $rows = [];
        foreach ( $results as $s => $r ) {
            $rows[] = [
                'collector'    => $s,
                'status'       => $r['error'] ? 'error' : 'ok',
                'rows_written' => $r['rows'],
                'error'        => $r['error'] ?? '',
            ];
        }

        \WP_CLI\Utils\format_items( 'table', $rows, [ 'collector', 'status', 'rows_written', 'error' ] );
    }

    /**
     * Fetch one Wayback snapshot for an entity and dump the raw HTML section around "install".
     *
     * ## OPTIONS
     * --entity=<slug>
     * : Entity slug (e.g. "wp-all-import").
     *
     * ## EXAMPLES
     *   wp smi debug-wayback --entity=wp-all-import
     *
     * @subcommand debug-wayback
     */
    public function debug_wayback( array $args, array $assoc ): void {
        global $wpdb;

        $slug   = $assoc['entity'] ?? '';
        $entity = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, slug, wporg_slug FROM `{$wpdb->prefix}smi_entities` WHERE slug = %s",
            $slug
        ), ARRAY_A );

        if ( ! $entity || empty( $entity['wporg_slug'] ) ) {
            \WP_CLI::error( "No entity with wporg_slug found for slug: {$slug}" );
        }

        $cdx_url = add_query_arg( [
            'url'      => 'wordpress.org/plugins/' . $entity['wporg_slug'] . '/',
            'output'   => 'json',
            'collapse' => 'timestamp:6',
            'limit'    => '36',
            'from'     => '20160101',
        ], 'https://web.archive.org/cdx/search/cdx' );

        \WP_CLI::log( "CDX URL: {$cdx_url}" );
        \WP_CLI::log( 'Waiting 60s before CDX request...' );
        sleep( 60 );

        $cdx_resp = wp_remote_get( $cdx_url, [ 'timeout' => 60 ] );
        if ( is_wp_error( $cdx_resp ) ) {
            \WP_CLI::error( 'CDX request failed: ' . $cdx_resp->get_error_message() );
        }

        $snapshots = json_decode( wp_remote_retrieve_body( $cdx_resp ), true );
        if ( ! is_array( $snapshots ) || count( $snapshots ) < 2 ) {
            \WP_CLI::error( 'CDX returned no snapshots.' );
        }
        array_shift( $snapshots ); // remove header row

        \WP_CLI::log( sprintf( 'Sweeping %d snapshots...', count( $snapshots ) ) );

        foreach ( $snapshots as $i => $snap ) {
            $snap_url = "https://web.archive.org/web/{$snap[1]}/{$snap[2]}";
            $label    = substr( $snap[1], 0, 6 ); // YYYYMM

            $snap_resp = wp_remote_get( $snap_url, [ 'timeout' => 60 ] );
            if ( is_wp_error( $snap_resp ) ) {
                \WP_CLI::log( "[{$label}] ERROR: " . $snap_resp->get_error_message() );
                sleep( 2 );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $snap_resp );
            if ( $code !== 200 ) {
                \WP_CLI::log( "[{$label}] HTTP {$code}" );
                sleep( 2 );
                continue;
            }

            $html = wp_remote_retrieve_body( $snap_resp );
            $text = preg_replace( '/\s+/', ' ', strip_tags( $html ) );

            if ( preg_match( '/active\s+installs?\s*:?\s*([\d,]+\+?)/i', $text, $m ) ) {
                \WP_CLI::log( "[{$label}] OK — primary regex: {$m[1]}" );
            } elseif ( preg_match( '/(\d+)\+?\s*million\+?\s*active\s+installs?/i', $text, $m ) ) {
                \WP_CLI::log( "[{$label}] OK — million regex: {$m[1]}M+" );
            } else {
                $has_installs = stripos( $text, 'install' ) !== false;
                $has_wporg    = stripos( $text, 'wordpress.org' ) !== false || stripos( $text, 'active installs' ) !== false;

                // Show ±120 chars around "install" (first hit) so the format is visible.
                $snippet = '';
                $pos     = stripos( $text, 'install' );
                if ( $pos !== false ) {
                    $snippet = '  snippet: ...' . substr( $text, max( 0, $pos - 80 ), 200 ) . '...';
                } else {
                    $snippet = '  first 300 chars: ' . substr( $text, 0, 300 );
                }

                \WP_CLI::log( "[{$label}] MISS (has_install=" . ( $has_installs ? 'y' : 'n' ) . " has_wporg=" . ( $has_wporg ? 'y' : 'n' ) . ")" );
                \WP_CLI::log( $snippet );

                // Hex dump if "active install" is present but regex failed.
                $ai_pos = stripos( $text, 'active install' );
                if ( $ai_pos !== false ) {
                    \WP_CLI::log( '  hex: ' . bin2hex( substr( $text, $ai_pos, 30 ) ) );
                }
            }

            sleep( 60 );
        }
    }

    /**
     * Force-run a backfill collector regardless of schedule.
     *
     * ## OPTIONS
     * --collector=<name>
     * : Collector slug to backfill (e.g. "wayback").
     *
     * ## EXAMPLES
     *   wp smi backfill --collector=wayback
     *
     * @subcommand backfill
     */
    public function backfill( array $args, array $assoc ): void {
        $slug      = $assoc['collector'] ?? '';
        $collector = Plugin::instance()->registry->get( $slug );

        if ( ! $collector ) {
            \WP_CLI::error( "Unknown collector: {$slug}" );
        }

        \WP_CLI::log( "Running {$slug} (this may take several minutes)..." );
        $collector->run();

        global $wpdb;
        $last = $wpdb->get_row( $wpdb->prepare(
            "SELECT rows_written, status, error FROM `{$wpdb->prefix}smi_collector_log`
             WHERE collector = %s ORDER BY ran_at DESC LIMIT 1",
            $slug
        ), ARRAY_A );
        if ( $last ) {
            \WP_CLI::log( "Status: {$last['status']} | Rows written: {$last['rows_written']}" );
            if ( ! empty( $last['error'] ) ) {
                \WP_CLI::warning( $last['error'] );
            }
        }

        \WP_CLI::success( "Done." );
    }
}

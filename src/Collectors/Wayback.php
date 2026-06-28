<?php
namespace Soflyy\MarketIntel\Collectors;

use Soflyy\MarketIntel\Database\Schema;
use Soflyy\MarketIntel\Enums\Confidence;

class Wayback extends Collector {

    public function slug(): string     { return 'wayback'; }
    public function schedule(): string { return 'manual'; } // forced via wp smi backfill

    protected function collect(): array {
        $entities = Schema::get_entities_with_wporg_slug();

        $cli   = defined( 'WP_CLI' ) && WP_CLI;
        $total = count( $entities );

        foreach ( $entities as $idx => $entity ) {
            if ( $idx > 0 ) {
                sleep( 60 ); // rate-limit between entities; skip before the first request
            }

            if ( $cli ) {
                \WP_CLI::log( "[{$entity['slug']}] ({$idx}/{$total}) Fetching CDX index..." );
            }

            $cdx_url  = add_query_arg( [
                'url'      => 'wordpress.org/plugins/' . $entity['wporg_slug'] . '/',
                'output'   => 'json',
                'collapse' => 'timestamp:6',
                'limit'    => '36',
                'from'     => '20160101',
            ], 'https://web.archive.org/cdx/search/cdx' );

            $response = $this->wayback_get( $cdx_url, [ 'timeout' => 60 ] );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                $this->flag_manual_fallback( $entity['slug'], 'active_installs', 'CDX API error', 'Manual Entry → General metric' );
                if ( $cli ) {
                    \WP_CLI::warning( "[{$entity['slug']}] CDX failed — skipping." );
                }
                continue;
            }

            $snapshots = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $snapshots ) || count( $snapshots ) < 2 ) {
                $this->flag_manual_fallback( $entity['slug'], 'active_installs', 'CDX returned empty', 'Manual Entry → General metric' );
                if ( $cli ) {
                    \WP_CLI::log( "[{$entity['slug']}] CDX empty — no snapshots found." );
                }
                continue;
            }

            // First row is header: ["urlkey","timestamp","original","mimetype","statuscode","digest","length"]
            array_shift( $snapshots );

            if ( $cli ) {
                \WP_CLI::log( "[{$entity['slug']}] Got " . count( $snapshots ) . " snapshots. Fetching..." );
            }

            $entity_rows = [];
            foreach ( $snapshots as $snap_idx => $snap ) {
                $timestamp = $snap[1] ?? '';
                $orig_url  = $snap[2] ?? '';
                if ( ! $timestamp || ! $orig_url ) continue;

                $period_date = \DateTimeImmutable::createFromFormat( 'YmdHis', $timestamp );
                if ( ! $period_date ) continue;

                $snap_url = "https://web.archive.org/web/{$timestamp}/{$orig_url}";
                if ( $snap_idx > 0 ) {
                    sleep( 60 ); // rate-limit between snapshot fetches; skip before the first
                }

                $label = substr( $timestamp, 0, 6 ); // YYYYMM
                if ( $cli ) {
                    \WP_CLI::log( "[{$entity['slug']}] [{$label}] snap " . ( $snap_idx + 1 ) . '/' . count( $snapshots ) . '...' );
                }

                $snap_resp = $this->wayback_get( $snap_url, [ 'timeout' => 20 ] );

                $snap_code = is_wp_error( $snap_resp ) ? $snap_resp->get_error_message() : wp_remote_retrieve_response_code( $snap_resp );
                if ( is_wp_error( $snap_resp ) || $snap_code !== 200 ) {
                    $this->flag_manual_fallback( $entity['slug'], 'active_installs', "Snapshot fetch failed (HTTP {$snap_code})", 'Manual Entry → General metric' );
                    continue;
                }

                $html    = wp_remote_retrieve_body( $snap_resp );
                $parsed  = $this->parse_snapshot( $html, (int) $entity['id'], $entity['slug'], $period_date );
                if ( ! empty( $parsed ) ) {
                    $this->write_rows( $parsed );
                }
                $entity_rows = array_merge( $entity_rows, $parsed );
            }

            if ( $cli ) {
                \WP_CLI::log( "[{$entity['slug']}] Done — " . count( $entity_rows ) . " rows written." );
            }
        }

        return []; // rows already written per-entity above
    }

    private function parse_snapshot( string $html, int $entity_id, string $entity_slug, \DateTimeImmutable $period_date ): array {
        $rows   = [];
        $period = $period_date->format( 'Y-m-d' );
        $text   = preg_replace( '/\s+/', ' ', strip_tags( $html ) );

        // Active installs — WP.org format: "Active Installs: 40,000+"
        // Older reverse format also handled: "1+ million active installs"
        if ( preg_match( '/active\s+installs?\s*:?\s*([\d,]+\+?)/i', $text, $m ) ) {
            $bucket = trim( $m[1] );
            $lower  = (float) str_replace( [ ',', '+' ], '', $bucket );
        } elseif ( preg_match( '/(\d+)\+?\s*million\+?\s*active\s+installs?/i', $text, $m ) ) {
            $lower  = (float) $m[1] * 1_000_000;
            $bucket = $m[1] . 'M+';
        } else {
            $bucket = null;
            $lower  = null;
        }

        if ( $lower !== null ) {
            if ( ! Schema::metric_exists( $entity_id, 'active_installs', $period ) ) {
                $rows[] = new MetricRow( $entity_id, 'active_installs', $lower, $bucket, Confidence::Medium, 'wayback-cdx', $period_date );
            }
        } elseif ( stripos( $text, 'active install' ) !== false ) {
            // Text has "Active Installs" but our regex couldn't parse the format — genuinely flag for manual entry.
            $this->flag_manual_fallback( $entity_slug, 'active_installs', 'Install bucket parse failed', 'Manual Entry → General metric' );
        }
        // else: "Active Installs" absent entirely — unusable snapshot (WM interstitial, redirect, pre-2015 page), skip silently.

        // Rating
        if ( preg_match( '/(\d+(?:\.\d+)?)\s*(?:out\s*of\s*5|stars?)/i', $text, $m ) ) {
            if ( ! Schema::metric_exists( $entity_id, 'rating', $period ) ) {
                $rows[] = new MetricRow( $entity_id, 'rating', (float) $m[1] * 20, null, Confidence::Medium, 'wayback-cdx', $period_date );
            }
        }

        // Number of ratings
        if ( preg_match( '/(\d[\d,]*)\s+rating/i', $text, $m ) ) {
            if ( ! Schema::metric_exists( $entity_id, 'num_ratings', $period ) ) {
                $rows[] = new MetricRow( $entity_id, 'num_ratings', (float) str_replace( ',', '', $m[1] ), null, Confidence::Medium, 'wayback-cdx', $period_date );
            }
        }

        return $rows;
    }
}

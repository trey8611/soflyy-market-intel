<?php
namespace Soflyy\MarketIntel\Admin;

use Soflyy\MarketIntel\Collectors\Registry;
use Soflyy\MarketIntel\Database\Schema;

class Dashboard {

    public function __construct( private readonly Registry $registry ) {}

    public function render(): void {
        $chart_data    = $this->build_chart_data();
        $signals       = $this->build_signals();
        $collector_log = $this->build_collector_log();
        $verdict       = $this->build_verdict();

        wp_localize_script( 'smi-dashboard', 'smiData', [
            'charts'  => $chart_data,
            'signals' => $signals,
        ] );

        $this->render_verdict( $verdict );
        $this->render_confidence_legend();
        echo '<div id="smi-charts-container"></div>';
        $this->render_signals_panel( $signals );
        $this->render_collector_panel( $collector_log );
    }

    // ── Verdict ───────────────────────────────────────────────────────────────

    private function build_verdict(): array {
        // Each def: [ entity_slug, metric_key, label, unit, inverted, weight, note ]
        // inverted = true means a rising value is actually bad (e.g. "sites with no CMS" going up = bad)
        $defs = [
            [ 'wordpress',     'cms_market_share', 'WordPress CMS share',    '%',    false, 3, '' ],
            [ 'none-cms',      'cms_market_share', 'Sites with no CMS',      '%',    true,  1, 'rising = fewer sites use any CMS' ],
            [ 'wordpress',     'search_interest',  'WordPress search trend', '/100', false, 2, '' ],
            [ 'wp-all-import', 'active_installs',  'WP All Import installs', '',     false, 2, '' ],
        ];

        $signals = [];
        foreach ( $defs as [ $slug, $metric, $label, $unit, $inverted, $weight, $note ] ) {
            $id = Schema::get_entity_id( $slug );
            if ( ! $id ) continue;
            $rows = $this->latest_n( $id, $metric, 3 );
            if ( count( $rows ) < 2 ) continue;

            $delta   = (float) $rows[0]['value'] - (float) $rows[1]['value'];
            $raw_dir = $delta > 0 ? 'up' : ( $delta < 0 ? 'down' : 'flat' );
            $eff_dir = ( $inverted && $raw_dir !== 'flat' )
                ? ( $raw_dir === 'up' ? 'down' : 'up' )
                : $raw_dir;

            $signals[] = [
                'label'    => $label,
                'unit'     => $unit,
                'rows'     => $rows,
                'delta'    => $delta,
                'raw_dir'  => $raw_dir,
                'eff_dir'  => $eff_dir,
                'weight'   => $weight,
                'note'     => $note,
            ];
        }

        if ( empty( $signals ) ) {
            return [ 'verdict' => 'no_data', 'signals' => [] ];
        }

        $score = 0;
        $total = 0;
        foreach ( $signals as $s ) {
            $score += match( $s['eff_dir'] ) { 'up' => 1, 'down' => -1, default => 0 } * $s['weight'];
            $total += $s['weight'];
        }
        $norm = $total ? $score / $total : 0;

        return [
            'verdict' => match( true ) {
                $norm >  0.25 => 'expanding',
                $norm < -0.25 => 'contracting',
                default       => 'stable',
            },
            'signals' => $signals,
        ];
    }

    private function latest_n( int $entity_id, string $metric_key, int $n ): array {
        global $wpdb;
        $q = Schema::latest_metrics_query( (string) $entity_id, $metric_key );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            "SELECT value, value_text, period_date FROM ( {$q} ) m ORDER BY m.period_date DESC LIMIT {$n}",
            ARRAY_A
        ) ?: [];
    }

    private function render_verdict( array $v ): void {
        if ( $v['verdict'] === 'no_data' ) {
            echo '<div class="smi-verdict smi-verdict-nodata"><p>Not enough data yet for a verdict — add W3Techs data in <strong>Manual Entry</strong> to unlock the expansion/contraction analysis.</p></div>';
            return;
        }

        $cfg = match( $v['verdict'] ) {
            'expanding'   => [ 'cls' => 'smi-verdict-expanding',   'icon' => '↑', 'word' => 'Expanding'   ],
            'contracting' => [ 'cls' => 'smi-verdict-contracting', 'icon' => '↓', 'word' => 'Contracting' ],
            default       => [ 'cls' => 'smi-verdict-stable',      'icon' => '→', 'word' => 'Stable'      ],
        };

        echo '<div class="smi-verdict ' . esc_attr( $cfg['cls'] ) . '">';
        echo '<div class="smi-verdict-header">';
        echo '<span class="smi-verdict-icon">' . esc_html( $cfg['icon'] ) . '</span>';
        echo '<span class="smi-verdict-label">WordPress is <strong>' . esc_html( $cfg['word'] ) . '</strong></span>';
        echo '</div>';

        echo '<table class="smi-verdict-signals">';
        echo '<thead><tr><th>Signal</th><th>Latest</th><th>Previous</th><th>Change</th></tr></thead><tbody>';

        foreach ( $v['signals'] as $s ) {
            $latest = $s['rows'][0];
            $prev   = $s['rows'][1];

            $fmt = function ( float $val ) use ( $s ): string {
                return match( $s['unit'] ) {
                    '%'    => number_format( $val, 1 ) . '%',
                    '/100' => number_format( $val, 0 ) . '/100',
                    default => number_format( $val ),
                };
            };

            $fmt_delta = function ( float $d ) use ( $s ): string {
                $sign = $d >= 0 ? '+' : '';
                return match( $s['unit'] ) {
                    '%'     => sprintf( '%+.1f%%', $d ),
                    '/100'  => sprintf( '%+d', (int) $d ),
                    default => $sign . number_format( $d ),
                };
            };

            $arrow     = match( $s['raw_dir'] ) { 'up' => '↑', 'down' => '↓', default => '→' };
            $color_cls = match( $s['eff_dir'] ) { 'up' => 'smi-vs-pos', 'down' => 'smi-vs-neg', default => 'smi-vs-flat' };

            $label_html = esc_html( $s['label'] );
            if ( $s['note'] ) {
                $label_html .= ' <span class="smi-vs-note">(' . esc_html( $s['note'] ) . ')</span>';
            }

            printf(
                '<tr><td>%s</td><td>%s<small>%s</small></td><td>%s<small>%s</small></td><td class="%s">%s %s</td></tr>',
                $label_html,
                esc_html( $fmt( (float) $latest['value'] ) ),
                esc_html( $latest['period_date'] ),
                esc_html( $fmt( (float) $prev['value'] ) ),
                esc_html( $prev['period_date'] ),
                esc_attr( $color_cls ),
                esc_html( $arrow ),
                esc_html( $fmt_delta( $s['delta'] ) )
            );
        }

        echo '</tbody></table></div>';
    }

    // ── Charts ────────────────────────────────────────────────────────────────

    private function build_chart_data(): array {
        global $wpdb;

        $metric_groups = [
            'active_installs' => 'Active Installs',
            'rating'          => 'Rating',
            'num_ratings'     => 'Review Count',
            'search_interest' => 'Search Interest',
            'github_stars'    => 'GitHub Stars',
        ];

        $charts = [];

        foreach ( $metric_groups as $metric_key => $label ) {
            $latest_query = Schema::latest_metrics_query( null, $metric_key );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results( "
                SELECT m.entity_id, m.value, m.value_text, m.confidence, m.source,
                       m.period_date, e.name AS entity_name, e.slug AS entity_slug
                FROM ( {$latest_query} ) m
                JOIN `{$wpdb->prefix}smi_entities` e ON e.id = m.entity_id
                ORDER BY m.period_date ASC
            ", ARRAY_A );

            if ( ! $rows ) continue;

            // Group by entity
            $series_map = [];
            foreach ( $rows as $row ) {
                $series_map[ $row['entity_slug'] ]['name']       = $row['entity_name'];
                $series_map[ $row['entity_slug'] ]['data'][]     = [
                    'x'          => $row['period_date'],
                    'y'          => (float) $row['value'],
                    'value_text' => $row['value_text'],
                    'confidence' => $row['confidence'],
                    'source'     => $row['source'],
                    // Flag Wayback buckets for step-line rendering in JS
                    'stepline'   => $row['source'] === 'wayback-cdx' && $metric_key === 'active_installs',
                ];
            }

            $charts[] = [
                'id'     => 'chart-' . sanitize_html_class( $metric_key ),
                'title'  => $label,
                'metric' => $metric_key,
                'series' => array_values( $series_map ),
            ];
        }

        return $charts;
    }

    private function build_signals(): array {
        global $wpdb;

        $signals = [];

        // Review velocity — Δnum_ratings / Δdays per entity
        $self_ids = implode( ',', array_map( 'intval', $wpdb->get_col(
            "SELECT id FROM `{$wpdb->prefix}smi_entities` WHERE type IN ('self','competitor')"
        ) ) );

        if ( $self_ids ) {
            $latest_q = Schema::latest_metrics_query( $self_ids, 'num_ratings' );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rating_rows = $wpdb->get_results( "
                SELECT m.entity_id, m.value, m.period_date, e.name
                FROM ( {$latest_q} ) m
                JOIN `{$wpdb->prefix}smi_entities` e ON e.id = m.entity_id
                ORDER BY m.entity_id, m.period_date ASC
            ", ARRAY_A );

            $by_entity = [];
            foreach ( $rating_rows as $r ) {
                $by_entity[ $r['entity_id'] ][] = $r;
            }

            foreach ( $by_entity as $entity_id => $points ) {
                if ( count( $points ) < 2 ) continue;

                $first = $points[0];
                $last  = end( $points );
                $days  = max( 1, ( strtotime( $last['period_date'] ) - strtotime( $first['period_date'] ) ) / 86400 );
                $delta = ( (float) $last['value'] - (float) $first['value'] ) / $days;
                $flag  = $delta < 0 && $days >= 60;

                $signals[] = [
                    'signal' => 'Review velocity',
                    'entity' => esc_html( $last['name'] ),
                    'value'  => round( $delta, 2 ) . ' ratings/day',
                    'status' => $flag ? 'red' : 'green',
                    'note'   => $flag ? 'Declining for 60+ days' : '',
                ];
            }

            // Update cadence — days since last_updated
            $updated_q = Schema::latest_metrics_query( $self_ids, 'last_updated' );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $updated_rows = $wpdb->get_results( "
                SELECT m.entity_id, m.value_text, e.name
                FROM ( {$updated_q} ) m
                JOIN `{$wpdb->prefix}smi_entities` e ON e.id = m.entity_id
            ", ARRAY_A );

            foreach ( $updated_rows as $r ) {
                $days = $r['value_text'] ? (int) ( ( time() - strtotime( $r['value_text'] ) ) / 86400 ) : null;
                if ( $days === null ) continue;
                $signals[] = [
                    'signal' => 'Update cadence',
                    'entity' => esc_html( $r['name'] ),
                    'value'  => "{$days} days since last update",
                    'status' => $days > 180 ? 'red' : ( $days > 90 ? 'amber' : 'green' ),
                    'note'   => $days > 180 ? 'Possible abandonment' : '',
                ];
            }
        }

        return $signals;
    }

    private function build_collector_log(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            "SELECT collector, status, rows_written, error, ran_at
             FROM `{$wpdb->prefix}smi_collector_log`
             WHERE id IN (
                 SELECT MAX(id) FROM `{$wpdb->prefix}smi_collector_log`
                 GROUP BY collector
             )
             ORDER BY collector",
            ARRAY_A
        );
    }

    private function render_confidence_legend(): void {
        echo '<div class="smi-legend">';
        echo '<strong>Confidence:</strong>';
        foreach ( [
            'ground_truth' => 'Ground truth (authoritative source)',
            'high'         => 'High (live API, public)',
            'medium'       => 'Medium (scraped / unofficial)',
            'low'          => 'Low (estimate/proxy)',
            'manual'       => 'Manual (hand-entered)',
        ] as $key => $desc ) {
            printf(
                '<span><span class="smi-badge smi-badge-%s">%s</span> %s</span>',
                esc_attr( $key ),
                esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ),
                esc_html( $desc )
            );
        }
        echo '</div>';
    }

    private function render_signals_panel( array $signals ): void {
        echo '<h2>Derived Signals</h2>';
        echo '<table class="smi-signal-table widefat"><thead><tr>';
        echo '<th>Signal</th><th>Entity</th><th>Value</th><th>Status</th><th>Note</th>';
        echo '</tr></thead><tbody>';
        foreach ( $signals as $s ) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td><span class="smi-badge smi-badge-%s">%s</span></td><td>%s</td></tr>',
                esc_html( $s['signal'] ),
                esc_html( $s['entity'] ),
                esc_html( $s['value'] ),
                esc_attr( $s['status'] ),
                esc_html( ucfirst( $s['status'] ) ),
                esc_html( $s['note'] )
            );
        }
        echo '</tbody></table>';
    }

    private function render_collector_panel( array $log ): void {
        $manual_url = add_query_arg( 'tab', 'manual-entry', menu_page_url( 'soflyy-market-intel', false ) );

        echo '<h2>Collector Status</h2>';
        echo '<table class="smi-signal-table widefat"><thead><tr>';
        echo '<th>Collector</th><th>Last ran</th><th>Status</th><th>Rows written</th><th>Error / Action</th>';
        echo '</tr></thead><tbody>';

        foreach ( $log as $row ) {
            $status_badge = match ( $row['status'] ) {
                'ok'             => '<span class="smi-badge smi-badge-green">OK</span>',
                'manual_required'=> '<span class="smi-badge smi-badge-amber">Manual required</span>',
                default          => '<span class="smi-badge smi-badge-red">Error</span>',
            };
            $action = $row['status'] === 'manual_required'
                ? '<a href="' . esc_url( $manual_url ) . '">Go to Manual Entry →</a>'
                : esc_html( $row['error'] ?? '' );

            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( $row['collector'] ),
                esc_html( $row['ran_at'] ),
                $status_badge,
                esc_html( $row['rows_written'] ),
                $action
            );
        }
        echo '</tbody></table>';
    }
}

<?php
namespace Soflyy\MarketIntel\Collectors;

abstract class Collector {

    private int $total_written = 0;

    abstract public function slug(): string;

    /** Returns "daily", "weekly", "monthly", or "manual". */
    abstract public function schedule(): string;

    /** @return MetricRow[] */
    abstract protected function collect(): array;

    final public function run(): void {
        try {
            $rows = $this->collect();
            $this->write_rows( $rows );
            $this->log_run( 'ok', $this->total_written, null );
        } catch ( \Throwable $e ) {
            $this->log_run( 'error', 0, $e->getMessage() );
        }
    }

    final protected function write_rows( array $rows ): int {
        global $wpdb;
        $count = 0;
        $now   = current_time( 'mysql' );

        foreach ( $rows as $row ) {
            if ( ! $row instanceof MetricRow ) {
                throw new \LogicException( 'collect() must return MetricRow[].' );
            }
            // source is validated in MetricRow constructor; confidence is a typed enum.
            $wpdb->insert(
                $wpdb->prefix . 'smi_metrics',
                [
                    'entity_id'   => $row->entity_id,
                    'metric_key'  => $row->metric_key,
                    'value'       => $row->value,
                    'value_text'  => $row->value_text,
                    'confidence'  => $row->confidence->value,
                    'source'      => $row->source,
                    'captured_at' => $now,
                    'period_date' => $row->period_date->format( 'Y-m-d' ),
                ],
                [ '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
            );
            ++$count;
        }

        $this->total_written += $count;
        return $count;
    }

    /**
     * Graceful-degradation: logs manual_required, never writes a fabricated row.
     * Call this when a fragile/unofficial source fails instead of inventing data.
     */
    final protected function flag_manual_fallback(
        string $entity_slug,
        string $metric_key,
        string $reason,
        string $manual_section
    ): void {
        $this->log_run(
            'manual_required',
            0,
            sprintf(
                '[%s / %s] %s — Enter manually: %s',
                $entity_slug,
                $metric_key,
                $reason,
                $manual_section
            )
        );
    }

    private function log_run( string $status, int $rows, ?string $error ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'smi_collector_log',
            [
                'collector'    => $this->slug(),
                'status'       => $status,
                'rows_written' => $rows,
                'error'        => $error,
                'ran_at'       => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    protected function http_get( string $url, array $args = [] ): array|\WP_Error {
        $defaults = [
            'timeout'    => 15,
            'user-agent' => 'SMI-Collector/1.0',
            'headers'    => [],
        ];
        return wp_remote_get( $url, array_merge( $defaults, $args ) );
    }

    protected function wayback_get( string $url, array $args = [] ): array|\WP_Error {
        $response = $this->http_get( $url, $args );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 429 ) {
            return $response;
        }

        $retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
        if ( $retry_after !== '' ) {
            $wait = is_numeric( $retry_after )
                ? (int) $retry_after
                : max( 0, strtotime( $retry_after ) - time() );
        } else {
            $wait = 60;
        }

        sleep( $wait + 5 );

        return $this->http_get( $url, $args );
    }
}

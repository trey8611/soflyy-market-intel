<?php
namespace Soflyy\MarketIntel\Collectors;

class Registry {

    /** @var array<string, Collector> */
    private array $collectors = [];

    public function register( Collector $collector ): void {
        $this->collectors[ $collector->slug() ] = $collector;
    }

    public function get( string $slug ): ?Collector {
        return $this->collectors[ $slug ] ?? null;
    }

    /** @return Collector[] */
    public function all(): array {
        return $this->collectors;
    }

    /** @return Collector[] collectors whose schedule is due */
    public function due(): array {
        return array_filter( $this->collectors, fn( $c ) => $this->is_due( $c ) );
    }

    /**
     * Dispatch one collector by slug, or all due collectors if null.
     * A failure in one never aborts the others.
     *
     * @return array<string, array{rows: int, error: ?string}>
     */
    public function dispatch( ?string $slug = null ): array {
        $targets = $slug !== null
            ? array_filter( [ $slug => $this->get( $slug ) ] )
            : $this->due();

        $results = [];
        foreach ( $targets as $s => $collector ) {
            $collector->run();
            $results[ $s ] = [ 'rows' => $this->last_rows( $s ), 'error' => null ];
        }

        return $results;
    }

    private function is_due( Collector $c ): bool {
        if ( $c->schedule() === 'manual' ) {
            return false;
        }

        global $wpdb;
        $last = $wpdb->get_var( $wpdb->prepare(
            "SELECT ran_at FROM `{$wpdb->prefix}smi_collector_log`
             WHERE collector = %s AND status IN ('ok','manual_required')
             ORDER BY ran_at DESC LIMIT 1",
            $c->slug()
        ) );

        if ( ! $last ) {
            return true;
        }

        $hours_ago = ( time() - strtotime( $last ) ) / 3600;

        return match ( $c->schedule() ) {
            'daily'   => $hours_ago >= 23,
            'weekly'  => $hours_ago >= 167,
            'monthly' => $hours_ago >= 719,
            default   => false,
        };
    }

    private function last_rows( string $slug ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT rows_written FROM `{$wpdb->prefix}smi_collector_log`
             WHERE collector = %s ORDER BY ran_at DESC LIMIT 1",
            $slug
        ) );
    }
}

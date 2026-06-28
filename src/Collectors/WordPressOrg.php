<?php
namespace Soflyy\MarketIntel\Collectors;

use Soflyy\MarketIntel\Database\Schema;
use Soflyy\MarketIntel\Enums\Confidence;

class WordPressOrg extends Collector {

    public const METRIC_ACTIVE_INSTALLS = 'active_installs';
    public const METRIC_RATING          = 'rating';
    public const METRIC_NUM_RATINGS     = 'num_ratings';
    public const METRIC_DOWNLOADED      = 'downloaded';
    public const METRIC_LAST_UPDATED    = 'last_updated';
    public const METRIC_VERSION         = 'version';

    public function slug(): string     { return 'wporg'; }
    public function schedule(): string { return 'daily'; }

    protected function collect(): array {
        $entities = Schema::get_entities_with_wporg_slug();
        $rows     = [];
        $today    = new \DateTimeImmutable( 'today' );

        foreach ( $entities as $entity ) {
            $url      = "https://api.wordpress.org/plugins/info/1.0/{$entity['wporg_slug']}.json";
            $response = $this->http_get( $url );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                $this->flag_manual_fallback(
                    $entity['slug'],
                    self::METRIC_ACTIVE_INSTALLS,
                    'WP.org API request failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ),
                    'Manual Entry → General metric'
                );
                continue;
            }

            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $data ) ) {
                $this->flag_manual_fallback(
                    $entity['slug'],
                    self::METRIC_ACTIVE_INSTALLS,
                    'WP.org response was not valid JSON',
                    'Manual Entry → General metric'
                );
                continue;
            }

            $id = (int) $entity['id'];

            $numeric = [
                self::METRIC_ACTIVE_INSTALLS => $data['active_installs'] ?? null,
                self::METRIC_RATING          => isset( $data['rating'] ) ? (float) $data['rating'] : null,
                self::METRIC_NUM_RATINGS     => $data['num_ratings'] ?? null,
                self::METRIC_DOWNLOADED      => $data['downloaded'] ?? null,
            ];

            foreach ( $numeric as $key => $val ) {
                if ( $val === null ) continue;
                $rows[] = new MetricRow( $id, $key, (float) $val, null, Confidence::High, 'wporg-api', $today );
            }

            foreach ( [ self::METRIC_LAST_UPDATED => 'last_updated', self::METRIC_VERSION => 'version' ] as $key => $field ) {
                if ( ! empty( $data[ $field ] ) ) {
                    $rows[] = new MetricRow( $id, $key, null, (string) $data[ $field ], Confidence::High, 'wporg-api', $today );
                }
            }
        }

        return $rows;
    }
}

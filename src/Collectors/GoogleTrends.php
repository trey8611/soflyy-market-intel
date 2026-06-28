<?php
namespace Soflyy\MarketIntel\Collectors;

use Soflyy\MarketIntel\Enums\Confidence;

class GoogleTrends extends Collector {

    // Upgrade path: replace with SerpAPI or DataForSEO for a stable paid feed.
    // Do not build it here.
    private const ENDPOINT = 'https://trends.google.com/trends/api/multiline';

    private const TERMS = [
        'WordPress', 'Wix', 'Shopify', 'Squarespace', 'Webflow', 'Joomla',
        'WP All Import', 'WP All Export',
    ];

    public function slug(): string     { return 'google-trends'; }
    public function schedule(): string { return 'weekly'; }

    protected function collect(): array {
        $url = add_query_arg( [
            'hl'      => 'en-US',
            'tz'      => '-60',
            'req'     => json_encode( [
                'comparisonItem' => array_map( fn( $t ) => [ 'keyword' => $t, 'geo' => '', 'time' => 'today 12-m' ], self::TERMS ),
                'category'       => 0,
                'property'       => '',
            ] ),
            'token'   => '',
            'tz'      => '-60',
        ], self::ENDPOINT );

        $response = $this->http_get( $url, [
            'headers' => [ 'Accept' => 'application/json' ],
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            foreach ( self::TERMS as $term ) {
                $this->flag_manual_fallback( sanitize_title( $term ), 'search_interest', 'Google Trends request failed or blocked', 'Manual Entry → Google Trends' );
            }
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        // Unofficial endpoint prepends ")]}',\n" — strip it.
        $body = preg_replace( '/^\)\]\}\'.*?\n/s', '', $body );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            foreach ( self::TERMS as $term ) {
                $this->flag_manual_fallback( sanitize_title( $term ), 'search_interest', 'Google Trends JSON parse failed', 'Manual Entry → Google Trends' );
            }
            return [];
        }

        return $this->parse_trends( $data );
    }

    private function parse_trends( array $data ): array {
        global $wpdb;
        $rows     = [];
        $timeline = $data['default']['timelineData'] ?? [];

        foreach ( $timeline as $point ) {
            $date   = \DateTimeImmutable::createFromFormat( 'U', $point['time'] ?? 0 );
            $values = $point['value'] ?? [];

            foreach ( self::TERMS as $i => $term ) {
                $slug    = sanitize_title( $term );
                $entity  = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM `{$wpdb->prefix}smi_entities` WHERE slug = %s", $slug
                ) );
                if ( ! $entity || ! isset( $values[ $i ] ) ) continue;

                $rows[] = new MetricRow(
                    (int) $entity,
                    'search_interest',
                    (float) $values[ $i ],
                    null,
                    Confidence::Medium,
                    'google-trends-unofficial',
                    $date
                );
            }
        }

        return $rows;
    }
}

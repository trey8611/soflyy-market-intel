<?php
namespace Soflyy\MarketIntel\Collectors;

use Soflyy\MarketIntel\Database\Schema;
use Soflyy\MarketIntel\Enums\Confidence;

class BuiltWith extends Collector {

    // BuiltWith has no free public API. This scraper is fragile;
    // manual entry is the expected fallback.
    private const WC_URL       = 'https://trends.builtwith.com/shop/WooCommerce';
    private const SHOPIFY_URL  = 'https://trends.builtwith.com/shop/Shopify';

    public function slug(): string     { return 'builtwith'; }
    public function schedule(): string { return 'weekly'; }

    protected function collect(): array {
        $rows  = [];
        $today = new \DateTimeImmutable( 'today' );

        foreach ( [
            [ 'woocommerce', self::WC_URL,      'builtwith_store_count' ],
            [ 'shopify',     self::SHOPIFY_URL,  'builtwith_store_count' ],
        ] as [ $slug, $url, $metric ] ) {
            $response = $this->http_get( $url );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                $this->flag_manual_fallback( $slug, $metric, 'BuiltWith fetch failed', 'Manual Entry → BuiltWith' );
                continue;
            }

            $body  = wp_remote_retrieve_body( $response );
            $count = $this->parse_store_count( $body );

            if ( $count === null ) {
                $this->flag_manual_fallback( $slug, $metric, 'BuiltWith parse failed', 'Manual Entry → BuiltWith' );
                continue;
            }

            $entity_id = Schema::get_entity_id( $slug );
            if ( ! $entity_id ) continue;

            $rows[] = new MetricRow( $entity_id, $metric, $count, null, Confidence::Medium, 'builtwith-scrape', $today );
        }

        return $rows;
    }

    private function parse_store_count( string $html ): ?float {
        // BuiltWith renders counts like "4,567,890 Live Websites"
        if ( preg_match( '/([\d,]+)\s+Live\s+Websites/i', $html, $m ) ) {
            return (float) str_replace( ',', '', $m[1] );
        }
        return null;
    }
}

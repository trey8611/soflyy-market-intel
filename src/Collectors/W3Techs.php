<?php
namespace Soflyy\MarketIntel\Collectors;

class W3Techs extends Collector {

    public function slug(): string     { return 'w3techs'; }
    public function schedule(): string { return 'weekly'; }

    // W3Techs has no public API. Copy figures from
    // w3techs.com/technologies/overview/cms manually.
    protected function collect(): array {
        $platforms = [ 'wordpress', 'wix', 'shopify', 'squarespace', 'webflow', 'joomla' ];

        foreach ( $platforms as $slug ) {
            $this->flag_manual_fallback( $slug, 'cms_market_share',        'W3Techs has no public API', 'Manual Entry → W3Techs' );
            $this->flag_manual_fallback( $slug, 'ecommerce_market_share',  'W3Techs has no public API', 'Manual Entry → W3Techs' );
        }

        return [];
    }
}

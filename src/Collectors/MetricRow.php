<?php
namespace Soflyy\MarketIntel\Collectors;

use Soflyy\MarketIntel\Enums\Confidence;

readonly class MetricRow {
    public function __construct(
        public int               $entity_id,
        public string            $metric_key,
        public ?float            $value,
        public ?string           $value_text,
        public Confidence        $confidence,
        public string            $source,
        public \DateTimeImmutable $period_date,
    ) {
        if ( $this->source === '' ) {
            throw new \LogicException( 'MetricRow source must not be empty.' );
        }
    }
}

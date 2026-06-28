<?php
namespace Soflyy\MarketIntel\Enums;

enum EntityType: string {
    case Platform   = 'platform';
    case Plugin     = 'plugin';
    case Competitor = 'competitor';
    case Self       = 'self';
}

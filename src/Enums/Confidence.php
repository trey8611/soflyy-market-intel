<?php
namespace Soflyy\MarketIntel\Enums;

enum Confidence: string {
    case GroundTruth = 'ground_truth';
    case High        = 'high';
    case Medium      = 'medium';
    case Low         = 'low';
    case Manual      = 'manual';
}

<?php

namespace App\Domain\Costs\Enums;

/**
 * How strong the evidence behind a cost item is. Actual replaces a
 * quote; the two are never added.
 */
enum EvidenceState: string
{
    case Actual = 'actual';
    case Estimate = 'estimate';
    case Quote = 'quote';
    case Manual = 'manual';
}

<?php

namespace App\Domain\Providers\Enums;

/**
 * Independent provider capabilities. Each keeps its own support, health,
 * attempt, success, and observation state: fresh inventory must not
 * imply fresh prices.
 */
enum ProviderCapability: string
{
    case Inventory = 'inventory';
    case Subscriptions = 'subscriptions';
    case RenewalQuotes = 'renewal_quotes';
    case Usage = 'usage';
    case Invoices = 'invoices';

    /**
     * Capabilities carried by the cost-facts phase, as opposed to the
     * inventory phase.
     *
     * @return list<self>
     */
    public static function costCapabilities(): array
    {
        return [self::Subscriptions, self::RenewalQuotes, self::Usage, self::Invoices];
    }
}

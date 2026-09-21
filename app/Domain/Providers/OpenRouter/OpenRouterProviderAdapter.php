<?php

namespace App\Domain\Providers\OpenRouter;

use App\Domain\Costs\Enums\AllocationState;
use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Enums\TaxBasis;
use App\Domain\Providers\Contracts\ProviderAdapter;
use App\Domain\Providers\Dtos\CapabilitySet;
use App\Domain\Providers\Dtos\CostFact;
use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\CredentialCheck;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\InventoryItem;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Support\ValueObjects\Money;

final class OpenRouterProviderAdapter implements ProviderAdapter
{
    public function __construct(private readonly BuildOpenRouterApi $buildApi) {}

    public function validateCredentials(SyncContext $context): CredentialCheck
    {
        try {
            $this->buildApi->build($context->credentials)->get('/auth/key');
        } catch (InvalidCredentialsException $exception) {
            return CredentialCheck::invalid($exception->getMessage());
        }

        return CredentialCheck::valid();
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet([
            ProviderCapability::Inventory,
            ProviderCapability::Usage,
        ]);
    }

    public function fetchInventory(SyncContext $context): InventoryBatch
    {
        $api = $this->buildApi->build($context->credentials);
        $warnings = [];

        try {
            $keyResponse = $api->get('/auth/key');
            $data = is_array($keyResponse['data'] ?? null) ? $keyResponse['data'] : [];
            $label = is_string($data['label'] ?? null) && $data['label'] !== '' ? $data['label'] : 'Default Key';
        } catch (InvalidCredentialsException $exception) {
            throw $exception;
        } catch (ProviderException $exception) {
            $warnings[] = "OpenRouter key discovery failed: {$exception->getMessage()}";
            $label = 'Default Key';
        }

        $items = [
            new InventoryItem(
                externalId: 'openrouter:key',
                category: 'ai',
                name: "OpenRouter ({$label})",
                providerType: 'api_key',
            ),
        ];

        return new InventoryBatch(
            completeness: $warnings === [] ? BatchCompleteness::Complete : BatchCompleteness::Partial,
            observedAt: $context->now,
            sourceRef: 'openrouter:/auth/key',
            items: $items,
            warnings: $warnings,
        );
    }

    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
    {
        $api = $this->buildApi->build($context->credentials);
        $warnings = [];
        $facts = [];
        $usageMinor = null;
        $currency = 'USD';

        try {
            $creditsResponse = $api->get('/credits');
            $creditsData = is_array($creditsResponse['data'] ?? null) ? $creditsResponse['data'] : [];

            if (isset($creditsData['total_usage']) && is_numeric($creditsData['total_usage'])) {
                $usageMinor = $this->toMinor((string) $creditsData['total_usage']);
            }
        } catch (InvalidCredentialsException $exception) {
            throw $exception;
        } catch (ProviderException $exception) {
            $warnings[] = "OpenRouter credits fetching failed: {$exception->getMessage()}";
        }

        // Fallback to /auth/key usage if /credits didn't report it
        if ($usageMinor === null) {
            try {
                $keyResponse = $api->get('/auth/key');
                $keyData = is_array($keyResponse['data'] ?? null) ? $keyResponse['data'] : [];

                if (isset($keyData['usage']) && is_numeric($keyData['usage'])) {
                    $usageMinor = $this->toMinor((string) $keyData['usage']);
                }
            } catch (InvalidCredentialsException $exception) {
                throw $exception;
            } catch (ProviderException $exception) {
                $warnings[] = "OpenRouter key usage fallback failed: {$exception->getMessage()}";
            }
        }

        $amount = $usageMinor !== null ? Money::ofMinor($usageMinor, $currency) : null;

        $facts[] = new CostFact(
            sourceRef: 'openrouter:usage:key',
            serviceExternalIds: ['openrouter:key'],
            sourceKind: SourceKind::Usage,
            chargeKind: ChargeKind::Usage,
            period: Period::Monthly,
            evidenceState: EvidenceState::Actual,
            amount: $amount,
            validFrom: $context->now->startOfMonth(),
            taxBasis: TaxBasis::Unknown,
            renewsAt: null,
            autoRenew: false,
            allocationState: AllocationState::Direct,
            notes: 'OpenRouter AI model usage',
        );

        $completeness = $warnings === [] ? BatchCompleteness::Complete : BatchCompleteness::Partial;

        return new CostFactBatch(
            completeness: $completeness,
            observedAt: $context->now,
            sourceRef: 'openrouter:usage',
            facts: $facts,
            warnings: $warnings,
            capabilityCompleteness: [
                ProviderCapability::Usage->value => $completeness,
            ],
            reportedCapabilities: [ProviderCapability::Usage],
        );
    }

    private function toMinor(string $value): ?int
    {
        try {
            return Money::ofString(trim($value), 'USD')->amountMinor;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}

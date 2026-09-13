<?php
if (!defined('ABSPATH')) exit;

/** @return array<string,mixed>|null */
function nps_core_get_card(string $gameId, string $cardId): ?array
{
    return (new \NPS\Core\CardCatalog())->get($gameId, $cardId);
}

/**
 * @param array<string,mixed> $criteria
 * @return array<int,array<string,mixed>>
 */
function nps_core_search_cards(string $gameId, array $criteria, int $limit = 25): array
{
    return (new \NPS\Core\CardCatalog())->search($gameId, $criteria, $limit);
}

/** @param array<string,mixed> $definition */
function nps_core_register_data_provider(array $definition): void
{
    \NPS\Core\DataProviderRegistry::instance()->register($definition);
}

/** @return array<string,mixed>|null */
function nps_core_get_data_provider(string $providerId): ?array
{
    return \NPS\Core\DataProviderRegistry::instance()->get($providerId);
}

/** @return array<string,mixed> */
function nps_core_get_data_provider_settings(string $providerId): array
{
    return \NPS\Core\DataProviderSettings::get($providerId);
}

function nps_core_get_data_provider_credential(string $providerId, string $key): string
{
    return \NPS\Core\DataProviderSettings::credential($providerId, $key);
}

/** @return array<string,mixed> */
function nps_core_data_provider_can_request(string $providerId, int $cost = 1): array
{
    return (new \NPS\Core\DataProviderQuota())->canSpend($providerId, $cost);
}

function nps_core_data_provider_record_request(
    string $providerId,
    int $statusCode = 0,
    int $cost = 1,
    string $endpoint = '',
    string $outcome = '',
    string $message = ''
): int {
    return (new \NPS\Core\DataProviderStore())->recordRequest(
        $providerId,
        $statusCode,
        $cost,
        $endpoint,
        $outcome,
        $message
    );
}

/** @param array<string,mixed> $meta */
function nps_core_data_provider_store_raw(
    string $providerId,
    string $resourceKey,
    string $payload,
    int $statusCode = 200,
    string $requestUrl = '',
    string $contentType = 'application/json',
    array $meta = []
): int {
    return (new \NPS\Core\DataProviderStore())->storeRaw(
        $providerId,
        $resourceKey,
        $payload,
        $statusCode,
        $requestUrl,
        $contentType,
        $meta
    );
}

/** @param array<string,mixed> $job */
function nps_core_data_provider_save_job(string $providerId, string $jobKey, array $job): int
{
    return (new \NPS\Core\DataProviderStore())->saveJob($providerId, $jobKey, $job);
}

/** @return array<string,mixed>|null */
function nps_core_data_provider_get_job(string $providerId, string $jobKey): ?array
{
    return (new \NPS\Core\DataProviderStore())->getJob($providerId, $jobKey);
}

/** @param array<string,mixed> $resource */
function nps_core_data_provider_save_resource(string $providerId, string $resourceKey, array $resource): int
{
    return (new \NPS\Core\DataProviderStore())->saveResourceState($providerId, $resourceKey, $resource);
}

/** @return array<string,mixed>|null */
function nps_core_data_provider_get_resource(string $providerId, string $resourceKey): ?array
{
    return (new \NPS\Core\DataProviderStore())->getResourceState($providerId, $resourceKey);
}

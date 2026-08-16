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

<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

/**
 * Game-agnostic lookup facade over the shared local card index.
 * Consumers only know stable game/card IDs and normalized card fields.
 */
final class CardCatalog
{
    private LocalCardIndex $index;

    public function __construct(?LocalCardIndex $index = null)
    {
        $this->index = $index ?: new LocalCardIndex();
    }

    /** @return array<string,mixed>|null */
    public function get(string $gameId, string $cardId): ?array
    {
        return $this->index->get($gameId, $cardId);
    }

    /**
     * @param array<string,mixed> $criteria
     * @return array<int,array<string,mixed>>
     */
    public function search(string $gameId, array $criteria, int $limit = 25): array
    {
        return $this->index->searchCriteria($gameId, $criteria, $limit);
    }

    public function count(string $gameId): int
    {
        return $this->index->count($gameId);
    }
}

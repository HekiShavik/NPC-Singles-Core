<?php

namespace NPS\Core\Admin;

use NPS\Core\Http;
use NPS\Core\LocalCardIndex;

if (!defined('ABSPATH')) exit;

final class LocalCardSearchController
{
    private LocalCardIndex $index;
    private string $gameId;
    private string $nonceAction;
    private string $ajaxAction;

    public function __construct(LocalCardIndex $index, string $gameId, string $nonceAction, string $ajaxAction)
    {
        $this->index = $index;
        $this->gameId = sanitize_key($gameId);
        $this->nonceAction = $nonceAction;
        $this->ajaxAction = sanitize_key($ajaxAction);
    }

    public function init(): void
    {
        add_action('wp_ajax_' . $this->ajaxAction, [$this, 'search']);
    }

    public function search(): void
    {
        check_ajax_referer($this->nonceAction, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            Http::fail('No permission', 403, 'no_access_local_search');
        }

        $term = trim((string)wp_unslash($_POST['term'] ?? ''));
        $termLength = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term);
        if ($termLength < 2) {
            Http::ok(['items' => [], 'count' => 0, 'indexed' => $this->index->count($this->gameId)]);
        }

        $items = $this->index->search($this->gameId, $term, 60);
        Http::ok([
            'items' => $items,
            'count' => count($items),
            'indexed' => $this->index->count($this->gameId),
        ]);
    }
}

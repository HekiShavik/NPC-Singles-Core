<?php if (!defined('ABSPATH')) exit; ?>
<?php
$historySlug = (string)$config['history_slug'];
$cssPrefix = (string)$config['css_prefix'];
$title = (string)$config['history_page_title'];
$rollbackAction = (string)$config['rollback_action'];
$noncePrefix = (string)$config['rollback_nonce_prefix'];
$finishLabelCallback = $config['finish_label'] ?? null;

$baseUrl = admin_url('edit.php?post_type=product&page=' . rawurlencode($historySlug));
$currentUrlArgs = [
    'post_type' => 'product',
    'page' => $historySlug,
    'setId' => $set_id,
    'perPage' => $per_page,
];

$formatDelta = static function (int $n): string {
    return $n > 0 ? '+' . $n : (string)$n;
};

$actionLabel = static function (string $action): string {
    $labels = [
        'created_product' => 'Oprettet',
        'stock_changed' => 'Lager',
        'deleted_product' => 'Slettet',
        'rollback_created_product' => 'Rollback oprettelse',
        'rollback_stock_changed' => 'Rollback lager',
        'rollback_deleted_product' => 'Rollback sletning',
    ];
    return $labels[$action] ?? ($action !== '' ? $action : 'Ændring');
};

$finishLabel = static function (string $finish) use ($finishLabelCallback): string {
    if (is_callable($finishLabelCallback)) {
        return (string)$finishLabelCallback($finish);
    }
    return $finish;
};

$lineTitle = static function (array $line): string {
    $number = trim((string)($line['card_number'] ?? ''));
    $name = trim((string)($line['card_name'] ?? ''));
    $cardId = trim((string)($line['card_id'] ?? ''));
    $left = trim(($number !== '' ? $number . ' ' : '') . ($name !== '' ? $name : $cardId));
    return $left !== '' ? $left : 'Ukendt kort';
};
?>

<div class="wrap <?php echo esc_attr($cssPrefix); ?>-wrap <?php echo esc_attr($cssPrefix); ?>-history-page">
    <h1><?php echo esc_html($title); ?></h1>

    <?php if ($notice !== ''): ?>
        <div class="notice notice-<?php echo $notice_type === 'error' ? 'error' : 'success'; ?> is-dismissible">
            <p><?php echo esc_html($notice); ?></p>
        </div>
    <?php endif; ?>

    <div class="<?php echo esc_attr($cssPrefix); ?>-card <?php echo esc_attr($cssPrefix); ?>-history-page__filters">
        <form method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>">
            <input type="hidden" name="post_type" value="product">
            <input type="hidden" name="page" value="<?php echo esc_attr($historySlug); ?>">

            <label for="<?php echo esc_attr($cssPrefix); ?>_history_set"><strong>Sæt</strong></label>
            <select id="<?php echo esc_attr($cssPrefix); ?>_history_set" name="setId">
                <option value="">Alle sæt</option>
                <?php foreach ($sets as $set): ?>
                    <?php
                    $sid = (string)($set['set_id'] ?? '');
                    $sname = (string)($set['set_name'] ?? '');
                    $count = (int)($set['transaction_count'] ?? 0);
                    $label = trim($sname !== '' ? $sname : $sid);
                    if ($label === '') $label = $sid;
                    ?>
                    <option value="<?php echo esc_attr($sid); ?>" <?php selected($set_id, $sid); ?>>
                        <?php echo esc_html($label . ' (' . $count . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="<?php echo esc_attr($cssPrefix); ?>_history_per_page"><strong>Pr. side</strong></label>
            <select id="<?php echo esc_attr($cssPrefix); ?>_history_per_page" name="perPage">
                <?php foreach ([10, 20, 50, 100] as $n): ?>
                    <option value="<?php echo (int)$n; ?>" <?php selected($per_page, $n); ?>><?php echo (int)$n; ?></option>
                <?php endforeach; ?>
            </select>

            <button class="button button-primary" type="submit">Filtrér</button>
            <a class="button" href="<?php echo esc_url($baseUrl); ?>">Nulstil</a>
        </form>
    </div>

    <div class="<?php echo esc_attr($cssPrefix); ?>-card">
        <div class="<?php echo esc_attr($cssPrefix); ?>-history-page__summary">
            <strong><?php echo esc_html((string)$total); ?></strong> transaktioner
            <?php if ($set_id !== ''): ?> for <code><?php echo esc_html($set_id); ?></code><?php endif; ?>
            · side <?php echo esc_html((string)$paged); ?> / <?php echo esc_html((string)$total_pages); ?>
        </div>

        <?php if (!$transactions): ?>
            <p class="<?php echo esc_attr($cssPrefix); ?>-muted">Ingen transaktioner matcher filteret.</p>
        <?php else: ?>
            <div class="<?php echo esc_attr($cssPrefix); ?>-history-page__list">
                <?php foreach ($transactions as $tx): ?>
                    <?php
                    $txId = (int)($tx['id'] ?? 0);
                    $isRollback = (string)($tx['type'] ?? '') === 'rollback';
                    $rolledBack = trim((string)($tx['rolled_back_at'] ?? '')) !== '';
                    $owner = (string)($tx['owner_display_name'] ?: ($tx['owner_user_login'] ?? 'Ukendt'));
                    $lines = (array)($tx['lines'] ?? []);
                    $txTitle = $isRollback ? 'Rollback #' . $txId . (!empty($tx['rollback_of']) ? ' af #' . (int)$tx['rollback_of'] : '') : 'Transaktion #' . $txId;
                    ?>
                    <article class="<?php echo esc_attr($cssPrefix); ?>-history-page__tx" id="tx-<?php echo (int)$txId; ?>">
                        <div class="<?php echo esc_attr($cssPrefix); ?>-history-page__txhead">
                            <div>
                                <h2><?php echo esc_html($txTitle); ?></h2>
                                <p class="<?php echo esc_attr($cssPrefix); ?>-muted">
                                    <?php echo esc_html((string)($tx['set_name'] ?: $tx['set_id'])); ?>
                                    · ejer: <?php echo esc_html($owner); ?>
                                    · <?php echo esc_html((string)($tx['updated_at'] ?: $tx['created_at'])); ?>
                                    · netto <?php echo esc_html($formatDelta((int)($tx['delta_total'] ?? 0))); ?>
                                    · <?php echo esc_html((string)($tx['line_count'] ?? count($lines))); ?> linjer
                                </p>
                            </div>
                            <div class="<?php echo esc_attr($cssPrefix); ?>-history-page__txactions">
                                <?php if ($rolledBack): ?>
                                    <span class="<?php echo esc_attr($cssPrefix); ?>-status <?php echo esc_attr($cssPrefix); ?>-status--muted">Rullet tilbage</span>
                                <?php elseif ($isRollback): ?>
                                    <span class="<?php echo esc_attr($cssPrefix); ?>-status <?php echo esc_attr($cssPrefix); ?>-status--muted">Rollback</span>
                                <?php else: ?>
                                    <span class="<?php echo esc_attr($cssPrefix); ?>-status">Aktiv</span>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Rul transaktion #<?php echo (int)$txId; ?> tilbage?\n\nRollback kører kun hvis lageret stadig matcher transaktionens slutværdier.');">
                                        <input type="hidden" name="action" value="<?php echo esc_attr($rollbackAction); ?>">
                                        <input type="hidden" name="transactionId" value="<?php echo (int)$txId; ?>">
                                        <input type="hidden" name="setId" value="<?php echo esc_attr($set_id); ?>">
                                        <input type="hidden" name="paged" value="<?php echo (int)$paged; ?>">
                                        <input type="hidden" name="perPage" value="<?php echo (int)$per_page; ?>">
                                        <?php wp_nonce_field($noncePrefix . $txId); ?>
                                        <button type="submit" class="button button-secondary">Rollback</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <details class="<?php echo esc_attr($cssPrefix); ?>-history-page__details" <?php echo count($transactions) === 1 ? 'open' : ''; ?>>
                            <summary>Vis <?php echo esc_html((string)count($lines)); ?> linjer</summary>
                            <table class="widefat striped <?php echo esc_attr($cssPrefix); ?>-history-page__linetable">
                                <thead><tr><th>Kort</th><th>Finish</th><th>Handling</th><th class="<?php echo esc_attr($cssPrefix); ?>-history-page__num">Før</th><th class="<?php echo esc_attr($cssPrefix); ?>-history-page__num">Efter</th><th class="<?php echo esc_attr($cssPrefix); ?>-history-page__num">Netto</th></tr></thead>
                                <tbody>
                                    <?php foreach ($lines as $line): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($lineTitle($line)); ?></strong><?php if (!empty($line['card_id'])): ?><br><code><?php echo esc_html((string)$line['card_id']); ?></code><?php endif; ?></td>
                                            <td><?php echo esc_html($finishLabel((string)($line['finish'] ?? ''))); ?></td>
                                            <td><?php echo esc_html($actionLabel((string)($line['action'] ?? ''))); ?></td>
                                            <td class="<?php echo esc_attr($cssPrefix); ?>-history-page__num"><?php echo esc_html((string)(int)($line['qty_before'] ?? 0)); ?></td>
                                            <td class="<?php echo esc_attr($cssPrefix); ?>-history-page__num"><?php echo esc_html((string)(int)($line['qty_after'] ?? 0)); ?></td>
                                            <td class="<?php echo esc_attr($cssPrefix); ?>-history-page__num"><?php echo esc_html($formatDelta((int)($line['delta_qty'] ?? 0))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom <?php echo esc_attr($cssPrefix); ?>-history-page__pagination"><div class="tablenav-pages">
                <?php
                $prevUrl = add_query_arg($currentUrlArgs + ['paged' => max(1, $paged - 1)], admin_url('edit.php'));
                $nextUrl = add_query_arg($currentUrlArgs + ['paged' => min($total_pages, $paged + 1)], admin_url('edit.php'));
                ?>
                <span class="pagination-links">
                    <?php if ($paged > 1): ?><a class="button" href="<?php echo esc_url($prevUrl); ?>">‹ Forrige</a><?php else: ?><span class="button disabled">‹ Forrige</span><?php endif; ?>
                    <span class="paging-input"><?php echo esc_html((string)$paged); ?> af <?php echo esc_html((string)$total_pages); ?></span>
                    <?php if ($paged < $total_pages): ?><a class="button" href="<?php echo esc_url($nextUrl); ?>">Næste ›</a><?php else: ?><span class="button disabled">Næste ›</span><?php endif; ?>
                </span>
            </div></div>
        <?php endif; ?>
    </div>
</div>

<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

class InventoryHistory
{
    public const DB_VERSION = '1.0.0';

    private string $transactionsTable;
    private string $linesTable;
    private string $dbPrefix;
    private string $optionKey;
    private array $metaKeys;
    private string $stockMeta = '_stock';

    public function __construct(string $dbPrefix, string $optionKey, array $metaKeys)
    {
        global $wpdb;
        $this->dbPrefix = trim($dbPrefix, '_');
        $this->optionKey = $optionKey;
        $this->metaKeys = $metaKeys;
        $this->transactionsTable = $wpdb->prefix . $this->dbPrefix . '_inventory_transactions';
        $this->linesTable = $wpdb->prefix . $this->dbPrefix . '_inventory_transaction_lines';
        $this->maybeInstall();
    }

    public static function installFor(string $dbPrefix, string $optionKey): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $dbPrefix = trim($dbPrefix, '_');
        $transactionsTable = $wpdb->prefix . $dbPrefix . '_inventory_transactions';
        $linesTable = $wpdb->prefix . $dbPrefix . '_inventory_transaction_lines';
        $charset = $wpdb->get_charset_collate();

        $sqlTransactions = "CREATE TABLE {$transactionsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(32) NOT NULL DEFAULT 'normal',
            rollback_of bigint(20) unsigned DEFAULT NULL,
            set_id varchar(64) NOT NULL DEFAULT '',
            set_name text NULL,
            lang varchar(16) NOT NULL DEFAULT '',
            owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            owner_user_login varchar(191) NOT NULL DEFAULT '',
            owner_display_name varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            rolled_back_at datetime DEFAULT NULL,
            rolled_back_by_user_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY owner_user_updated (owner_user_id, updated_at),
            KEY set_id_updated (set_id, updated_at),
            KEY rollback_of (rollback_of),
            KEY type_updated (type, updated_at)
        ) {$charset};";

        $sqlLines = "CREATE TABLE {$linesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            transaction_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            card_id varchar(191) NOT NULL DEFAULT '',
            card_number varchar(64) NOT NULL DEFAULT '',
            card_name text NULL,
            finish varchar(64) NOT NULL DEFAULT '',
            lang varchar(16) NOT NULL DEFAULT '',
            action varchar(64) NOT NULL DEFAULT '',
            qty_before int(11) NOT NULL DEFAULT 0,
            qty_after int(11) NOT NULL DEFAULT 0,
            delta_qty int(11) NOT NULL DEFAULT 0,
            meta_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY transaction_id (transaction_id),
            KEY product_id (product_id),
            KEY card_lookup (card_id, finish, lang)
        ) {$charset};";

        dbDelta($sqlTransactions);
        dbDelta($sqlLines);
        update_option($optionKey, self::DB_VERSION, false);
    }

    private function maybeInstall(): void
    {
        if ((string)get_option($this->optionKey, '') !== self::DB_VERSION) {
            self::installFor($this->dbPrefix, $this->optionKey);
        }
    }

    private function meta(string $logicalName, string $fallback = ''): string
    {
        return (string)($this->metaKeys[$logicalName] ?? $fallback);
    }

    public function recordCreatedProduct(
        string $setId,
        array $setInfo,
        int $productId,
        string $cardId,
        string $cardNumber,
        string $cardName,
        string $finish,
        string $lang,
        int $qtyBefore,
        int $qtyAfter,
        string $source = 'bulk_create'
    ): void {
        $this->appendNormalLine(
            $setId,
            (string)($setInfo['name'] ?? ''),
            strtoupper($lang),
            [
                'product_id' => $productId,
                'card_id' => $cardId,
                'card_number' => $cardNumber,
                'card_name' => $cardName,
                'finish' => $finish,
                'lang' => strtoupper($lang),
                'action' => 'created_product',
                'qty_before' => max(0, $qtyBefore),
                'qty_after' => max(0, $qtyAfter),
                'meta' => [
                    'source' => $source,
                    'set' => $this->cleanSetInfo($setInfo + ['id' => $setId]),
                ],
            ]
        );
    }

    public function recordStockChangeForProduct(int $productId, int $qtyBefore, int $qtyAfter, string $source = 'stock_change'): void
    {
        $qtyBefore = max(0, $qtyBefore);
        $qtyAfter = max(0, $qtyAfter);
        if ($qtyBefore === $qtyAfter) return;

        $snap = $this->snapshotProduct($productId);
        if (!$snap) return;

        $this->appendNormalLine(
            (string)$snap['set_id'],
            (string)$snap['set_name'],
            (string)$snap['lang'],
            [
                'product_id' => $productId,
                'card_id' => (string)$snap['card_id'],
                'card_number' => (string)$snap['card_number'],
                'card_name' => (string)$snap['card_name'],
                'finish' => (string)$snap['finish'],
                'lang' => (string)$snap['lang'],
                'action' => 'stock_changed',
                'qty_before' => $qtyBefore,
                'qty_after' => $qtyAfter,
                'meta' => [
                    'source' => $source,
                    'post_status' => (string)$snap['post_status'],
                ],
            ]
        );
    }

    public function recordDeletedProduct(int $productId, string $source = 'delete_product'): void
    {
        $snap = $this->snapshotProduct($productId);
        if (!$snap) return;

        $qtyBefore = max(0, (int)$snap['stock']);

        $this->appendNormalLine(
            (string)$snap['set_id'],
            (string)$snap['set_name'],
            (string)$snap['lang'],
            [
                'product_id' => $productId,
                'card_id' => (string)$snap['card_id'],
                'card_number' => (string)$snap['card_number'],
                'card_name' => (string)$snap['card_name'],
                'finish' => (string)$snap['finish'],
                'lang' => (string)$snap['lang'],
                'action' => 'deleted_product',
                'qty_before' => $qtyBefore,
                'qty_after' => 0,
                'meta' => [
                    'source' => $source,
                    'rollback_note' => 'Fysisk slettede produkter kan ikke genskabes automatisk i V1.',
                    'post_title' => (string)$snap['post_title'],
                    'post_status' => (string)$snap['post_status'],
                ],
            ]
        );
    }

    public function listTransactions(string $setId = '', int $limit = 20, int $offset = 0, int $lineLimit = 12): array
    {
        global $wpdb;

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $lineLimit = max(0, $lineLimit);
        $setId = trim($setId);

        if ($setId !== '') {
            $transactions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->transactionsTable} WHERE set_id = %s ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
                    $setId,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            $transactions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->transactionsTable} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }

        $out = [];
        foreach ((array)$transactions as $tx) {
            $id = (int)($tx['id'] ?? 0);
            if ($id <= 0) continue;

            if ((string)($tx['type'] ?? 'normal') === 'normal' && empty($tx['rolled_back_at'])) {
                $this->compactTransactionLines($id);
            }

            $summary = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) AS line_count, COALESCE(SUM(delta_qty), 0) AS delta_total FROM {$this->linesTable} WHERE transaction_id = %d",
                    $id
                ),
                ARRAY_A
            );

            if ((int)($summary['line_count'] ?? 0) <= 0) {
                continue;
            }

            if ($lineLimit > 0) {
                $lines = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, product_id, card_id, card_number, card_name, finish, lang, action, qty_before, qty_after, delta_qty
                         FROM {$this->linesTable}
                         WHERE transaction_id = %d
                         ORDER BY id ASC
                         LIMIT %d",
                        $id,
                        $lineLimit
                    ),
                    ARRAY_A
                );
            } else {
                $lines = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, product_id, card_id, card_number, card_name, finish, lang, action, qty_before, qty_after, delta_qty
                         FROM {$this->linesTable}
                         WHERE transaction_id = %d
                         ORDER BY id ASC",
                        $id
                    ),
                    ARRAY_A
                );
            }

            $out[] = [
                'id' => $id,
                'type' => (string)($tx['type'] ?? 'normal'),
                'rollback_of' => isset($tx['rollback_of']) ? (int)$tx['rollback_of'] : 0,
                'set_id' => (string)($tx['set_id'] ?? ''),
                'set_name' => (string)($tx['set_name'] ?? ''),
                'lang' => (string)($tx['lang'] ?? ''),
                'owner_user_id' => (int)($tx['owner_user_id'] ?? 0),
                'owner_user_login' => (string)($tx['owner_user_login'] ?? ''),
                'owner_display_name' => (string)($tx['owner_display_name'] ?? ''),
                'created_at' => (string)($tx['created_at'] ?? ''),
                'updated_at' => (string)($tx['updated_at'] ?? ''),
                'rolled_back_at' => (string)($tx['rolled_back_at'] ?? ''),
                'rolled_back_by_user_id' => isset($tx['rolled_back_by_user_id']) ? (int)$tx['rolled_back_by_user_id'] : 0,
                'line_count' => (int)($summary['line_count'] ?? 0),
                'delta_total' => (int)($summary['delta_total'] ?? 0),
                'lines' => array_map([$this, 'formatLineForOutput'], (array)$lines),
            ];
        }

        return $out;
    }

    public function countTransactions(string $setId = ''): int
    {
        global $wpdb;

        $setId = trim($setId);
        if ($setId !== '') {
            return (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->transactionsTable} WHERE set_id = %s",
                    $setId
                )
            );
        }

        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->transactionsTable}");
    }

    public function listSetOptions(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT set_id, MAX(set_name) AS set_name, COUNT(*) AS transaction_count, MAX(updated_at) AS latest_at
             FROM {$this->transactionsTable}
             WHERE set_id <> ''
             GROUP BY set_id
             ORDER BY latest_at DESC, set_name ASC
             LIMIT 300",
            ARRAY_A
        );

        return array_map(static function (array $row): array {
            return [
                'set_id' => (string)($row['set_id'] ?? ''),
                'set_name' => (string)($row['set_name'] ?? ''),
                'transaction_count' => (int)($row['transaction_count'] ?? 0),
                'latest_at' => (string)($row['latest_at'] ?? ''),
            ];
        }, (array)$rows);
    }

    public function rollbackTransaction(int $transactionId): array
    {
        global $wpdb;

        $transactionId = (int)$transactionId;
        if ($transactionId <= 0) {
            return ['ok' => false, 'message' => 'Ugyldig transaktion.'];
        }

        $tx = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->transactionsTable} WHERE id = %d", $transactionId),
            ARRAY_A
        );

        if (!$tx) {
            return ['ok' => false, 'message' => 'Transaktionen findes ikke.'];
        }

        if ((string)($tx['type'] ?? '') !== 'normal') {
            return ['ok' => false, 'message' => 'Kun normale lagertransaktioner kan rulles tilbage.'];
        }

        if (!empty($tx['rolled_back_at'])) {
            return ['ok' => false, 'message' => 'Transaktionen er allerede rullet tilbage.'];
        }

        $this->compactTransactionLines($transactionId);

        $lines = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$this->linesTable} WHERE transaction_id = %d ORDER BY id ASC", $transactionId),
            ARRAY_A
        );

        if (!$lines) {
            return ['ok' => false, 'message' => 'Transaktionen har ingen linjer.'];
        }

        $effective = [];
        $conflicts = [];

        foreach ((array)$lines as $line) {
            $action = (string)($line['action'] ?? '');
            $productId = (int)($line['product_id'] ?? 0);
            $key = $productId > 0
                ? 'p:' . $productId
                : 'c:' . (string)($line['card_id'] ?? '') . '|' . (string)($line['finish'] ?? '') . '|' . (string)($line['lang'] ?? '');

            if ($action === 'deleted_product') {
                $conflicts[] = [
                    'line_id' => (int)($line['id'] ?? 0),
                    'product_id' => $productId,
                    'card' => $this->lineTitle($line),
                    'message' => 'Produktet blev fysisk slettet. V1 rollback kan ikke genskabe slettede produkter automatisk.',
                ];
                continue;
            }

            if ($action !== 'stock_changed' && $action !== 'created_product') {
                continue;
            }

            if ($productId <= 0 || get_post_type($productId) !== 'product') {
                $conflicts[] = [
                    'line_id' => (int)($line['id'] ?? 0),
                    'product_id' => $productId,
                    'card' => $this->lineTitle($line),
                    'message' => 'Produktet findes ikke længere.',
                ];
                continue;
            }

            if (!isset($effective[$key])) {
                $effective[$key] = [
                    'product_id' => $productId,
                    'card_id' => (string)($line['card_id'] ?? ''),
                    'card_number' => (string)($line['card_number'] ?? ''),
                    'card_name' => $this->decodeText((string)($line['card_name'] ?? '')),
                    'finish' => (string)($line['finish'] ?? ''),
                    'lang' => (string)($line['lang'] ?? ''),
                    'action' => $action,
                    'qty_before' => (int)($line['qty_before'] ?? 0),
                    'qty_after' => (int)($line['qty_after'] ?? 0),
                    'line_ids' => [(int)($line['id'] ?? 0)],
                ];
            } else {
                if ($action === 'created_product') {
                    $effective[$key]['action'] = 'created_product';
                }
                $effective[$key]['qty_after'] = (int)($line['qty_after'] ?? 0);
                $effective[$key]['line_ids'][] = (int)($line['id'] ?? 0);
            }
        }

        foreach ($effective as $item) {
            $current = (int)get_post_meta((int)$item['product_id'], $this->stockMeta, true);
            $expected = (int)$item['qty_after'];

            if ($current !== $expected) {
                $conflicts[] = [
                    'product_id' => (int)$item['product_id'],
                    'card' => $this->lineTitle($item),
                    'expected_stock' => $expected,
                    'actual_stock' => $current,
                    'message' => 'Nuværende lager matcher ikke transaktionens forventede slutværdi.',
                ];
            }
        }

        if ($conflicts) {
            return [
                'ok' => false,
                'message' => 'Rollback stoppet pga. konflikt. Ingen ændringer er udført.',
                'conflicts' => $conflicts,
            ];
        }

        if (!$effective) {
            return ['ok' => false, 'message' => 'Der er ingen rollback-egnede lagerlinjer i transaktionen.'];
        }

        $rollbackTxId = $this->createTransaction(
            (string)($tx['set_id'] ?? ''),
            (string)($tx['set_name'] ?? ''),
            (string)($tx['lang'] ?? ''),
            'rollback',
            $transactionId
        );

        if ($rollbackTxId <= 0) {
            return ['ok' => false, 'message' => 'Kunne ikke oprette rollback-transaktion.'];
        }

        $changed = [];
        foreach ($effective as $item) {
            $productId = (int)$item['product_id'];
            $before = (int)get_post_meta($productId, $this->stockMeta, true);
            $target = max(0, (int)$item['qty_before']);

            $createdProduct = (string)$item['action'] === 'created_product';

            if (!$createdProduct) {
                update_post_meta($productId, '_manage_stock', 'yes');
                update_post_meta($productId, $this->stockMeta, $target);
                update_post_meta($productId, '_stock_status', $target > 0 ? 'instock' : 'outofstock');
            }

            $this->insertLine($rollbackTxId, [
                'product_id' => $productId,
                'card_id' => (string)$item['card_id'],
                'card_number' => (string)$item['card_number'],
                'card_name' => (string)$item['card_name'],
                'finish' => (string)$item['finish'],
                'lang' => (string)$item['lang'],
                'action' => 'rollback_' . (string)$item['action'],
                'qty_before' => $before,
                'qty_after' => $target,
                'meta' => [
                    'rollback_of' => $transactionId,
                    'original_line_ids' => (array)$item['line_ids'],
                    'product_deleted' => $createdProduct,
                ],
            ]);

            if ($createdProduct) {
                // The product did not exist before the original transaction. Restoring
                // the pre-transaction state therefore means removing it completely.
                // We deliberately call WordPress directly here, so this rollback-internal
                // deletion does not create a new normal inventory-history transaction.
                $deleted = wp_delete_post($productId, true);
                if (!$deleted) {
                    return [
                        'ok' => false,
                        'message' => 'Rollback kunne ikke fjerne en vare, som blev oprettet af transaktionen.',
                        'product_id' => $productId,
                    ];
                }
            }

            $changed[] = [
                'product_id' => $productId,
                'card' => $this->lineTitle($item),
                'qty_before' => $before,
                'qty_after' => $target,
                'deleted' => $createdProduct,
            ];
        }

        $now = current_time('mysql');
        $user = $this->currentUser();
        $wpdb->update(
            $this->transactionsTable,
            [
                'rolled_back_at' => $now,
                'rolled_back_by_user_id' => (int)$user['id'],
                'updated_at' => $now,
            ],
            ['id' => $transactionId],
            ['%s', '%d', '%s'],
            ['%d']
        );

        $this->touchTransaction($rollbackTxId);

        return [
            'ok' => true,
            'message' => 'Transaktionen er rullet tilbage.',
            'rollback_transaction_id' => $rollbackTxId,
            'changed' => $changed,
        ];
    }

    private function appendNormalLine(string $setId, string $setName, string $lang, array $line): void
    {
        $setId = trim($setId);
        if ($setId === '') {
            $setId = $this->extractSetIdFromCardId((string)($line['card_id'] ?? ''));
        }

        $txId = $this->getOrCreateAppendableTransaction($setId, $setName, strtoupper($lang));
        if ($txId <= 0) return;

        $this->insertOrMergeNormalLine($txId, $line);
        $this->deleteTransactionIfEmpty($txId);
        $this->touchTransaction($txId);
    }

    private function insertOrMergeNormalLine(int $transactionId, array $line): void
    {
        global $wpdb;

        $action = (string)($line['action'] ?? 'stock_changed');
        if (!$this->isMergeableAction($action)) {
            $this->insertLine($transactionId, $line);
            return;
        }

        $productId = max(0, (int)($line['product_id'] ?? 0));
        $cardId = (string)($line['card_id'] ?? '');
        $finish = (string)($line['finish'] ?? '');
        $lang = strtoupper((string)($line['lang'] ?? ''));

        $existing = $this->findMergeableLines($transactionId, $productId, $cardId, $finish, $lang);

        if (!$existing) {
            $qtyBefore = max(0, (int)($line['qty_before'] ?? 0));
            $qtyAfter = max(0, (int)($line['qty_after'] ?? 0));

            // A pure stock +/- sequence that ends where it began should not leave history noise.
            if ($action === 'stock_changed' && $qtyBefore === $qtyAfter) {
                return;
            }

            $this->insertLine($transactionId, $line);
            return;
        }

        $first = (array)$existing[0];
        $firstId = (int)($first['id'] ?? 0);
        if ($firstId <= 0) {
            $this->insertLine($transactionId, $line);
            return;
        }

        $qtyBefore = max(0, (int)($first['qty_before'] ?? 0));
        $qtyAfter = max(0, (int)($line['qty_after'] ?? 0));

        $actions = array_map(static fn($row) => (string)($row['action'] ?? ''), (array)$existing);
        $actions[] = $action;
        $finalAction = $this->chooseMergedAction($actions);

        $lineIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), (array)$existing)));
        $lineIds[] = 0; // marker for the incoming not-yet-inserted line

        $hasSideEffect = $this->actionsHaveSideEffect($actions);
        if ($qtyBefore === $qtyAfter && !$hasSideEffect) {
            $this->deleteLinesByIds(array_values(array_filter($lineIds)));
            return;
        }

        $deleteIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), array_slice((array)$existing, 1))));
        if ($deleteIds) {
            $this->deleteLinesByIds($deleteIds);
        }

        $meta = $line['meta'] ?? [];
        if (!is_array($meta)) $meta = [];
        $meta['compacted'] = true;
        $meta['compacted_line_ids'] = array_values(array_filter($lineIds));

        $wpdb->update(
            $this->linesTable,
            [
                'product_id' => $productId > 0 ? $productId : (int)($first['product_id'] ?? 0),
                'card_id' => $cardId !== '' ? $cardId : (string)($first['card_id'] ?? ''),
                'card_number' => (string)($line['card_number'] ?? ($first['card_number'] ?? '')),
                'card_name' => (string)($line['card_name'] ?? ($first['card_name'] ?? '')),
                'finish' => $finish !== '' ? $finish : (string)($first['finish'] ?? ''),
                'lang' => $lang !== '' ? $lang : strtoupper((string)($first['lang'] ?? '')),
                'action' => $finalAction,
                'qty_before' => $qtyBefore,
                'qty_after' => $qtyAfter,
                'delta_qty' => $qtyAfter - $qtyBefore,
                'meta_json' => $meta ? (string)wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ],
            ['id' => $firstId],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s'],
            ['%d']
        );
    }

    private function compactTransactionLines(int $transactionId): void
    {
        global $wpdb;

        if ($transactionId <= 0) return;

        $lines = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->linesTable} WHERE transaction_id = %d ORDER BY id ASC",
                $transactionId
            ),
            ARRAY_A
        );

        $groups = [];
        foreach ((array)$lines as $line) {
            $action = (string)($line['action'] ?? '');
            if (!$this->isMergeableAction($action)) continue;

            $key = $this->mergeKey($line);
            if ($key === '') continue;
            $groups[$key][] = $line;
        }

        foreach ($groups as $groupLines) {
            if (count($groupLines) <= 1) continue;

            $first = (array)$groupLines[0];
            $last = (array)$groupLines[count($groupLines) - 1];
            $firstId = (int)($first['id'] ?? 0);
            if ($firstId <= 0) continue;

            $actions = array_map(static fn($row) => (string)($row['action'] ?? ''), $groupLines);
            $qtyBefore = max(0, (int)($first['qty_before'] ?? 0));
            $qtyAfter = max(0, (int)($last['qty_after'] ?? 0));
            $hasSideEffect = $this->actionsHaveSideEffect($actions);
            $lineIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $groupLines)));

            if ($qtyBefore === $qtyAfter && !$hasSideEffect) {
                $this->deleteLinesByIds($lineIds);
                continue;
            }

            $deleteIds = array_slice($lineIds, 1);
            if ($deleteIds) {
                $this->deleteLinesByIds($deleteIds);
            }

            $meta = [
                'compacted' => true,
                'compacted_line_ids' => $lineIds,
            ];

            $wpdb->update(
                $this->linesTable,
                [
                    'product_id' => max((int)($last['product_id'] ?? 0), (int)($first['product_id'] ?? 0)),
                    'card_id' => (string)($last['card_id'] ?: ($first['card_id'] ?? '')),
                    'card_number' => (string)($last['card_number'] ?: ($first['card_number'] ?? '')),
                    'card_name' => (string)($last['card_name'] ?: ($first['card_name'] ?? '')),
                    'finish' => (string)($last['finish'] ?: ($first['finish'] ?? '')),
                    'lang' => strtoupper((string)($last['lang'] ?: ($first['lang'] ?? ''))),
                    'action' => $this->chooseMergedAction($actions),
                    'qty_before' => $qtyBefore,
                    'qty_after' => $qtyAfter,
                    'delta_qty' => $qtyAfter - $qtyBefore,
                    'meta_json' => (string)wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
                ['id' => $firstId],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s'],
                ['%d']
            );
        }

        $this->deleteTransactionIfEmpty($transactionId);
    }

    private function findMergeableLines(int $transactionId, int $productId, string $cardId, string $finish, string $lang): array
    {
        global $wpdb;

        $actionsSql = "'stock_changed','created_product','deleted_product'";

        if ($productId > 0) {
            return (array)$wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->linesTable}
                     WHERE transaction_id = %d
                       AND product_id = %d
                       AND action IN ({$actionsSql})
                     ORDER BY id ASC",
                    $transactionId,
                    $productId
                ),
                ARRAY_A
            );
        }

        if ($cardId === '' || $finish === '') return [];

        return (array)$wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->linesTable}
                 WHERE transaction_id = %d
                   AND card_id = %s
                   AND finish = %s
                   AND lang = %s
                   AND action IN ({$actionsSql})
                 ORDER BY id ASC",
                $transactionId,
                $cardId,
                $finish,
                strtoupper($lang)
            ),
            ARRAY_A
        );
    }

    private function mergeKey(array $line): string
    {
        $productId = (int)($line['product_id'] ?? 0);
        if ($productId > 0) return 'p:' . $productId;

        $cardId = (string)($line['card_id'] ?? '');
        $finish = (string)($line['finish'] ?? '');
        $lang = strtoupper((string)($line['lang'] ?? ''));

        if ($cardId === '' || $finish === '') return '';
        return 'c:' . $cardId . '|' . $finish . '|' . $lang;
    }

    private function isMergeableAction(string $action): bool
    {
        return in_array($action, ['stock_changed', 'created_product', 'deleted_product'], true);
    }

    private function chooseMergedAction(array $actions): string
    {
        if (in_array('deleted_product', $actions, true)) return 'deleted_product';
        if (in_array('created_product', $actions, true)) return 'created_product';
        return 'stock_changed';
    }

    private function actionsHaveSideEffect(array $actions): bool
    {
        return in_array('created_product', $actions, true) || in_array('deleted_product', $actions, true);
    }

    private function deleteLinesByIds(array $ids): void
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->linesTable} WHERE id IN ({$placeholders})",
                ...$ids
            )
        );
    }

    private function deleteTransactionIfEmpty(int $transactionId): void
    {
        global $wpdb;

        if ($transactionId <= 0) return;

        $lineCount = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->linesTable} WHERE transaction_id = %d",
                $transactionId
            )
        );

        if ($lineCount > 0) return;

        $wpdb->delete($this->transactionsTable, ['id' => $transactionId], ['%d']);
    }

    private function getOrCreateAppendableTransaction(string $setId, string $setName, string $lang): int
    {
        global $wpdb;

        $user = $this->currentUser();

        $last = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, set_id
                 FROM {$this->transactionsTable}
                 WHERE owner_user_id = %d
                   AND type = 'normal'
                   AND rollback_of IS NULL
                   AND rolled_back_at IS NULL
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                (int)$user['id']
            ),
            ARRAY_A
        );

        if ($last && (string)($last['set_id'] ?? '') === $setId) {
            return (int)$last['id'];
        }

        return $this->createTransaction($setId, $setName, $lang, 'normal', 0);
    }

    private function createTransaction(string $setId, string $setName, string $lang, string $type = 'normal', int $rollbackOf = 0): int
    {
        global $wpdb;

        $user = $this->currentUser();
        $now = current_time('mysql');

        $ok = $wpdb->insert(
            $this->transactionsTable,
            [
                'type' => $type,
                'rollback_of' => $rollbackOf > 0 ? $rollbackOf : null,
                'set_id' => $setId,
                'set_name' => $setName,
                'lang' => strtoupper($lang),
                'owner_user_id' => (int)$user['id'],
                'owner_user_login' => (string)$user['login'],
                'owner_display_name' => (string)$user['display_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    private function insertLine(int $transactionId, array $line): void
    {
        global $wpdb;

        $qtyBefore = max(0, (int)($line['qty_before'] ?? 0));
        $qtyAfter = max(0, (int)($line['qty_after'] ?? 0));
        $meta = $line['meta'] ?? [];

        $wpdb->insert(
            $this->linesTable,
            [
                'transaction_id' => $transactionId,
                'product_id' => max(0, (int)($line['product_id'] ?? 0)),
                'card_id' => (string)($line['card_id'] ?? ''),
                'card_number' => (string)($line['card_number'] ?? ''),
                'card_name' => $this->decodeText((string)($line['card_name'] ?? '')),
                'finish' => (string)($line['finish'] ?? ''),
                'lang' => strtoupper((string)($line['lang'] ?? '')),
                'action' => (string)($line['action'] ?? 'stock_changed'),
                'qty_before' => $qtyBefore,
                'qty_after' => $qtyAfter,
                'delta_qty' => $qtyAfter - $qtyBefore,
                'meta_json' => $meta ? (string)wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s']
        );
    }

    private function touchTransaction(int $transactionId): void
    {
        global $wpdb;
        if ($transactionId <= 0) return;

        $wpdb->update(
            $this->transactionsTable,
            ['updated_at' => current_time('mysql')],
            ['id' => $transactionId],
            ['%s'],
            ['%d']
        );
    }

    private function snapshotProduct(int $productId): array
    {
        if ($productId <= 0 || get_post_type($productId) !== 'product') return [];

        $cardId = (string)get_post_meta($productId, $this->meta('card_id'), true);
        $finish = (string)get_post_meta($productId, $this->meta('finish'), true);
        $lang = strtoupper((string)get_post_meta($productId, $this->meta('language'), true));
        $setId = (string)get_post_meta($productId, $this->meta('set_id'), true);
        $setName = (string)get_post_meta($productId, $this->meta('set_name'), true);
        $stock = get_post_meta($productId, $this->stockMeta, true);

        if ($setId === '') {
            $setId = $this->extractSetIdFromCardId($cardId);
        }

        $title = $this->decodeText((string)get_the_title($productId));
        $cardNumberMeta = $this->meta('card_number', '');
        $cardNameMeta = $this->meta('card_name', '');
        $cardNumber = $cardNumberMeta !== '' ? (string)get_post_meta($productId, $cardNumberMeta, true) : '';
        $cardName = $cardNameMeta !== '' ? (string)get_post_meta($productId, $cardNameMeta, true) : '';

        return [
            'product_id' => $productId,
            'post_title' => $title,
            'post_status' => (string)get_post_status($productId),
            'card_id' => $cardId,
            'card_number' => $cardNumber !== '' ? $cardNumber : $this->extractCardNumberFromCardId($cardId),
            'card_name' => $cardName !== '' ? $cardName : $this->extractCardNameFromTitle($title),
            'finish' => $finish,
            'lang' => $lang ?: 'EN',
            'set_id' => $setId,
            'set_name' => $setName,
            'stock' => is_numeric($stock) ? (int)$stock : 0,
        ];
    }

    private function currentUser(): array
    {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;

        if ($user && !empty($user->ID)) {
            return [
                'id' => (int)$user->ID,
                'login' => (string)$user->user_login,
                'display_name' => (string)($user->display_name ?: $user->user_login),
            ];
        }

        return [
            'id' => 0,
            'login' => 'system',
            'display_name' => 'System',
        ];
    }

    private function cleanSetInfo(array $setInfo): array
    {
        return [
            'id' => (string)($setInfo['id'] ?? ''),
            'name' => (string)($setInfo['name'] ?? ''),
            'ptcgoCode' => (string)($setInfo['ptcgoCode'] ?? ''),
            'series' => (string)($setInfo['series'] ?? ''),
        ];
    }

    private function extractSetIdFromCardId(string $cardId): string
    {
        $pos = strrpos($cardId, '-');
        if ($pos === false || $pos <= 0) return '';
        return substr($cardId, 0, $pos);
    }

    private function extractCardNumberFromCardId(string $cardId): string
    {
        $pos = strrpos($cardId, '-');
        if ($pos === false) return '';
        return substr($cardId, $pos + 1);
    }

    private function extractCardNameFromTitle(string $title): string
    {
        $parts = array_map('trim', explode(' - ', $title));
        return (string)($parts[0] ?? $title);
    }

    private function lineTitle(array $line): string
    {
        $num = trim((string)($line['card_number'] ?? ''));
        $name = trim($this->decodeText((string)($line['card_name'] ?? '')));
        $finish = trim((string)($line['finish'] ?? ''));

        $left = trim(($num !== '' ? $num . ' ' : '') . ($name !== '' ? $name : (string)($line['card_id'] ?? '')));
        return trim($left . ($finish !== '' ? ' / ' . $finish : ''));
    }

    private function decodeText(string $text): string
    {
        if ($text === '') return '';

        if (function_exists('wp_specialchars_decode')) {
            $text = wp_specialchars_decode($text, ENT_QUOTES);
        }

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function formatLineForOutput(array $line): array
    {
        return [
            'id' => (int)($line['id'] ?? 0),
            'product_id' => (int)($line['product_id'] ?? 0),
            'card_id' => (string)($line['card_id'] ?? ''),
            'card_number' => (string)($line['card_number'] ?? ''),
            'card_name' => $this->decodeText((string)($line['card_name'] ?? '')),
            'finish' => (string)($line['finish'] ?? ''),
            'lang' => (string)($line['lang'] ?? ''),
            'action' => (string)($line['action'] ?? ''),
            'qty_before' => (int)($line['qty_before'] ?? 0),
            'qty_after' => (int)($line['qty_after'] ?? 0),
            'delta_qty' => (int)($line['delta_qty'] ?? 0),
        ];
    }
}

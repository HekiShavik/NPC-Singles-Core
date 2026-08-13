<?php

namespace NPS\Core\Admin;

if (!defined('ABSPATH')) exit;

final class InventoryHistoryPage
{
    private object $history;
    private array $config;

    public function __construct(object $history, array $config)
    {
        $this->history = $history;
        $this->config = $config;
        add_action('admin_post_' . $this->actionName(), [$this, 'handleRollbackPost']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        $set_id = isset($_GET['setId']) ? trim(sanitize_text_field(wp_unslash((string)$_GET['setId']))) : '';
        $paged = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $per_page = isset($_GET['perPage']) ? (int)$_GET['perPage'] : 20;
        if (!in_array($per_page, [10, 20, 50, 100], true)) $per_page = 20;

        $offset = ($paged - 1) * $per_page;
        $transactions = $this->history->listTransactions($set_id, $per_page, $offset, 0);
        $total = $this->history->countTransactions($set_id);
        $total_pages = max(1, (int)ceil($total / $per_page));
        $sets = $this->history->listSetOptions();
        $noticeKey = $this->noticeKey();
        $noticeTypeKey = $this->noticeTypeKey();
        $notice = isset($_GET[$noticeKey]) ? sanitize_text_field(wp_unslash((string)$_GET[$noticeKey])) : '';
        $notice_type = isset($_GET[$noticeTypeKey]) ? sanitize_text_field(wp_unslash((string)$_GET[$noticeTypeKey])) : 'success';

        $config = $this->config;
        include NPS_CORE_DIR . 'includes/Admin/Views/inventory-history-page.php';
    }

    public function handleRollbackPost(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('No permission', 'No permission', ['response' => 403]);
        }

        $transaction_id = isset($_POST['transactionId']) ? (int)$_POST['transactionId'] : 0;
        if ($transaction_id <= 0) wp_die('Missing transactionId', 'Bad request', ['response' => 400]);

        check_admin_referer($this->noncePrefix() . $transaction_id);

        $set_id = isset($_POST['setId']) ? trim(sanitize_text_field(wp_unslash((string)$_POST['setId']))) : '';
        $paged = isset($_POST['paged']) ? max(1, (int)$_POST['paged']) : 1;
        $per_page = isset($_POST['perPage']) ? (int)$_POST['perPage'] : 20;
        if (!in_array($per_page, [10, 20, 50, 100], true)) $per_page = 20;

        try {
            $result = $this->history->rollbackTransaction($transaction_id);
            $ok = (bool)($result['ok'] ?? false);
            $message = (string)($result['message'] ?? ($ok ? 'Rollback udført.' : 'Rollback fejlede.'));
        } catch (\Throwable $e) {
            $ok = false;
            $message = 'Rollback fejlede: ' . $e->getMessage();
        }

        $historyBaseUrl = $this->config['history_base_url'] ?? null;
        $baseUrl = is_callable($historyBaseUrl)
            ? (string)$historyBaseUrl()
            : admin_url('edit.php?post_type=product&page=' . rawurlencode((string)$this->config['history_slug']));

        $url = add_query_arg([
            'setId' => $set_id,
            'paged' => $paged,
            'perPage' => $per_page,
            $this->noticeKey() => $message,
            $this->noticeTypeKey() => $ok ? 'success' : 'error',
        ], $baseUrl);

        wp_safe_redirect($url);
        exit;
    }

    private function actionName(): string { return (string)$this->config['rollback_action']; }
    private function noncePrefix(): string { return (string)$this->config['rollback_nonce_prefix']; }
    private function noticeKey(): string { return (string)$this->config['notice_key']; }
    private function noticeTypeKey(): string { return (string)$this->config['notice_type_key']; }
}

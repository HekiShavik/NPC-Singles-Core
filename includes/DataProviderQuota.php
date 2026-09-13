<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class DataProviderQuota
{
    private DataProviderStore $store;

    public function __construct(?DataProviderStore $store = null)
    {
        $this->store = $store ?? new DataProviderStore();
    }

    /**
     * @return array{allowed:bool,reason:string,daily:array<string,mixed>,monthly:array<string,mixed>}
     */
    public function canSpend(string $providerId, int $cost = 1): array
    {
        $providerId = sanitize_key($providerId);
        $cost = max(1, $cost);
        $settings = DataProviderSettings::get($providerId);
        $summary = $this->summary($providerId);

        if (empty($settings['active'])) {
            return $summary + ['allowed' => false, 'reason' => 'inactive'];
        }

        foreach (['daily', 'monthly'] as $period) {
            $limit = (int)$summary[$period]['limit'];
            if ($limit > 0 && ((int)$summary[$period]['used'] + $cost) > $limit) {
                return $summary + ['allowed' => false, 'reason' => $period . '_quota'];
            }
        }

        return $summary + ['allowed' => true, 'reason' => ''];
    }

    /**
     * @return array{daily:array<string,mixed>,monthly:array<string,mixed>}
     */
    public function summary(string $providerId): array
    {
        $providerId = sanitize_key($providerId);
        $settings = DataProviderSettings::get($providerId);
        $limits = is_array($settings['limits'] ?? null) ? $settings['limits'] : [];
        $timezone = wp_timezone();
        $now = new \DateTimeImmutable('now', $timezone);

        $dailyStart = $now->setTime(0, 0, 0);
        $dailyReset = $dailyStart->modify('+1 day');

        $resetDay = min(31, max(1, (int)($limits['monthly_reset_day'] ?? 1)));
        $monthlyStart = $this->monthlyBoundary($now, $resetDay);
        if ($monthlyStart > $now) {
            $monthlyStart = $this->monthlyBoundary($now->modify('first day of previous month'), $resetDay);
        }
        $monthlyReset = $this->monthlyBoundary($monthlyStart->modify('first day of next month'), $resetDay);

        return [
            'daily' => [
                'used' => $this->store->usageSince($providerId, $dailyStart),
                'limit' => max(0, (int)($limits['daily'] ?? 0)),
                'window_start' => $dailyStart->format(DATE_ATOM),
                'resets_at' => $dailyReset->format(DATE_ATOM),
            ],
            'monthly' => [
                'used' => $this->store->usageSince($providerId, $monthlyStart),
                'limit' => max(0, (int)($limits['monthly'] ?? 0)),
                'window_start' => $monthlyStart->format(DATE_ATOM),
                'resets_at' => $monthlyReset->format(DATE_ATOM),
            ],
        ];
    }

    private function monthlyBoundary(\DateTimeImmutable $month, int $resetDay): \DateTimeImmutable
    {
        $first = $month->modify('first day of this month')->setTime(0, 0, 0);
        $daysInMonth = (int)$first->format('t');
        $day = min($daysInMonth, $resetDay);
        return $first->setDate((int)$first->format('Y'), (int)$first->format('m'), $day);
    }
}

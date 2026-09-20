<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\User;
use InvalidArgumentException;

final class CashCloseService
{
    /**
     * @return array<string, int>
     */
    public function emptyPayments(): array
    {
        return [
            'especes' => 0,
            'wave' => 0,
            'orange_money' => 0,
            'carte' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReport(CashSession $session): array
    {
        $session->loadMissing(['orders.payments', 'movements']);

        $active = $session->orders->where('channel', 'boutique')->where('status', '!=', 'annulée');
        $cancelled = $session->orders->where('channel', 'boutique')->where('status', 'annulée');

        $payments = $this->emptyPayments();
        foreach ($active as $order) {
            foreach ($order->payments as $payment) {
                $method = (string) $payment->method;
                if (! array_key_exists($method, $payments)) {
                    $payments[$method] = 0;
                }
                $payments[$method] += (int) round((float) $payment->amount);
            }
        }

        $cashIn = (int) round((float) $session->movements->where('type', 'entree')->sum('amount'));
        $cashOut = (int) round((float) $session->movements->where('type', 'sortie')->sum('amount'));
        $opening = (int) round((float) $session->opening_amount);
        $expected = $opening + $payments['especes'] + $cashIn - $cashOut;
        $counted = $session->closing_amount_counted === null
            ? null
            : (int) round((float) $session->closing_amount_counted);

        return [
            'sales_count' => $active->count(),
            'cancelled_count' => $cancelled->count(),
            'sales_total' => (int) round((float) $active->sum('total_amount')),
            'discount_total' => (int) round((float) $active->sum('discount_amount')),
            'payments' => $payments,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'opening_amount' => $opening,
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'discrepancy' => $counted === null ? null : $counted - $expected,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(CashSession $session): array
    {
        $session->loadMissing('cashier');
        $report = is_array($session->report) && $session->report !== []
            ? $session->report
            : $this->buildReport($session);

        return [
            'id' => $session->id,
            'cashier_id' => $session->cashier_id,
            'cashier_name' => $session->cashier?->name,
            'opening_amount' => (int) round((float) $session->opening_amount),
            'closing_amount_expected' => $session->closing_amount_expected === null
                ? ($report['expected_cash'] ?? null)
                : (int) round((float) $session->closing_amount_expected),
            'closing_amount_counted' => $session->closing_amount_counted === null
                ? null
                : (int) round((float) $session->closing_amount_counted),
            'discrepancy' => $session->discrepancy === null
                ? ($report['discrepancy'] ?? null)
                : (int) round((float) $session->discrepancy),
            'opened_at' => optional($session->opened_at)->toIso8601String(),
            'closed_at' => optional($session->closed_at)->toIso8601String(),
            'notes' => $session->notes,
            'is_open' => $session->isOpen(),
            'report' => $report,
        ];
    }

    public function close(CashSession $session, int $counted, ?string $notes, User $actor): CashSession
    {
        if (! $session->isOpen()) {
            throw new InvalidArgumentException('Cette session est déjà fermée');
        }

        $report = $this->buildReport($session);
        $expected = (int) $report['expected_cash'];
        $discrepancy = $counted - $expected;
        $report['counted_cash'] = $counted;
        $report['discrepancy'] = $discrepancy;

        $session->update([
            'closing_amount_expected' => $expected,
            'closing_amount_counted' => $counted,
            'discrepancy' => $discrepancy,
            'closed_at' => now(),
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : $session->notes,
            'report' => $report,
        ]);

        $fresh = $session->fresh(['cashier']);

        ActivityLogger::record(
            $actor,
            'cash.closed',
            'Clôture de caisse : ventes '.$report['sales_total'].' FCFA, écart '.$discrepancy.' FCFA',
            $fresh,
            [
                'sales_total' => $report['sales_total'],
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'discrepancy' => $discrepancy,
            ],
            'pos',
        );

        return $fresh;
    }
}

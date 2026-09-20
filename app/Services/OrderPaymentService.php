<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\ShopSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class OrderPaymentService
{
    public const STATUS_UNPAID = 'non_paye';

    public const STATUS_PARTIAL = 'partiel';

    public const STATUS_PAID = 'paye';

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_UNPAID => 'Non payé',
            self::STATUS_PARTIAL => 'Acompte',
            self::STATUS_PAID => 'Payé',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Order $order): array
    {
        $order->loadMissing('payments');

        $due = (int) round((float) $order->total_amount);
        $paid = (int) round((float) $order->payments->sum('amount'));
        $balance = max(0, $due - $paid);
        $status = $paid <= 0
            ? self::STATUS_UNPAID
            : ($paid >= $due ? self::STATUS_PAID : self::STATUS_PARTIAL);

        $settings = ShopSetting::current();
        $percent = max(0, min(100, (int) $settings->min_deposit_percent));
        $required = (int) ceil($due * $percent / 100);
        $closed = $order->isClosed();

        return [
            'payment_status' => $status,
            'payment_status_label' => self::statusLabels()[$status],
            'paid_amount' => $paid,
            'balance' => $balance,
            'due_amount' => $due,
            'min_deposit_percent' => $percent,
            'min_deposit_amount' => $required,
            'can_validate' => ! $closed && $paid >= $required,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Order $order): array
    {
        $order->loadMissing(['payments.recordedBy']);
        $settings = ShopSetting::current();
        $enabled = array_values(array_intersect(
            ShopSetting::paymentMethodKeys(),
            is_array($settings->payment_methods) && $settings->payment_methods !== []
                ? $settings->payment_methods
                : ShopSetting::paymentMethodKeys()
        ));

        return [
            ...$this->snapshot($order),
            'methods' => collect($enabled)->map(fn (string $key) => [
                'key' => $key,
                'label' => ShopSetting::paymentMethodLabels()[$key] ?? $key,
            ])->values()->all(),
            'payments' => $order->payments
                ->sortBy('id')
                ->map(fn (OrderPayment $payment) => [
                    'id' => $payment->id,
                    'method' => $payment->method,
                    'method_label' => ShopSetting::paymentMethodLabels()[$payment->method] ?? $payment->method,
                    'amount' => (int) round((float) $payment->amount),
                    'reference' => $payment->reference,
                    'note' => $payment->note,
                    'recorded_by' => $payment->recordedBy?->name,
                    'created_at' => optional($payment->created_at)->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForClient(Order $order): array
    {
        $full = $this->present($order);

        return [
            'payment_status' => $full['payment_status'],
            'payment_status_label' => $full['payment_status_label'],
            'paid_amount' => $full['paid_amount'],
            'balance' => $full['balance'],
            'due_amount' => $full['due_amount'],
            'payments' => collect($full['payments'])->map(fn (array $payment) => [
                'method' => $payment['method'],
                'method_label' => $payment['method_label'],
                'amount' => $payment['amount'],
                'created_at' => $payment['created_at'],
            ])->values()->all(),
        ];
    }

    public function record(
        Order $order,
        User $actor,
        string $method,
        int $amount,
        ?string $reference = null,
        ?string $note = null,
    ): OrderPayment {
        return DB::transaction(function () use ($order, $actor, $method, $amount, $reference, $note) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $locked->load('payments');

            if ($locked->isClosed()) {
                throw new InvalidArgumentException(
                    $locked->status === 'expirée'
                        ? 'Impossible d’enregistrer un paiement sur une commande expirée'
                        : 'Impossible d’enregistrer un paiement sur une commande annulée'
                );
            }

            if (($locked->channel ?? 'en_ligne') === 'boutique') {
                throw new InvalidArgumentException('Cette vente de caisse est déjà encaissée');
            }

            $allowed = $this->enabledMethods();
            if (! in_array($method, $allowed, true)) {
                throw new InvalidArgumentException('Ce mode de paiement n’est pas actif');
            }

            if ($amount < 1) {
                throw new InvalidArgumentException('Le montant doit être au moins 1 FCFA');
            }

            $snap = $this->snapshot($locked);
            if ($amount > $snap['balance']) {
                throw new InvalidArgumentException(
                    'Le montant dépasse le solde restant ('.$snap['balance'].' FCFA)'
                );
            }

            $payment = OrderPayment::query()->create([
                'order_id' => $locked->id,
                'method' => $method,
                'amount' => $amount,
                'reference' => $reference,
                'note' => $note,
                'recorded_by' => $actor->id,
            ]);

            $locked->amount_received = $snap['paid_amount'] + $amount;
            $locked->save();

            $fresh = $locked->fresh('payments');
            $after = $this->snapshot($fresh);

            ActivityLogger::record(
                $actor,
                'order.payment_recorded',
                'Paiement de '.$amount.' FCFA enregistré sur CMD-'.str_pad((string) $locked->id, 6, '0', STR_PAD_LEFT),
                $locked,
                [
                    'method' => $method,
                    'amount' => $amount,
                    'reference' => $reference,
                    'paid_amount' => $after['paid_amount'],
                    'balance' => $after['balance'],
                    'payment_status' => $after['payment_status'],
                ],
                'admin',
            );

            return $payment;
        });
    }

    public function assertCanAccept(Order $order): void
    {
        $snap = $this->snapshot($order);
        if ($snap['can_validate']) {
            return;
        }

        throw new InvalidArgumentException(
            $snap['min_deposit_percent'] > 0
                ? 'Acompte insuffisant : '.$snap['min_deposit_amount'].' FCFA requis ('.$snap['min_deposit_percent'].' %)'
                : 'Le paiement n’est pas encore validé'
        );
    }

    /**
     * @return list<string>
     */
    public function enabledMethods(): array
    {
        $settings = ShopSetting::current();
        $configured = is_array($settings->payment_methods) ? $settings->payment_methods : [];
        $enabled = array_values(array_intersect(ShopSetting::paymentMethodKeys(), $configured));

        return $enabled !== [] ? $enabled : ShopSetting::paymentMethodKeys();
    }
}

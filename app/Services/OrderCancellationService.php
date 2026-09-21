<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class OrderCancellationService
{
    public function cancel(Order $order, User $actor, string $reason, string $channel = 'admin'): Order
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('Le motif d’annulation est obligatoire (3 caractères minimum)');
        }
        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Le motif ne peut pas dépasser 1000 caractères');
        }

        $cancelled = DB::transaction(function () use ($order, $actor, $reason, $channel) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->isClosed()) {
                throw new InvalidArgumentException(
                    $locked->status === 'expirée'
                        ? 'Cette commande est expirée'
                        : 'Cette commande est déjà annulée'
                );
            }

            $previous = $locked->status;
            $stockChannel = ($locked->channel ?? 'en_ligne') === 'boutique' ? 'pos' : 'site';
            app(StockService::class)->syncOrderHold($locked, 'annulée', $actor, $stockChannel);

            $locked->status = 'annulée';
            $locked->cancelled_by = $actor->id;
            $locked->cancellation_reason = $reason;
            $locked->cancelled_at = now();
            $locked->save();

            $credit = app(OrderPaymentService::class)->issueCredit(
                $locked->fresh('payments'),
                $actor,
                $reason,
            );

            ActivityLogger::record(
                $actor,
                'order.cancelled',
                'Commande #'.$locked->id.' annulée : '.$reason,
                $locked,
                [
                    'reason' => $reason,
                    'previous_status' => $previous,
                    'refunded_amount' => $credit ? (int) round((float) $credit->amount) : 0,
                ],
                $channel === 'pos' ? 'pos' : 'admin',
            );

            return $locked->fresh(['payments', 'cancelledByUser', 'client']);
        });

        try {
            $snap = app(OrderPaymentService::class)->snapshot($cancelled);
            app(OrderNotifier::class)->notify($cancelled, OrderNotifier::CANCELLED, [
                'reason' => $cancelled->cancellation_reason,
                'refunded_amount' => $snap['refunded_amount'],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return $cancelled;
    }
}

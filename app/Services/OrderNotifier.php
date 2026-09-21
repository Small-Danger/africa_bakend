<?php

namespace App\Services;

use App\Mail\OrderStatusMail;
use App\Models\Notification;
use App\Models\Order;
use App\Models\ShopSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class OrderNotifier
{
    public const PAYMENT = 'payment_recorded';

    public const ACCEPTED = 'order_accepted';

    public const READY = 'order_ready';

    public const AVAILABLE = 'order_available';

    public const PREORDER = 'preorder_available';

    public const CANCELLED = 'order_cancelled';

    public const EXPIRED = 'order_expired';

    public function notify(Order $order, string $event, array $extra = []): ?Notification
    {
        $order->loadMissing(['client', 'payments']);
        $copy = $this->copyFor($order, $event, $extra);

        $notification = null;
        if ($order->client_id) {
            $notification = Notification::query()->create([
                'user_id' => $order->client_id,
                'title' => $copy['title'],
                'message' => $copy['message'],
                'type' => 'order_update',
                'data' => [
                    'event' => $event,
                    'order_id' => $order->id,
                    'order_number' => $copy['order_number'],
                    'email_sent' => false,
                ],
                'is_read' => false,
            ]);
        }

        $emailSent = $this->sendEmail($order->client, $copy);
        if ($notification && $emailSent) {
            $notification->data = array_merge($notification->data ?? [], ['email_sent' => true]);
            $notification->save();
        }

        return $notification;
    }

    /**
     * @param  array{title: string, message: string, order_number: string}  $copy
     */
    private function sendEmail(?User $client, array $copy): bool
    {
        if (! $this->canEmail($client)) {
            return false;
        }

        try {
            Mail::to($client->email)->send(new OrderStatusMail(
                $copy['title'],
                $copy['message'],
                $copy['order_number'],
            ));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function canEmail(?User $client): bool
    {
        if (! $client) {
            return false;
        }

        if (! ShopSetting::current()->notify_email) {
            return false;
        }

        $email = strtolower(trim((string) $client->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (str_ends_with($email, '@afrikraga.local') || str_ends_with($email, '@bs-shop.com')) {
            return false;
        }

        $local = explode('@', $email)[0] ?? '';

        return ! str_starts_with($local, 'pos_') && ! str_starts_with($local, 'temp_');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{title: string, message: string, order_number: string}
     */
    private function copyFor(Order $order, string $event, array $extra): array
    {
        $number = 'CMD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
        $total = (int) round((float) $order->total_amount);
        $paid = (int) round((float) ($extra['paid_amount'] ?? 0));
        $balance = (int) round((float) ($extra['balance'] ?? 0));
        $refunded = (int) round((float) ($extra['refunded_amount'] ?? 0));
        $reason = trim((string) ($extra['reason'] ?? $order->cancellation_reason ?? ''));

        [$title, $message] = match ($event) {
            self::PAYMENT => $balance <= 0
                ? ['Commande soldée', $number.' est payée intégralement ('.$total.' FCFA).']
                : ['Acompte reçu', 'Nous avons enregistré '.$paid.' FCFA sur '.$number.'. Reste '.$balance.' FCFA.'],
            self::ACCEPTED => ['Commande acceptée', 'Nous préparons '.$number.'.'],
            self::READY => ['Commande prête', $number.' est prête. Nous convenons du retrait sur WhatsApp.'],
            self::AVAILABLE => ['Disponible à la boutique', $number.' vous attend à la boutique.'],
            self::PREORDER => ['Précommande disponible', 'L’article de '.$number.' est arrivé. Vous pouvez passer le récupérer.'],
            self::CANCELLED => [
                'Commande annulée',
                $number.' a été annulée'.($reason !== '' ? ' : '.$reason : '.').
                ($refunded > 0 ? ' Un avoir de '.$refunded.' FCFA a été enregistré.' : ''),
            ],
            self::EXPIRED => ['Commande expirée', $number.' a expiré faute de paiement dans le délai.'],
            default => ['Mise à jour de commande', $number.' a été mise à jour.'],
        };

        return [
            'title' => $title,
            'message' => $message,
            'order_number' => $number,
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    /**
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        return [
            'team.created' => 'Compte créé',
            'team.updated' => 'Compte modifié',
            'team.activated' => 'Compte activé',
            'team.deactivated' => 'Compte désactivé',
            'settings.updated' => 'Paramètres mis à jour',
            'stock.adjusted' => 'Stock corrigé',
            'stock.received' => 'Réception de stock',
            'stock.receipt_updated' => 'Arrivage modifié',
            'stock.receipt_cancelled' => 'Arrivage annulé',
            'stock.reserved' => 'Stock réservé',
            'stock.reservation_released' => 'Réservation libérée',
            'stock.reservation_expired' => 'Réservation expirée',
            'stock.preorder_queued' => 'Précommande en file',
            'stock.preorder_allocated' => 'Précommande servie',
            'stock.preorder_cancelled' => 'Précommande annulée',
            'cash.closed' => 'Clôture de caisse',
        ];
    }

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'properties',
        'channel',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => self::actionLabels()[$this->action] ?? $this->action,
            'description' => $this->description,
            'properties' => $this->properties ?? [],
            'channel' => $this->channel,
            'actor' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role,
                'role_label' => \App\Authorization\Roles::label((string) $this->user->role),
            ] : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}

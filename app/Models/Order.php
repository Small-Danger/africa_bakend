<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'client_id',
        'total_amount',
        'status',
        'whatsapp_message_id',
        'notes'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2'
    ];

    // Relation avec le client
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    // Relation avec les éléments de commande
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // Scope pour les commandes en attente
    public function scopePending($query)
    {
        return $query->where('status', 'en_attente');
    }

    // Scope pour les commandes validées
    public function scopeValidated($query)
    {
        return $query->where('status', 'validée');
    }

    // Méthode pour calculer le total
    public function calculateTotal(): void
    {
        $this->total_amount = $this->items->sum('total_price');
        $this->save();
    }

    // Méthode pour valider la commande
    public function validate(): void
    {
        $this->status = 'validée';
        $this->save();
    }

    // Méthode pour annuler la commande
    public function cancel(): void
    {
        $this->status = 'annulée';
        $this->save();
    }
}

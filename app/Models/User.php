<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'whatsapp_phone',
        'role',
        'is_active'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Relations pour les clients
    public function orders()
    {
        return $this->hasMany(Order::class, 'client_id');
    }

    public function cartSessions()
    {
        return $this->hasMany(CartSession::class);
    }

    // Scopes
    public function scopeClients($query)
    {
        return $query->where('role', 'client');
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    // Méthodes utilitaires
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isClient(): bool
    {
        return $this->role === 'client';
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isBlocked(): bool
    {
        return !$this->is_active;
    }

    // Accessor pour la propriété is_admin
    public function getIsAdminAttribute(): bool
    {
        return $this->isAdmin();
    }

    // Accessor pour la propriété is_client
    public function getIsClientAttribute(): bool
    {
        return $this->isClient();
    }

    public function getActiveCart()
    {
        return $this->cartSessions()
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }

    // Méthode pour créer ou récupérer un client par WhatsApp
    public static function findByWhatsApp(string $whatsappPhone): ?self
    {
        return static::where('whatsapp_phone', $whatsappPhone)->first();
    }

    // Méthode pour créer un nouveau client
    public static function createFromWhatsApp(string $name, string $whatsappPhone, ?string $email = null): self
    {
        return static::create([
            'name' => $name,
            'whatsapp_phone' => $whatsappPhone,
            'email' => $email,
            'role' => 'client',
            'password' => bcrypt(Str::random(16)) // Mot de passe temporaire
        ]);
    }
}

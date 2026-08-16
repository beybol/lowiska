<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasName, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory,
        HasRoles,
        LogsActivity,
        Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'surname',
        'email',
        'password',
        'country_id',
        'phone',
        'two_factor_code', 'two_factor_expires_at',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // ⚠️ Żywy kod drugiego składnika. Bez tego trafiał do `attributesToArray()`,
        // a stamtąd hurtem do stanu formularza edycji użytkownika, czyli do snapshotu
        // Livewire widocznego dla klienta (audyt bezpieczeństwa, zadanie 012).
        'two_factor_code',
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

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->is_admin && ! $user->email_verified_at) {
                $user->email_verified_at = now();
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            return $this->is_admin == 1;
        }

        return true;
    }

    public function getFilamentName(): string
    {
        return "{$this->name} {$this->surname}";
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function generateTwoFactorCode(): void
    {
        $this->timestamps = false;
        // ⚠️ `random_int`, nie `rand`. Mersenne Twister jest deterministyczny wobec
        // stanu, który napastnik może próbkować bez ograniczeń przez ponowne wysyłanie
        // kodu na WŁASNYM koncie — a to jest drugi składnik uwierzytelnienia.
        $this->two_factor_code = random_int(100000, 999999);
        $this->two_factor_expires_at = now()->addMinutes(10);
        $this->save();
    }

    public function resetTwoFactorCode(): void
    {
        $this->timestamps = false;
        $this->two_factor_code = null;
        $this->two_factor_expires_at = null;
        $this->save();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            // ⚠️ Bez `logExcept` dziennik zapisywał HASH HASŁA i żywy kod 2FA przy każdym
            // logowaniu: `logOnlyDirty` jest domyślnie wyłączone, więc pakiet zrzuca pełny
            // snapshot logowanych atrybutów, a `resolveAttributeValue()` czyta przez
            // `getAttribute()`, czyli **pomija `$hidden`**. Tabela `users` trzyma tylko
            // bieżący hash — dziennik kumulowałby każdy historyczny przez rok.
            ->logExcept(['password', 'two_factor_code', 'two_factor_expires_at'])
            ->logOnlyDirty();
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}

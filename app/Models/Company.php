<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Company extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'is_verified',
        'name',
        'tin',
        'renae',
        'street',
        'house_number',
        'flat_number',
        'postal_code',
        'city',
        'state_id',
        'cso_response',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable);
    }

    #[Scope]
    protected function forCurrentUser(Builder $query): void
    {
        // ⚠️ Fail-CLOSED. Wcześniej brak zalogowanego użytkownika oznaczał, że scope
        // nie dokłada NICZEGO — czyli prymityw, który cała reszta kodu traktuje jak
        // bramkę, przy gościu przepuszczał wszystko. Trasy paneli są za `Authenticate`,
        // więc dziś nieosiągalne, ale to nie jest powód, żeby zostawiać fail-open.
        if (! auth()->check()) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where('user_id', auth()->id());
    }

    #[Scope]
    protected function findByNumber(Builder $query, string $tin, string $renae): void
    {
        // ⚠️ Warunki DOMKNIĘTE w grupę. Bez tego `orWhere` wychodził poza scope
        // i każdy przyszły wołający, który dołożyłby własny warunek, dostałby go
        // po cichu zniesionego przez alternatywę.
        $query->where(function (Builder $grouped) use ($tin, $renae): void {
            if (filled($tin)) {
                $grouped->orWhere('tin', $tin);
            }

            if (filled($renae)) {
                $grouped->orWhere('renae', $renae);
            }

            // Komplet pustych numerów nie ma dopasowywać niczego — wcześniej trafiał
            // w pierwszą firmę z pustym `renae` i zgłaszał ją jako kolizję.
            if (blank($tin) && blank($renae)) {
                $grouped->whereRaw('0 = 1');
            }
        });
    }
}

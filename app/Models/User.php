<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_admin', 'can_use_ats_optimization'])]
#[Hidden(['password', 'remember_token', 'capture_token_hash', 'capture_token_last_four', 'disabled_at'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'is_admin' => false,
        'can_use_ats_optimization' => false,
    ];

    /**
     * Aplicativo aberto: toda conta cadastrada pode acessar o painel admin,
     * exceto contas desativadas.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->disabled_at !== null) {
            return false;
        }

        return $panel->getId() === 'admin';
    }

    public function screeningPreference(): HasOne
    {
        return $this->hasOne(ScreeningPreference::class);
    }

    public function professionalCompetencies(): HasMany
    {
        return $this->hasMany(ProfessionalCompetency::class);
    }

    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    public function jobCaptures(): HasMany
    {
        return $this->hasMany(JobCapture::class);
    }

    public function resume(): HasOne
    {
        return $this->hasOne(Resume::class);
    }

    public function resumes(): HasMany
    {
        return $this->hasMany(Resume::class);
    }

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
            'disabled_at' => 'datetime',
            'is_admin' => 'boolean',
            'can_use_ats_optimization' => 'boolean',
        ];
    }
}

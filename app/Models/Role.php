<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name', 'label'];

    public function getDisplayNameAttribute(): string
    {
        return match ($this->name) {
            'nurse', 'registration' => 'Staff',
            'super-admin' => 'Super Admin',
            default => $this->label,
        };
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }
}

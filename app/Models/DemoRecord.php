<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoRecord extends Model
{
    protected $guarded = [];

    public static function mark(Model $model, string $demoKey): self
    {
        return static::create([
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
            'demo_key' => $demoKey,
        ]);
    }

    public static function isMarked(Model $model): bool
    {
        return static::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->exists();
    }
}

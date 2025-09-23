<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'version', 'description'];

    public function decisions()
    {
        return $this->hasMany(AiDecision::class, 'model_id');
    }

    public function retrainings()
    {
        return $this->hasMany(ModelRetraining::class, 'model_id');
    }
}

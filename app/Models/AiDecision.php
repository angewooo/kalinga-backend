<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiDecision extends Model
{
    use HasFactory;

    protected $fillable = ['model_id', 'decision_data', 'confidence_score'];

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}

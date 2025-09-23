<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PredictionAccuracy extends Model
{
    use HasFactory;

    protected $fillable = ['model_id', 'accuracy_score', 'evaluated_at'];

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}

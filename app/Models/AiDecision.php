<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiDecision extends Model
{
    use HasFactory;
    protected $primaryKey = 'decision_id';

    protected $fillable = ['model_id', 'decision_data', 'confidence_score'];
    public $timestamps = false;

    protected $table = 'ai_decisions';

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}

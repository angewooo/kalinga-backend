<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModelRetraining extends Model
{
    use HasFactory;

    protected $primaryKey = 'retrain_id';
    protected $table = 'model_retraining'; // matches your migration
    public $timestamps = false;

    protected $fillable = [
        'model_id',
        'reason',
        'old_accuracy',
        'new_accuracy',
        'training_duration_minutes',
        'retrained_at'
    ];

    public function aiModel()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}

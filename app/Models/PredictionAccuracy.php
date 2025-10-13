<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PredictionAccuracy extends Model
{
    use HasFactory;

    protected $primaryKey = 'accuracy_id';
    protected $table = 'prediction_accuracy'; // ✅ fix table name
    public $timestamps = false; // optional, if migration doesn’t use timestamps

    protected $fillable = [
        'forecast_id',
        'actual_demand',
        'prediction_error',
        'error_percentage',
        'recorded_at'
    ];

    public function forecast()
    {
        return $this->belongsTo(ForecastResult::class, 'forecast_id');
    }
}

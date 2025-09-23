<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ForecastResult extends Model
{
    use HasFactory;

    protected $fillable = ['model_id', 'forecast_data', 'generated_at'];

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}

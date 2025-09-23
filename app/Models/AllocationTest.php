<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AllocationTest extends Model
{
    use HasFactory;

    protected $fillable = ['algorithm_id', 'test_name', 'status', 'results'];

    public function algorithm()
    {
        return $this->belongsTo(AllocationAlgorithm::class, 'algorithm_id');
    }
}

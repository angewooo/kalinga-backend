<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AllocationAlgorithm extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'version'];

    public function tests()
    {
        return $this->hasMany(AllocationTest::class, 'algorithm_id');
    }
}

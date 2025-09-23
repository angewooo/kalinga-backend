<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutomatedAction extends Model
{
    use HasFactory;

    protected $fillable = ['trigger', 'action_type', 'status', 'executed_at'];
}

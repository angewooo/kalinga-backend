<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutomatedAction extends Model
{
    use HasFactory;
    protected $primaryKey = 'action_id';

    protected $fillable = ['trigger_condition', 'action_type', 'target_table', 'action_parameters', 'is_active', 'created_by'];
    public $timestamps = false;
    protected $table = 'automated_actions';
}

<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'qr_code',
        'account_status',
        'last_active',
        'failed_login_attempts',
        'locked_until'
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_active' => 'datetime',
        'locked_until' => 'datetime',
    ];

    public function profile() { return $this->hasOne(UserProfile::class, 'user_id'); }
    public function responderDetails() { return $this->hasOne(ResponderDetail::class, 'user_id'); }
    public function adminDetails() { return $this->hasOne(AdminDetail::class, 'user_id'); }
    public function responder() { return $this->hasOne(Responder::class, 'user_id'); }
}

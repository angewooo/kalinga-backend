<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    /**
     * The guard name defines which authentication guard this permission applies to.
     * Keep 'web' to match Laravel Sanctum + default auth guard.
     */
    protected $guard_name = 'web';
}

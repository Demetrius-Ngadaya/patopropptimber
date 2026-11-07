<?php

namespace App\Models\Mobile;

use Illuminate\Database\Eloquent\Model;

/** A change the mobile app made (often offline) and that the server has applied. */
class SyncMutation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array', 'client_created_at' => 'datetime'];
    }
}

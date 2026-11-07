<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    public $timestamps = false;

    protected $fillable = ['type', 'year', 'last_number'];
}

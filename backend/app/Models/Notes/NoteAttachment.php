<?php

namespace App\Models\Notes;

use Illuminate\Database\Eloquent\Model;

class NoteAttachment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path'];
}

<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Model;

/** A visitor's activity on one day (unique visitors per day, week, month, year). */
class SiteVisitorDay extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'date', 'first_at' => 'datetime', 'last_at' => 'datetime', 'page_views' => 'integer'];
    }
}

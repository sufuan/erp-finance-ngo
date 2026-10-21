<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Village (manual master). Its geography is NOT a database relation:
 * division/district/upazila/union are plain stored values (bd-geo-info
 * package id as string + name) captured from the GeoCascade dropdowns.
 */
class Village extends Model
{
    protected $fillable = [
        'name',
        'division_id', 'division_name',
        'district_id', 'district_name',
        'upazila_id', 'upazila_name',
        'union_id', 'union_name',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InternetStatus extends Model
{
    use HasFactory;
    protected $table = 'internet_statuses';

    protected $fillable = [
        'site_id',
        'device_id',
        'state',
        'active_wan',
        'wan1_role',
        'wan1_status',
        'wan1_ip',
        'wan2_role',
        'wan2_status',
        'wan2_ip',
        'failover_at',
        'restored_at',
        'checked_at',
        'image',
    ];

    public function histories()
    {
        return $this->hasMany(
            WanFailoverHistory::class,
            'device_id',   // FK on history
            'device_id'    // local key
        )->orderBy('created_at', 'desc');
    }
    
    public function latestHistory()
    {
        return $this->hasOne(
            WanFailoverHistory::class,
            'device_id',
            'device_id'
        )->latestOfMany('created_at');
    }
}
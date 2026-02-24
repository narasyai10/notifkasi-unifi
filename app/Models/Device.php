<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Device extends Model
{
    use HasFactory;
    protected $table = 'devices';

    protected $fillable = [
        'device_id',
        'name',
        'type',
        'image',
        'model',
        'ip',
        'mac',
        'uptime',
        'status',
        'host_name',
        'site_id',
        'checked_at',
    ];

    protected $casts = [
        'uptime' => 'integer',
    ];

    public function histories()
    {
        return $this->hasMany(DeviceHistory::class, 'device_id', 'device_id');
    }

    public function wanFailovers()
    {
        return $this->hasMany(WanFailoverHistory::class, 'device_id', 'device_id');
    }
}
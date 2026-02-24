<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DeviceHistory extends Model
{
    use HasFactory;
    protected $table = 'device_histories';
    protected $fillable = [
        'device_id',
        'event',
        'old_status',
        'new_status',
        'old_ip',
        'new_ip',
        'old_uptime',
        'new_uptime',
        'description',
        'created_at',
    ];

    protected $casts = [
        'old_uptime' => 'integer',
        'new_uptime' => 'integer',
        'created_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
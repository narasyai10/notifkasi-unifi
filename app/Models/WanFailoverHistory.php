<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WanFailoverHistory extends Model
{
    use HasFactory;
    protected $table = 'wan_failover_histories';

    protected $fillable = [
        'device_id',
        'state',
        'active_wan',
        'wan1_ip',
        'wan2_ip',
        'failover_at',
        'restored_at',
        'created_at',
        'updated_at',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
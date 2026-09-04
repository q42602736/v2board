<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'auto_speedlimit_manual_restore_at' => 'integer'
    ];

    /**
     * 今天使用的流量占总流量的百分比
     */
    public function getTodayUsedTrafficPercent()
    {
        if ($this->transfer_enable == 0) {
            return 0;
        }
        $todayUsed = ($this->u + $this->d) - $this->last_day_t;
        $percent = ($todayUsed / $this->transfer_enable) * 100;
        return round($percent, 2);
    }

    /**
     * 今天使用的流量占昨日剩余流量的百分比
     */
    public function getTodayUsedTrafficPercentOfRemaining()
    {
        if ($this->transfer_enable == 0) {
            return 0;
        }

        // 计算昨日结束时的剩余流量
        $yesterdayRemaining = $this->transfer_enable - $this->last_day_t;

        // 计算今日使用的流量
        $todayUsed = ($this->u + $this->d) - $this->last_day_t;

        // 特殊情况处理
        if ($yesterdayRemaining <= 0) {
            return $todayUsed > 0 ? 100 : 100;
        }

        // 正常情况：计算今日流量占昨日剩余流量的百分比
        $percent = ($todayUsed / $yesterdayRemaining) * 100;

        // 如果今日使用超过了昨日剩余流量，返回100%
        return min(100, max(0, round($percent, 2)));
    }

    /**
     * 总流量使用百分比
     */
    public function getTrafficUsagePercent()
    {
        if ($this->transfer_enable == 0) {
            return 0;
        }
        $total = $this->u + $this->d;
        $percent = ($total / $this->transfer_enable) * 100;
        return round($percent, 2);
    }

    /**
     * 今天使用的流量（字节）
     */
    public function getTodayUsedTraffic()
    {
        return ($this->u + $this->d) - $this->last_day_t;
    }

    /**
     * 剩余流量（字节）
     */
    public function getRemainingTraffic()
    {
        return max(0, $this->transfer_enable - ($this->u + $this->d));
    }

    /**
     * 自动限速日志关联
     */
    public function autoSpeedlimitLogs()
    {
        return $this->hasMany(AutoSpeedlimitLog::class);
    }
}

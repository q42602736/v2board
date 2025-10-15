<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoSpeedlimitLog extends Model
{
    protected $table = 'v2_auto_speedlimit_log';
    protected $dateFormat = 'U'; // 使用Unix时间戳格式，与V2Board保持一致

    protected $fillable = [
        'user_id', 'action', 'old_status', 'new_status',
        'old_speedlimit', 'new_speedlimit', 'trigger_info',
        'daily_percent', 'total_percent'
    ];

    protected $casts = [
        'daily_percent' => 'decimal:2',
        'total_percent' => 'decimal:2',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 获取操作类型描述
     */
    public function getActionDescription()
    {
        $descriptions = [
            'limit' => '限速',
            'restore' => '恢复'
        ];
        
        return $descriptions[$this->action] ?? '未知';
    }

    /**
     * 获取状态描述
     */
    public function getStatusDescription($status)
    {
        if ($status == 0) {
            return '正常';
        }
        return "限速等级{$status}";
    }

    /**
     * 获取旧状态描述
     */
    public function getOldStatusDescription()
    {
        return $this->getStatusDescription($this->old_status);
    }

    /**
     * 获取新状态描述
     */
    public function getNewStatusDescription()
    {
        return $this->getStatusDescription($this->new_status);
    }

    /**
     * 获取限速值描述
     */
    public function getSpeedlimitDescription($speedlimit)
    {
        if ($speedlimit === null || $speedlimit == 0) {
            return '不限速';
        }
        return $speedlimit . 'Mbps';
    }

    /**
     * 获取旧限速描述
     */
    public function getOldSpeedlimitDescription()
    {
        return $this->getSpeedlimitDescription($this->old_speedlimit);
    }

    /**
     * 获取新限速描述
     */
    public function getNewSpeedlimitDescription()
    {
        return $this->getSpeedlimitDescription($this->new_speedlimit);
    }

    /**
     * 创建限速日志
     */
    public static function createLimitLog($userId, $oldStatus, $newStatus, $oldSpeedlimit, $newSpeedlimit, $triggerInfo, $dailyPercent = null, $totalPercent = null)
    {
        return self::create([
            'user_id' => $userId,
            'action' => 'limit',
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_speedlimit' => $oldSpeedlimit,
            'new_speedlimit' => $newSpeedlimit,
            'trigger_info' => $triggerInfo,
            'daily_percent' => $dailyPercent,
            'total_percent' => $totalPercent,
        ]);
    }

    /**
     * 创建恢复日志
     */
    public static function createRestoreLog($userId, $oldStatus, $newStatus, $oldSpeedlimit, $newSpeedlimit, $reason)
    {
        return self::create([
            'user_id' => $userId,
            'action' => 'restore',
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_speedlimit' => $oldSpeedlimit,
            'new_speedlimit' => $newSpeedlimit,
            'trigger_info' => $reason,
            'daily_percent' => null,
            'total_percent' => null,
        ]);
    }

    /**
     * 获取用户的限速历史
     */
    public static function getUserHistory($userId, $limit = 50)
    {
        return self::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * 获取最近的限速操作统计
     */
    public static function getRecentStats($days = 7)
    {
        $startDate = now()->subDays($days);
        
        $stats = self::where('created_at', '>=', $startDate)
            ->selectRaw('
                action,
                COUNT(*) as count,
                COUNT(DISTINCT user_id) as unique_users
            ')
            ->groupBy('action')
            ->get()
            ->keyBy('action');
        
        return [
            'limit_operations' => $stats->get('limit')->count ?? 0,
            'restore_operations' => $stats->get('restore')->count ?? 0,
            'limited_users' => $stats->get('limit')->unique_users ?? 0,
            'restored_users' => $stats->get('restore')->unique_users ?? 0,
        ];
    }

    /**
     * 获取当前被限速的用户数量
     */
    public static function getCurrentLimitedUsersCount()
    {
        return User::where('auto_speedlimit_status', '>', 0)->count();
    }

    /**
     * 清理旧日志（保留指定天数）
     */
    public static function cleanOldLogs($keepDays = 30)
    {
        $cutoffDate = now()->subDays($keepDays);
        return self::where('created_at', '<', $cutoffDate)->delete();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckinLog extends Model
{
    protected $table = 'v2_checkin_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    // 指定日期字段的格式
    protected $dates = ['checkin_date'];

    /**
     * 设置签到日期格式
     */
    public function setCheckinDateAttribute($value)
    {
        // 确保日期以 Y-m-d 格式保存
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->attributes['checkin_date'] = $value;
        } else {
            $this->attributes['checkin_date'] = date('Y-m-d', is_numeric($value) ? $value : strtotime($value));
        }
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 检查用户今日是否已签到
     */
    public static function isTodayCheckedIn($userId)
    {
        return self::where('user_id', $userId)
            ->where('checkin_date', date('Y-m-d'))
            ->exists();
    }

    /**
     * 获取用户连续签到天数
     */
    public static function getConsecutiveDays($userId)
    {
        $latest = self::where('user_id', $userId)
            ->orderBy('checkin_date', 'desc')
            ->first();
        
        if (!$latest) {
            return 0;
        }

        // 如果最后一次签到不是昨天或今天，连续天数重置为0
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');
        
        if ($latest->checkin_date != $yesterday && $latest->checkin_date != $today) {
            return 0;
        }

        return $latest->consecutive_days;
    }

    /**
     * 用户签到
     */
    public static function checkin($userId)
    {
        $today = date('Y-m-d');
        
        // 检查今日是否已签到
        if (self::isTodayCheckedIn($userId)) {
            return ['success' => false, 'message' => '今日已签到'];
        }

        // 获取用户信息
        $user = User::find($userId);
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        // 获取签到配置
        $config = CheckinConfig::getConfigByPlanId($user->plan_id);
        if (!$config) {
            return ['success' => false, 'message' => '签到配置不存在'];
        }

        // 获取连续签到天数
        $consecutiveDays = self::getConsecutiveDays($userId) + 1;
        
        // 计算奖励流量
        if ($config->reward_mode === 'random') {
            // 随机模式
            $minTraffic = $config->min_traffic ?: $config->daily_traffic;
            $maxTraffic = $config->max_traffic ?: $config->daily_traffic;
            $rewardTraffic = rand($minTraffic, $maxTraffic);
            $rewardDesc = self::formatBytes($rewardTraffic) . '(随机奖励)';
        } else {
            // 固定模式
            $rewardTraffic = $config->daily_traffic;
            $rewardDesc = self::formatBytes($rewardTraffic);
        }

        // 连续签到奖励（只有固定模式才有连续奖励）
        if ($consecutiveDays >= $config->consecutive_days && $config->reward_mode === 'fixed' && $config->consecutive_bonus > 0) {
            $rewardTraffic += $config->consecutive_bonus;
            $rewardDesc .= ' + ' . self::formatBytes($config->consecutive_bonus) . '(连续' . $config->consecutive_days . '天奖励)';
        }

        try {
            \DB::beginTransaction();

            // 创建签到记录
            $checkin = self::create([
                'user_id' => $userId,
                'checkin_date' => $today,
                'plan_id' => $user->plan_id,
                'reward_traffic' => $rewardTraffic,
                'reward_desc' => $rewardDesc,
                'consecutive_days' => $consecutiveDays,
            ]);

            // 原子增加总额度和未清零签到额度，避免与流量重置并发时覆盖数据。
            $rewardTraffic = (int)$rewardTraffic;
            $updated = User::where('id', $userId)->update([
                'transfer_enable' => \DB::raw("transfer_enable + {$rewardTraffic}"),
                'checkin_traffic' => \DB::raw("checkin_traffic + {$rewardTraffic}"),
            ]);
            if ($updated !== 1) {
                throw new \RuntimeException('更新用户签到流量失败');
            }

            \DB::commit();

            return [
                'success' => true,
                'consecutive_days' => $consecutiveDays,
                'reward_traffic' => $rewardTraffic,
                'reward_desc' => $rewardDesc,
                'message' => "签到成功！连续签到{$consecutiveDays}天，获得{$rewardDesc}"
            ];

        } catch (\Exception $e) {
            \DB::rollBack();
            return ['success' => false, 'message' => '签到失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取用户签到历史
     */
    public static function getUserHistory($userId, $limit = 30)
    {
        return self::where('user_id', $userId)
            ->orderBy('checkin_date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * 获取用户签到统计
     */
    public static function getUserStats($userId)
    {
        $totalDays = self::where('user_id', $userId)->count();
        $totalTraffic = self::where('user_id', $userId)->sum('reward_traffic');
        $consecutiveDays = self::getConsecutiveDays($userId);
        $todayChecked = self::isTodayCheckedIn($userId);

        return [
            'total_days' => $totalDays,
            'total_traffic' => $totalTraffic,
            'total_traffic_formatted' => self::formatBytes($totalTraffic),
            'consecutive_days' => $consecutiveDays,
            'today_checked' => $todayChecked,
        ];
    }

    /**
     * 格式化字节数
     */
    public static function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

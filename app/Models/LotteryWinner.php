<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotteryWinner extends Model
{
    protected $table = 'v2_lottery_winner';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = [
        'created_at' => 'datetime'
    ];

    protected $appends = [
        'reward_amount_formatted',
        'reward_type_text',
        'created_at_formatted'
    ];

    // 奖励类型常量
    const REWARD_TYPE_BALANCE = 'balance';
    const REWARD_TYPE_TRAFFIC = 'traffic';

    /**
     * 获取格式化的奖励金额（元）或流量（MB）
     */
    public function getRewardAmountFormattedAttribute()
    {
        if ($this->reward_type === self::REWARD_TYPE_BALANCE) {
            // 余额类型：分转元
            return number_format($this->reward_amount / 100, 2);
        } else {
            // 流量类型：字节转MB
            return number_format($this->reward_amount / (1024 * 1024), 0);
        }
    }

    /**
     * 获取奖励类型文本
     */
    public function getRewardTypeTextAttribute()
    {
        return $this->reward_type === self::REWARD_TYPE_BALANCE ? '余额' : '流量';
    }

    /**
     * 获取格式化的创建时间
     */
    public function getCreatedAtFormattedAttribute()
    {
        return $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : '';
    }

    /**
     * 关联抽奖记录
     */
    public function lotteryLog()
    {
        return $this->belongsTo(LotteryLog::class, 'lottery_log_id');
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 创建中奖记录
     */
    public static function createWinner($lotteryLogId, $userId, $rewardType, $rewardAmount, $roundNumber)
    {
        return self::create([
            'lottery_log_id' => $lotteryLogId,
            'user_id' => $userId,
            'reward_type' => $rewardType,
            'reward_amount' => $rewardAmount,
            'round_number' => $roundNumber,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 检查用户是否在冷却期内
     */
    public static function isUserInCooldown($userId, $cooldownRounds)
    {
        if ($cooldownRounds <= 0) {
            return false;
        }

        // 获取最近的中奖记录
        $recentWin = self::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$recentWin) {
            return false;
        }

        // 计算从最近中奖到现在的轮次数
        $recentRounds = self::where('created_at', '>', $recentWin->created_at)
            ->distinct('round_number')
            ->count();

        return $recentRounds < $cooldownRounds;
    }

    /**
     * 获取用户最近中奖记录
     */
    public static function getUserRecentWins($userId, $limit = 10)
    {
        return self::where('user_id', $userId)
            ->with(['lotteryLog.config'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * 获取用户总中奖次数
     */
    public static function getUserWinCount($userId)
    {
        return self::where('user_id', $userId)->count();
    }

    /**
     * 获取用户总中奖金额
     */
    public static function getUserTotalWinAmount($userId, $rewardType = null)
    {
        $query = self::where('user_id', $userId);
        
        if ($rewardType) {
            $query->where('reward_type', $rewardType);
        }
        
        return $query->sum('reward_amount');
    }

    /**
     * 作用域：按用户筛选
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * 作用域：按奖励类型筛选
     */
    public function scopeByRewardType($query, $rewardType)
    {
        return $query->where('reward_type', $rewardType);
    }

    /**
     * 作用域：按轮次筛选
     */
    public function scopeByRound($query, $roundNumber)
    {
        return $query->where('round_number', $roundNumber);
    }

    /**
     * 作用域：今日中奖
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', date('Y-m-d'));
    }

    /**
     * 作用域：本周中奖
     */
    public function scopeThisWeek($query)
    {
        return $query->whereBetween('created_at', [
            strtotime('monday this week'),
            strtotime('sunday this week') + 86399
        ]);
    }

    /**
     * 作用域：本月中奖
     */
    public function scopeThisMonth($query)
    {
        return $query->whereMonth('created_at', date('m'))
                    ->whereYear('created_at', date('Y'));
    }
}

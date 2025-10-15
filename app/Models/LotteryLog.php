<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotteryLog extends Model
{
    protected $table = 'v2_lottery_log';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = [
        'executed_at' => 'datetime',
        'created_at' => 'datetime'
    ];

    protected $appends = [
        'total_reward_formatted',
        'status_text',
        'executed_at_formatted'
    ];

    // 状态常量
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_PROCESSING = 'processing';

    /**
     * 获取格式化的总奖励金额（元）
     */
    public function getTotalRewardFormattedAttribute()
    {
        return number_format($this->total_reward / 100, 2);
    }

    /**
     * 获取状态文本
     */
    public function getStatusTextAttribute()
    {
        $statusMap = [
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILED => '失败',
            self::STATUS_PROCESSING => '处理中'
        ];
        
        return $statusMap[$this->status] ?? '未知';
    }

    /**
     * 获取格式化的执行时间
     */
    public function getExecutedAtFormattedAttribute()
    {
        return $this->executed_at ? $this->executed_at->format('Y-m-d H:i:s') : '';
    }

    /**
     * 关联抽奖配置
     */
    public function config()
    {
        return $this->belongsTo(LotteryConfig::class, 'config_id');
    }

    /**
     * 关联中奖记录
     */
    public function winners()
    {
        return $this->hasMany(LotteryWinner::class, 'lottery_log_id');
    }

    /**
     * 生成轮次号
     */
    public static function generateRoundNumber($configId)
    {
        $today = date('Ymd');
        $todayCount = self::where('config_id', $configId)
            ->whereDate('executed_at', date('Y-m-d'))
            ->count();
        
        return $today . '-' . str_pad($todayCount + 1, 2, '0', STR_PAD_LEFT);
    }

    /**
     * 创建抽奖记录
     */
    public static function createRecord($configId, $participants, $winners, $totalReward)
    {
        $roundNumber = self::generateRoundNumber($configId);

        return self::create([
            'config_id' => $configId,
            'round_number' => $roundNumber,
            'total_participants' => $participants,
            'winner_count' => count($winners),
            'total_reward' => $totalReward,
            'executed_at' => date('Y-m-d H:i:s'),
            'status' => self::STATUS_PROCESSING,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 标记为成功
     */
    public function markAsSuccess()
    {
        $this->update(['status' => self::STATUS_SUCCESS]);
    }

    /**
     * 标记为失败
     */
    public function markAsFailed($errorMessage = null)
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage
        ]);
    }

    /**
     * 作用域：成功的记录
     */
    public function scopeSuccess($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * 作用域：失败的记录
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * 作用域：今日记录
     */
    public function scopeToday($query)
    {
        return $query->whereDate('executed_at', date('Y-m-d'));
    }

    /**
     * 作用域：按配置筛选
     */
    public function scopeByConfig($query, $configId)
    {
        return $query->where('config_id', $configId);
    }
}

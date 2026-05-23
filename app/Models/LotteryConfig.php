<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotteryConfig extends Model
{
    protected $table = 'v2_lottery_config';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = [
        'status' => 'boolean',
        'telegram_enabled' => 'boolean',
    ];

    protected $appends = [
        'reward_amount_formatted',
        'min_balance_formatted',
        'status_text',
        'reward_type_text'
    ];

    /**
     * 获取格式化的奖励金额（元）或流量（MB）
     */
    public function getRewardAmountFormattedAttribute()
    {
        if ($this->reward_type === 'balance') {
            // 余额类型：分转元
            return number_format($this->reward_amount / 100, 2);
        } else {
            // 流量类型：字节转MB
            return number_format($this->reward_amount / (1024 * 1024), 0);
        }
    }

    /**
     * 获取格式化的最低余额要求（元）
     */
    public function getMinBalanceFormattedAttribute()
    {
        return number_format(($this->min_balance ?? 0) / 100, 2);
    }

    /**
     * 获取状态文本
     */
    public function getStatusTextAttribute()
    {
        return $this->status ? '启用' : '禁用';
    }

    /**
     * 获取奖励类型文本
     */
    public function getRewardTypeTextAttribute()
    {
        return $this->reward_type === 'balance' ? '余额' : '流量';
    }
}

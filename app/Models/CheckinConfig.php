<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckinConfig extends Model
{
    protected $table = 'v2_checkin_config';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'enabled' => 'boolean',
        'reset_with_traffic' => 'boolean'
    ];

    // 奖励模式常量
    const REWARD_MODE_FIXED = 'fixed';
    const REWARD_MODE_RANDOM = 'random';

    /**
     * 关联套餐
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * 根据套餐ID获取配置
     */
    public static function getConfigByPlanId($planId = null)
    {
        // 先查找特定套餐的配置
        if ($planId) {
            $config = self::where('plan_id', $planId)
                ->where('enabled', true)
                ->first();
            
            if ($config) {
                return $config;
            }
        }

        // 如果没有找到特定配置，使用默认配置
        return self::where('plan_id', null)
            ->where('enabled', true)
            ->first();
    }

    /**
     * 获取所有启用的配置
     */
    public static function getAllConfigs()
    {
        return self::where('enabled', true)
            ->orderBy('plan_id')
            ->get();
    }

    /**
     * 获取需要在套餐流量重置时清除签到奖励的套餐ID
     */
    public static function getResetWithTrafficPlanIds(): array
    {
        $defaultConfig = self::whereNull('plan_id')
            ->where('enabled', true)
            ->first();
        $planConfigs = self::whereNotNull('plan_id')
            ->where('enabled', true)
            ->get()
            ->keyBy('plan_id');

        return Plan::pluck('id')
            ->filter(function ($planId) use ($defaultConfig, $planConfigs) {
                $config = $planConfigs->get($planId) ?: $defaultConfig;
                return $config && (bool)$config->reset_with_traffic;
            })
            ->map(function ($planId) {
                return (int)$planId;
            })
            ->values()
            ->all();
    }

    /**
     * 创建或更新配置
     */
    public static function createOrUpdate($planId, $data)
    {
        $config = self::where('plan_id', $planId)->first();
        
        $data['plan_id'] = $planId;
        $data['updated_at'] = time();
        
        if ($config) {
            $config->update($data);
            return $config;
        } else {
            $data['created_at'] = time();
            return self::create($data);
        }
    }

    /**
     * 格式化流量显示
     */
    public function getDailyTrafficFormattedAttribute()
    {
        return CheckinLog::formatBytes($this->daily_traffic);
    }

    /**
     * 格式化连续奖励显示
     */
    public function getConsecutiveBonusFormattedAttribute()
    {
        return CheckinLog::formatBytes($this->consecutive_bonus);
    }

    /**
     * 获取配置描述
     */
    public function getDescriptionAttribute()
    {
        $desc = "每日签到获得 {$this->daily_traffic_formatted}";
        
        if ($this->consecutive_bonus > 0) {
            $desc .= "，连续{$this->consecutive_days}天额外获得 {$this->consecutive_bonus_formatted}";
        }
        
        return $desc;
    }

    /**
     * 同步套餐名称
     */
    public function syncPlanName()
    {
        if ($this->plan_id) {
            $plan = Plan::find($this->plan_id);
            if ($plan) {
                $this->plan_name = $plan->name;
                $this->save();
            }
        }
    }

    /**
     * 批量同步所有套餐名称
     */
    public static function syncAllPlanNames()
    {
        $configs = self::whereNotNull('plan_id')->get();
        
        foreach ($configs as $config) {
            $config->syncPlanName();
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoSpeedlimitConfig extends Model
{
    protected $table = 'v2_auto_speedlimit_config';
    protected $dateFormat = 'U'; // 使用Unix时间戳格式，与V2Board保持一致

    protected $fillable = [
        'enable', 'limit_basis', 'traffic_mode', 'daily_calc_mode',
        'threshold_1', 'speed_1', 'threshold_2', 'speed_2',
        'threshold_3', 'speed_3', 'threshold_4', 'speed_4',
        'threshold_5', 'speed_5'
    ];

    protected $casts = [
        'enable' => 'boolean',
        'threshold_1' => 'decimal:2',
        'threshold_2' => 'decimal:2',
        'threshold_3' => 'decimal:2',
        'threshold_4' => 'decimal:2',
        'threshold_5' => 'decimal:2',
        'speed_1' => 'integer',
        'speed_2' => 'integer',
        'speed_3' => 'integer',
        'speed_4' => 'integer',
        'speed_5' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
    
    /**
     * 获取配置的限速等级数组
     */
    public function getSpeedLimitsArray()
    {
        $speedLimits = [];
        for ($i = 1; $i <= 5; $i++) {
            $threshold = $this->{"threshold_$i"};
            $speed = $this->{"speed_$i"};
            
            if ($threshold !== null && $speed !== null && $threshold > 0 && $speed > 0) {
                $speedLimits[] = [
                    'level' => $i,
                    'threshold' => (float)$threshold,
                    'speed' => (int)$speed
                ];
            }
        }
        
        // 按阈值排序（从小到大）
        usort($speedLimits, function($a, $b) {
            return $a['threshold'] <=> $b['threshold'];
        });
        
        return $speedLimits;
    }

    /**
     * 获取流量模式描述
     */
    public function getTrafficModeDescription()
    {
        $descriptions = [
            'daily' => '当日流量',
            'total' => '总流量',
            'both' => '双重检查（取最严格）'
        ];
        
        return $descriptions[$this->traffic_mode] ?? '未知';
    }

    /**
     * 获取限速依据描述
     */
    public function getLimitBasisDescription()
    {
        $descriptions = [
            'ratio' => '流量使用比例',
            'daily_fixed' => '当日固定流量'
        ];

        return $descriptions[$this->limit_basis ?: 'ratio'] ?? '未知';
    }

    /**
     * 获取阈值单位
     */
    public function getThresholdUnit()
    {
        return $this->limit_basis === 'daily_fixed' ? 'GB' : '%';
    }

    /**
     * 获取每日计算模式描述
     */
    public function getDailyCalcModeDescription()
    {
        $descriptions = [
            'total' => '基于总配额计算',
            'remaining' => '基于剩余流量计算'
        ];
        
        return $descriptions[$this->daily_calc_mode] ?? '未知';
    }

    /**
     * 验证配置的有效性
     */
    public function validateConfig()
    {
        $errors = [];
        $isDailyFixed = $this->limit_basis === 'daily_fixed';
        $maxThreshold = $isDailyFixed ? 999.99 : 100;
        $thresholdUnit = $isDailyFixed ? 'GB' : '%';
        
        // 检查是否至少有一个有效的阈值配置
        $hasValidThreshold = false;
        for ($i = 1; $i <= 5; $i++) {
            $threshold = $this->{"threshold_$i"};
            $speed = $this->{"speed_$i"};
            
            if ($threshold !== null && $speed !== null) {
                if ($threshold <= 0 || $threshold > $maxThreshold) {
                    $errors[] = "阈值{$i}必须大于0且不超过{$maxThreshold}{$thresholdUnit}";
                }
                if ($speed <= 0) {
                    $errors[] = "限速{$i}必须大于0";
                }
                $hasValidThreshold = true;
            }
        }
        
        if (!$hasValidThreshold) {
            $errors[] = "至少需要配置一个有效的阈值和限速";
        }
        
        return $errors;
    }

    /**
     * 获取配置摘要
     */
    public function getConfigSummary()
    {
        $speedLimits = $this->getSpeedLimitsArray();
        $summary = [
            'enabled' => $this->enable,
            'limit_basis' => $this->getLimitBasisDescription(),
            'traffic_mode' => $this->getTrafficModeDescription(),
            'daily_calc_mode' => $this->getDailyCalcModeDescription(),
            'threshold_unit' => $this->getThresholdUnit(),
            'levels_count' => count($speedLimits),
            'levels' => $speedLimits
        ];
        
        return $summary;
    }

    /**
     * 获取单例配置（系统只允许一个配置）
     */
    public static function getConfig()
    {
        $config = self::first();
        if (!$config) {
            // 创建默认配置
            $config = self::create([
                'enable' => false,
                'limit_basis' => 'ratio',
                'traffic_mode' => 'daily',
                'daily_calc_mode' => 'total',
                'threshold_1' => 80.00,
                'speed_1' => 50,
                'threshold_2' => 90.00,
                'speed_2' => 20,
                'threshold_3' => 95.00,
                'speed_3' => 10,
            ]);
        }
        
        return $config;
    }

    /**
     * 更新配置
     */
    public static function updateConfig($data)
    {
        $config = self::getConfig();
        $config->update($data);
        return $config;
    }
}

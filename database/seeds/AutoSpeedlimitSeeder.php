<?php

use Illuminate\Database\Seeder;
use App\Models\AutoSpeedlimitConfig;

class AutoSpeedlimitSeeder extends Seeder
{
    /**
     * 运行数据库种子
     */
    public function run()
    {
        // 清空现有数据
        AutoSpeedlimitConfig::truncate();

        // 创建默认配置
        AutoSpeedlimitConfig::create([
            'enable' => false, // 默认禁用
            'limit_basis' => 'ratio', // 默认按流量使用比例限速
            'traffic_mode' => 'daily', // 默认使用当日流量模式
            'daily_calc_mode' => 'total', // 默认基于总配额计算
            
            // 示例配置：5级阈值限速
            'threshold_1' => 70.00, // 70%时限速至100Mbps
            'speed_1' => 100,
            
            'threshold_2' => 80.00, // 80%时限速至50Mbps
            'speed_2' => 50,
            
            'threshold_3' => 90.00, // 90%时限速至20Mbps
            'speed_3' => 20,
            
            'threshold_4' => 95.00, // 95%时限速至10Mbps
            'speed_4' => 10,
            
            'threshold_5' => 98.00, // 98%时限速至5Mbps
            'speed_5' => 5,
        ]);

        echo "自动限速配置种子数据创建完成\n";
        echo "默认配置：\n";
        echo "- 状态：禁用（需要手动启用）\n";
        echo "- 流量模式：当日流量\n";
        echo "- 计算基准：基于总配额\n";
        echo "- 限速等级：5级（70%/80%/90%/95%/98%）\n";
        echo "- 对应限速：100/50/20/10/5 Mbps\n";
    }
}

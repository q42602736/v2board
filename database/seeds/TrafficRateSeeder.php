<?php

use Illuminate\Database\Seeder;
use App\Models\TrafficRateConfig;

class TrafficRateSeeder extends Seeder
{
    /**
     * 运行数据库种子
     */
    public function run()
    {
        // 清空现有数据
        TrafficRateConfig::truncate();

        // 创建示例配置
        $configs = [
            [
                'name' => '夜间流量优惠',
                'status' => 0, // 默认禁用
                'start_time' => '23:00:00',
                'end_time' => '06:00:00',
                'days_of_week' => '1,2,3,4,5,6,7',
                'target_rate' => 0.5,
                'node_filter' => 'all',
                'node_ids' => null,
                'backup_enabled' => true,
                'auto_restore' => true,
                'description' => '每日夜间时段（23:00-06:00）流量倍率减半，帮助用户节省流量消耗。适用于所有节点。',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => '工作日高峰期限制',
                'status' => 0, // 默认禁用
                'start_time' => '18:00:00',
                'end_time' => '22:00:00',
                'days_of_week' => '1,2,3,4,5', // 仅工作日
                'target_rate' => 2.0,
                'node_filter' => 'exclude',
                'node_ids' => [
                    ['server_type' => 'v2_server_vmess', 'server_id' => 1],
                    ['server_type' => 'v2_server_vmess', 'server_id' => 2],
                    ['server_type' => 'v2_server_trojan', 'server_id' => 1]
                ], // 排除核心节点
                'backup_enabled' => true,
                'auto_restore' => true,
                'description' => '工作日高峰期时段（18:00-22:00）增加流量倍率，缓解服务器压力。排除核心节点。',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => '周末全天特惠',
                'status' => 0, // 默认禁用
                'start_time' => '00:00:00',
                'end_time' => '23:59:59',
                'days_of_week' => '6,7', // 仅周末
                'target_rate' => 0.3,
                'node_filter' => 'include',
                'node_ids' => [
                    ['server_type' => 'v2_server_vmess', 'server_id' => 4],
                    ['server_type' => 'v2_server_vmess', 'server_id' => 5],
                    ['server_type' => 'v2_server_shadowsocks', 'server_id' => 1],
                    ['server_type' => 'v2_server_shadowsocks', 'server_id' => 2]
                ], // 指定特惠节点
                'backup_enabled' => true,
                'auto_restore' => true,
                'description' => '周末全天特惠活动，指定节点流量倍率降至0.3。仅适用于特定节点。',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => '维护期间限制',
                'status' => 0, // 默认禁用
                'start_time' => now()->addDays(7)->setTime(2, 0, 0),
                'end_time' => now()->addDays(7)->setTime(4, 0, 0),
                'target_rate' => 5.0,
                'node_filter' => 'all',
                'node_ids' => null,
                'backup_enabled' => true,
                'auto_restore' => true,
                'description' => '系统维护期间（02:00-04:00）大幅增加流量倍率，引导用户避开维护时段。',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => '新用户体验优惠',
                'status' => 0, // 默认禁用
                'start_time' => now()->setTime(12, 0, 0),
                'end_time' => now()->setTime(14, 0, 0),
                'target_rate' => 0.1,
                'node_filter' => 'include',
                'node_ids' => [
                    ['server_type' => 'v2_server_trojan', 'server_id' => 8],
                    ['server_type' => 'v2_server_vless', 'server_id' => 1]
                ], // 体验节点
                'backup_enabled' => true,
                'auto_restore' => true,
                'description' => '午间新用户体验时段（12:00-14:00），体验节点流量倍率极低。仅适用于体验节点8、9。',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ];

        foreach ($configs as $config) {
            TrafficRateConfig::create($config);
        }

        $this->command->info('流量倍率配置种子数据创建完成！');
        $this->command->info('已创建 ' . count($configs) . ' 个示例配置（默认为禁用状态）');
        $this->command->line('');
        $this->command->line('配置说明：');
        foreach ($configs as $index => $config) {
            $this->command->line(($index + 1) . '. ' . $config['name'] . ' - ' . $config['description']);
        }
        $this->command->line('');
        $this->command->warn('注意：所有示例配置默认为禁用状态，需要管理员在后台手动启用。');
    }
}

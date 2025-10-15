<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TrafficRateConfig;
use App\Services\TrafficRateService;

class TrafficRateCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'traffic-rate:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查并执行流量倍率配置';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $service = new TrafficRateService();

            // 检查需要开始执行的配置
            $configsToStart = TrafficRateConfig::getConfigsToStart();
            foreach ($configsToStart as $config) {
                $this->info("执行配置: {$config->name}");
                $result = $service->executeConfig($config, 'start');
                if ($result['success']) {
                    $this->info("✓ 配置 {$config->name} 执行成功，影响节点: {$result['affected_nodes']}");
                } else {
                    $this->error("✗ 配置 {$config->name} 执行失败: {$result['message']}");
                }
            }

            // 检查需要结束执行的配置
            $configsToEnd = TrafficRateConfig::getConfigsToEnd();
            if ($configsToEnd->count() > 0) {
                $this->info("找到 {$configsToEnd->count()} 个需要恢复的配置");
            }

            foreach ($configsToEnd as $config) {
                $this->info("恢复配置: {$config->name}");
                $result = $service->executeConfig($config, 'end');
                if ($result['success']) {
                    $this->info("✓ 配置 {$config->name} 恢复成功，影响节点: {$result['affected_nodes']}");
                } else {
                    $this->error("✗ 配置 {$config->name} 恢复失败: {$result['message']}");
                }
            }
            
        } catch (\Exception $e) {
            $this->error('流量倍率检查失败: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}

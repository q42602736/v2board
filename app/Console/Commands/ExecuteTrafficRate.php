<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TrafficRateService;
use Illuminate\Support\Facades\Log;

class ExecuteTrafficRate extends Command
{
    /**
     * 命令名称和签名
     */
    protected $signature = 'traffic-rate:execute 
                            {--force : 强制执行所有配置}
                            {--config= : 执行指定配置ID}
                            {--restore-all : 恢复所有未恢复的备份}';

    /**
     * 命令描述
     */
    protected $description = '执行流量倍率自动调整任务';

    /**
     * 流量倍率服务
     */
    protected $trafficRateService;

    /**
     * 创建命令实例
     */
    public function __construct(TrafficRateService $trafficRateService)
    {
        parent::__construct();
        $this->trafficRateService = $trafficRateService;
    }

    /**
     * 执行命令
     */
    public function handle()
    {
        $this->info('开始执行流量倍率任务...');
        
        try {
            // 检查是否需要恢复所有备份
            if ($this->option('restore-all')) {
                return $this->handleRestoreAll();
            }

            // 检查是否执行指定配置
            if ($configId = $this->option('config')) {
                return $this->handleSpecificConfig($configId);
            }

            // 执行常规定时任务
            $this->handleScheduledTasks();

        } catch (\Exception $e) {
            $this->error('执行失败: ' . $e->getMessage());
            Log::error('流量倍率命令执行失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }

    /**
     * 处理定时任务
     */
    protected function handleScheduledTasks()
    {
        $this->info('执行定时检查任务...');
        
        $this->trafficRateService->executeScheduledTasks();
        
        $this->info('定时任务执行完成');
    }

    /**
     * 处理指定配置
     */
    protected function handleSpecificConfig($configId)
    {
        $this->info("执行指定配置: {$configId}");
        
        $config = \App\Models\TrafficRateConfig::find($configId);
        
        if (!$config) {
            $this->error("配置 {$configId} 不存在");
            return 1;
        }

        if (!$config->status && !$this->option('force')) {
            $this->error("配置 {$configId} 未启用，使用 --force 参数强制执行");
            return 1;
        }

        // 询问执行类型
        $executionType = $this->choice(
            '选择执行类型',
            ['start' => '开始执行', 'end' => '结束恢复'],
            'start'
        );

        $result = $this->trafficRateService->executeConfig($config, $executionType);
        
        $this->info("配置执行成功，影响节点数: {$result['affected_nodes']}");
        
        return 0;
    }

    /**
     * 处理恢复所有备份
     */
    protected function handleRestoreAll()
    {
        $this->warn('即将恢复所有未恢复的备份，这将重置所有节点的流量倍率！');
        
        if (!$this->confirm('确定要继续吗？')) {
            $this->info('操作已取消');
            return 0;
        }

        $this->info('开始恢复所有备份...');
        
        $restoredCount = $this->trafficRateService->forceRestoreAllBackups();
        
        $this->info("恢复完成，共恢复 {$restoredCount} 个节点的倍率");
        
        return 0;
    }

    /**
     * 显示帮助信息
     */
    protected function showHelp()
    {
        $this->info('流量倍率管理命令使用说明：');
        $this->line('');
        $this->line('基本用法：');
        $this->line('  php artisan traffic-rate:execute                    # 执行定时任务');
        $this->line('  php artisan traffic-rate:execute --config=1         # 执行指定配置');
        $this->line('  php artisan traffic-rate:execute --restore-all      # 恢复所有备份');
        $this->line('  php artisan traffic-rate:execute --force            # 强制执行');
        $this->line('');
        $this->line('参数说明：');
        $this->line('  --force        强制执行，忽略配置状态检查');
        $this->line('  --config=ID    执行指定ID的配置');
        $this->line('  --restore-all  恢复所有未恢复的备份');
        $this->line('');
        $this->line('示例：');
        $this->line('  # 定时任务（通常由cron调用）');
        $this->line('  php artisan traffic-rate:execute');
        $this->line('');
        $this->line('  # 手动执行配置ID为1的配置');
        $this->line('  php artisan traffic-rate:execute --config=1');
        $this->line('');
        $this->line('  # 紧急恢复所有节点倍率');
        $this->line('  php artisan traffic-rate:execute --restore-all');
    }
}

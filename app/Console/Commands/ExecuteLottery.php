<?php

namespace App\Console\Commands;

use App\Models\LotteryConfig;
use App\Services\LotteryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExecuteLottery extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'lottery:execute {--config-id= : 指定配置ID，不指定则检查所有配置}';

    /**
     * The console command description.
     */
    protected $description = '执行抽奖任务';

    protected $lotteryService;

    public function __construct(LotteryService $lotteryService)
    {
        parent::__construct();
        $this->lotteryService = $lotteryService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $configId = $this->option('config-id');
        
        if ($configId) {
            // 执行指定配置的抽奖
            $this->executeSpecificLottery($configId);
        } else {
            // 检查所有可执行的配置
            $this->executeAllEligibleLotteries();
        }
    }

    /**
     * 执行指定配置的抽奖
     */
    private function executeSpecificLottery($configId)
    {
        try {
            $config = LotteryConfig::findOrFail($configId);
            $this->info("开始执行抽奖配置: {$config->name} (ID: {$configId})");
            
            $result = $this->lotteryService->executeLottery($configId);
            
            $this->info("抽奖执行成功!");
            $this->info("参与人数: {$result['participants']}");
            $this->info("中奖人数: {$result['actual_winners']}");
            $this->info("总奖励: {$result['total_reward']}");
            
            Log::info('定时任务执行抽奖成功', [
                'config_id' => $configId,
                'result' => $result
            ]);
            
        } catch (\Exception $e) {
            $this->error("执行抽奖失败: {$e->getMessage()}");
            Log::error('定时任务执行抽奖失败', [
                'config_id' => $configId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 执行所有符合条件的抽奖
     */
    private function executeAllEligibleLotteries()
    {
        $this->info('检查所有可执行的抽奖配置...');

        $executableConfigs = $this->lotteryService->checkExecutableConfigs();

        if ($executableConfigs->isEmpty()) {
            $this->info('当前没有可执行的抽奖配置');
            return;
        }
        
        $this->info("找到 {$executableConfigs->count()} 个可执行的配置");
        
        foreach ($executableConfigs as $config) {
            $this->info("执行配置: {$config->name} (ID: {$config->id})");
            
            try {
                $result = $this->lotteryService->executeLottery($config->id);
                
                $this->info("✓ 执行成功 - 参与: {$result['participants']}人, 中奖: {$result['actual_winners']}人");
                
            } catch (\Exception $e) {
                $this->error("✗ 执行失败: {$e->getMessage()}");
                Log::error('定时任务执行抽奖失败', [
                    'config_id' => $config->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->info('所有抽奖配置检查完成');
    }
}

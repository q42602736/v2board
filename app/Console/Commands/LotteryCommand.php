<?php

namespace App\Console\Commands;

use App\Services\LotteryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LotteryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lottery:execute {--config-id= : 指定配置ID执行抽奖}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '执行抽奖任务';

    protected $lotteryService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(LotteryService $lotteryService)
    {
        parent::__construct();
        $this->lotteryService = $lotteryService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $configId = $this->option('config-id');

        try {
            if ($configId) {
                // 执行指定配置的抽奖
                $this->executeSpecificLottery($configId);
            } else {
                // 检查并执行所有可执行的抽奖
                $this->executeAllLotteries();
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('抽奖执行失败: ' . $e->getMessage());
            Log::error('抽奖命令执行失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * 执行指定配置的抽奖
     */
    private function executeSpecificLottery($configId)
    {
        $this->info("开始执行配置ID {$configId} 的抽奖...");

        $result = $this->lotteryService->executeLottery($configId);

        if ($result['success']) {
            $this->info("抽奖执行成功!");
            $this->info("参与人数: {$result['participants']}");
            $this->info("中奖人数: {$result['winners']}");
            $this->info("总奖励: " . number_format($result['total_reward'] / 100, 2) . " 元");
            $this->info("轮次号: {$result['lottery_log']->round_number}");
        } else {
            $this->error("抽奖执行失败");
        }
    }

    /**
     * 执行所有可执行的抽奖
     */
    private function executeAllLotteries()
    {
        $this->info('检查可执行的抽奖配置...');

        $executableConfigs = $this->lotteryService->checkExecutableConfigs();

        if (empty($executableConfigs)) {
            $this->info('当前没有需要执行的抽奖');
            return;
        }

        $this->info('找到 ' . count($executableConfigs) . ' 个可执行的抽奖配置');

        $successCount = 0;
        $failCount = 0;

        foreach ($executableConfigs as $config) {
            try {
                $this->info("执行抽奖配置: {$config->name} (ID: {$config->id})");

                $result = $this->lotteryService->executeLottery($config->id);

                if ($result['success']) {
                    $successCount++;
                    $this->info("✓ 抽奖执行成功 - 参与: {$result['participants']}, 中奖: {$result['winners']}, 奖励: " . number_format($result['total_reward'] / 100, 2) . "元");
                } else {
                    $failCount++;
                    $this->error("✗ 抽奖执行失败");
                }
            } catch (\Exception $e) {
                $failCount++;
                $this->error("✗ 抽奖执行异常: " . $e->getMessage());
                Log::error('单个抽奖配置执行失败', [
                    'config_id' => $config->id,
                    'config_name' => $config->name,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info("抽奖执行完成 - 成功: {$successCount}, 失败: {$failCount}");
    }
}

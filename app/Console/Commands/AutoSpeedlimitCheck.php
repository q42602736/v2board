<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AutoSpeedlimitService;
use Illuminate\Support\Facades\Log;

class AutoSpeedlimitCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auto-speedlimit:check 
                            {--force : 强制执行，忽略配置状态}
                            {--user= : 检查指定用户ID}
                            {--restore= : 恢复指定用户ID的限速}
                            {--stats : 显示统计信息}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '执行自动限速检查任务';

    /**
     * 自动限速服务
     */
    protected $autoSpeedlimitService;

    /**
     * Create a new command instance.
     */
    public function __construct(AutoSpeedlimitService $autoSpeedlimitService)
    {
        parent::__construct();
        $this->autoSpeedlimitService = $autoSpeedlimitService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = microtime(true);
        
        try {
            // 显示统计信息
            if ($this->option('stats')) {
                $this->showStats();
                return 0;
            }
            
            // 恢复指定用户
            if ($this->option('restore')) {
                $this->restoreUser($this->option('restore'));
                return 0;
            }
            
            // 检查指定用户
            if ($this->option('user')) {
                $this->checkUser($this->option('user'));
                return 0;
            }
            
            // 执行完整的自动限速检查
            $this->info('==================== 自动限速检查任务开始 ====================');
            $this->info('执行时间: ' . date('Y-m-d H:i:s'));
            
            $result = $this->autoSpeedlimitService->executeAutoSpeedlimitCheck();
            
            if ($result['success']) {
                $stats = $result['stats'];
                $this->info("✓ 自动限速检查完成");
                $this->info("  - 检查用户数: {$stats['checked_users']}");
                $this->info("  - 新增限速用户: {$stats['limited_users']}");
                $this->info("  - 恢复限速用户: {$stats['restored_users']}");
                $this->info("  - 总操作数: {$stats['total_operations']}");
            } else {
                $this->error("✗ 自动限速检查失败: " . $result['message']);
                return 1;
            }
            
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("执行耗时: {$executionTime}ms");
            $this->info('==================== 自动限速检查任务结束 ====================');
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('自动限速检查任务执行失败: ' . $e->getMessage());
            Log::error('自动限速检查任务执行失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * 显示统计信息
     */
    private function showStats()
    {
        $this->info('==================== 自动限速系统统计 ====================');
        
        try {
            $stats = $this->autoSpeedlimitService->getSystemStats();
            
            $this->info('系统状态:');
            $this->info('  - 功能状态: ' . ($stats['config_enabled'] ? '启用' : '禁用'));
            $this->info('  - 当前被限速用户数: ' . $stats['current_limited_users']);
            
            if ($stats['config_summary']) {
                $summary = $stats['config_summary'];
                $this->info('  - 限速依据: ' . $summary['limit_basis']);
                $this->info('  - 流量计算模式: ' . $summary['traffic_mode']);
                $this->info('  - 每日计算基准: ' . $summary['daily_calc_mode']);
                $this->info('  - 配置等级数: ' . $summary['levels_count']);
                
                if (!empty($summary['levels'])) {
                    $this->info('  - 限速等级:');
                    foreach ($summary['levels'] as $level) {
                        $this->info("    等级{$level['level']}: {$level['threshold']}{$summary['threshold_unit']} → {$level['speed']}Mbps");
                    }
                }
            }
            
            $this->info('');
            $this->info('最近7天统计:');
            $recentStats = $stats['recent_stats'];
            $this->info('  - 限速操作: ' . $recentStats['limit_operations'] . ' 次');
            $this->info('  - 恢复操作: ' . $recentStats['restore_operations'] . ' 次');
            $this->info('  - 被限速用户: ' . $recentStats['limited_users'] . ' 人');
            $this->info('  - 恢复限速用户: ' . $recentStats['restored_users'] . ' 人');
            
        } catch (\Exception $e) {
            $this->error('获取统计信息失败: ' . $e->getMessage());
        }
        
        $this->info('========================================================');
    }

    /**
     * 检查指定用户
     */
    private function checkUser($userId)
    {
        $this->info("检查用户 ID: {$userId}");
        
        try {
            $result = $this->autoSpeedlimitService->checkSingleUser($userId);
            
            if ($result['success']) {
                $this->info("✓ " . $result['message']);
                $this->info("  - 当前状态: " . $result['user_status']);
                $this->info("  - 当前限速: " . ($result['speed_limit'] ?: '不限速'));
            } else {
                $this->error("✗ " . $result['message']);
            }
            
        } catch (\Exception $e) {
            $this->error('检查用户失败: ' . $e->getMessage());
        }
    }

    /**
     * 恢复指定用户
     */
    private function restoreUser($userId)
    {
        $this->info("恢复用户 ID: {$userId}");
        
        try {
            $result = $this->autoSpeedlimitService->restoreSingleUser($userId);
            
            if ($result['success']) {
                $this->info("✓ " . $result['message']);
                $this->info("  - 恢复后限速: " . ($result['speed_limit'] ?: '不限速'));
            } else {
                $this->error("✗ " . $result['message']);
            }
            
        } catch (\Exception $e) {
            $this->error('恢复用户失败: ' . $e->getMessage());
        }
    }

    /**
     * 显示帮助信息
     */
    public function showHelp()
    {
        $this->info('自动限速管理命令使用说明：');
        $this->line('');
        $this->line('基本用法：');
        $this->line('  php artisan auto-speedlimit:check                    # 执行定时检查');
        $this->line('  php artisan auto-speedlimit:check --stats            # 显示统计信息');
        $this->line('  php artisan auto-speedlimit:check --user=123         # 检查指定用户');
        $this->line('  php artisan auto-speedlimit:check --restore=123      # 恢复指定用户');
        $this->line('  php artisan auto-speedlimit:check --force            # 强制执行');
        $this->line('');
        $this->line('参数说明：');
        $this->line('  --force        强制执行，忽略配置状态检查');
        $this->line('  --user=ID      检查指定ID的用户');
        $this->line('  --restore=ID   恢复指定ID用户的限速');
        $this->line('  --stats        显示系统统计信息');
        $this->line('');
        $this->line('示例：');
        $this->line('  # 定时任务（通常由cron调用）');
        $this->line('  php artisan auto-speedlimit:check');
        $this->line('');
        $this->line('  # 查看系统状态');
        $this->line('  php artisan auto-speedlimit:check --stats');
        $this->line('');
        $this->line('  # 手动检查用户ID为123的用户');
        $this->line('  php artisan auto-speedlimit:check --user=123');
        $this->line('');
        $this->line('  # 手动恢复用户ID为123的限速');
        $this->line('  php artisan auto-speedlimit:check --restore=123');
    }
}

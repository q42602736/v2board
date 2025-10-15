<?php

namespace App\Services;

use App\Models\User;
use App\Models\AutoSpeedlimitConfig;
use App\Models\AutoSpeedlimitLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AutoSpeedlimitService
{
    /**
     * 执行自动限速检查
     */
    public function executeAutoSpeedlimitCheck()
    {
        Log::info('开始执行自动限速检查');
        
        try {
            // 获取配置
            $config = AutoSpeedlimitConfig::getConfig();
            if (!$config || !$config->enable) {
                Log::info('自动限速功能未启用');
                return [
                    'success' => true,
                    'message' => '自动限速功能未启用',
                    'stats' => ['checked_users' => 0, 'limited_users' => 0, 'restored_users' => 0]
                ];
            }
            
            // 先执行恢复检查
            $restoreStats = $this->checkAndRestoreSpeedLimit($config);
            
            // 执行限速检查
            $limitStats = $this->checkAndApplySpeedLimit($config);
            
            $totalStats = [
                'checked_users' => $limitStats['checked_users'],
                'limited_users' => $limitStats['limited_users'],
                'restored_users' => $restoreStats['restored_users'],
                'total_operations' => $limitStats['limited_users'] + $restoreStats['restored_users']
            ];
            
            Log::info('自动限速检查完成', $totalStats);
            
            return [
                'success' => true,
                'message' => '自动限速检查完成',
                'stats' => $totalStats
            ];
            
        } catch (\Exception $e) {
            Log::error('自动限速检查失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => '自动限速检查失败: ' . $e->getMessage(),
                'stats' => ['checked_users' => 0, 'limited_users' => 0, 'restored_users' => 0]
            ];
        }
    }
    
    /**
     * 检查并应用限速
     */
    private function checkAndApplySpeedLimit($config)
    {
        $speedLimits = $config->getSpeedLimitsArray();
        if (empty($speedLimits)) {
            Log::warning('没有有效的限速配置');
            return ['checked_users' => 0, 'limited_users' => 0];
        }
        
        // 获取所有启用的用户（排除被封禁的用户）
        $users = User::where('banned', 0)
            ->whereNotNull('transfer_enable')
            ->where('transfer_enable', '>', 0)
            ->get();
        
        $checkedUsers = $users->count();
        $limitedUsers = 0;
        
        Log::info("开始检查 {$checkedUsers} 个用户的流量使用情况");
        
        foreach ($users as $user) {
            if ($this->processUserSpeedLimit($user, $config, $speedLimits)) {
                $limitedUsers++;
            }
        }
        
        Log::info("限速检查完成，共检查 {$checkedUsers} 个用户，新增限速 {$limitedUsers} 个用户");
        
        return [
            'checked_users' => $checkedUsers,
            'limited_users' => $limitedUsers
        ];
    }
    
    /**
     * 处理单个用户的限速
     */
    private function processUserSpeedLimit($user, $config, $speedLimits)
    {
        try {
            // 计算流量使用百分比
            $dailyPercent = 0;
            $totalPercent = 0;
            
            if ($config->traffic_mode === 'daily' || $config->traffic_mode === 'both') {
                if ($config->daily_calc_mode === 'remaining') {
                    try {
                        $dailyPercent = $this->getTodayUsedTrafficPercentOfRemainingNew($user);
                    } catch (\Exception $e) {
                        $dailyPercent = 0; // 异常时不限速
                    }
                } else {
                    try {
                        $dailyPercent = $this->getTodayUsedTrafficPercentNew($user);
                    } catch (\Exception $e) {
                        $dailyPercent = 0; // 异常时不限速
                    }
                }
            }
            
            if ($config->traffic_mode === 'total' || $config->traffic_mode === 'both') {
                $totalPercent = $user->getTrafficUsagePercent();
            }
            
            // 计算应该应用的限速
            $result = $this->calculateSpeedLimit($dailyPercent, $totalPercent, $config->traffic_mode, $speedLimits);
            
            $newStatus = $result['level'];
            $newSpeedLimit = $result['speed'];
            $triggerInfo = $result['trigger_info'];
            
            // 如果状态发生变化，更新限速
            if ($newStatus != $user->auto_speedlimit_status) {
                $this->updateUserSpeedLimit($user, $newStatus, $newSpeedLimit, $triggerInfo, $dailyPercent, $totalPercent);
                return true; // 表示用户状态发生了变化
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error("处理用户 {$user->id} 限速时出错", [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * 计算应该应用的限速等级
     */
    private function calculateSpeedLimit($dailyPercent, $totalPercent, $trafficMode, $speedLimits)
    {
        $triggeredLevel = 0;
        $triggeredSpeed = 0;
        $triggerInfo = '';
        
        foreach ($speedLimits as $limit) {
            $shouldTrigger = false;
            
            switch ($trafficMode) {
                case 'daily':
                    if ($dailyPercent >= $limit['threshold']) {
                        $shouldTrigger = true;
                        $triggerInfo = "当日流量{$dailyPercent}%≥{$limit['threshold']}%";
                    }
                    break;
                case 'total':
                    if ($totalPercent >= $limit['threshold']) {
                        $shouldTrigger = true;
                        $triggerInfo = "总流量{$totalPercent}%≥{$limit['threshold']}%";
                    }
                    break;
                case 'both':
                    if ($dailyPercent >= $limit['threshold'] || $totalPercent >= $limit['threshold']) {
                        $shouldTrigger = true;
                        $triggerInfo = "当日{$dailyPercent}%或总计{$totalPercent}%≥{$limit['threshold']}%";
                    }
                    break;
            }
            
            if ($shouldTrigger) {
                $triggeredLevel = $limit['level'];
                $triggeredSpeed = $limit['speed'];
                // 继续检查更高等级的限速，取最严格的限制
            }
        }
        
        return [
            'level' => $triggeredLevel,
            'speed' => $triggeredSpeed,
            'trigger_info' => $triggerInfo,
        ];
    }
    
    /**
     * 更新用户限速
     */
    private function updateUserSpeedLimit($user, $newStatus, $newSpeedLimit, $triggerInfo, $dailyPercent, $totalPercent)
    {
        $oldStatus = $user->auto_speedlimit_status;
        $oldSpeedLimit = $user->speed_limit;
        
        DB::transaction(function () use ($user, $newStatus, $newSpeedLimit, $triggerInfo, $dailyPercent, $totalPercent, $oldStatus, $oldSpeedLimit) {
            if ($newStatus > 0) {
                // 需要限速
                if ($oldStatus == 0) {
                    // 首次限速，保存原始限速值
                    $user->original_speedlimit = $user->speed_limit;
                }
                $user->speed_limit = $newSpeedLimit;
            } else {
                // 恢复正常（这种情况在这个方法中不应该发生，但为了安全起见保留）
                $user->speed_limit = $user->original_speedlimit ?: null;
                $user->original_speedlimit = null;
            }
            
            $user->auto_speedlimit_status = $newStatus;
            $user->save();
            
            // 记录日志
            AutoSpeedlimitLog::createLimitLog(
                $user->id,
                $oldStatus,
                $newStatus,
                $oldSpeedLimit,
                $user->speed_limit,
                $triggerInfo,
                $dailyPercent,
                $totalPercent
            );
        });
        
        Log::info("用户限速状态变更", [
            'user_id' => $user->id,
            'email' => $user->email,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'speed_limit' => $user->speed_limit,
            'trigger_info' => $triggerInfo
        ]);
    }
    
    /**
     * 检查并恢复限速
     */
    private function checkAndRestoreSpeedLimit($config)
    {
        // 获取所有被限速的用户
        $limitedUsers = User::where('auto_speedlimit_status', '>', 0)->get();
        
        if ($limitedUsers->count() == 0) {
            return ['restored_users' => 0];
        }
        
        Log::info("检查需要恢复限速的用户数: {$limitedUsers->count()}");
        
        $restoredUsers = 0;
        
        foreach ($limitedUsers as $user) {
            $shouldRestore = false;
            $restoreReason = '';
            
            // 根据流量模式判断是否需要恢复
            if ($config->traffic_mode === 'daily') {
                // 当日流量模式：每天0点恢复
                if ($this->isDailyReset()) {
                    $shouldRestore = true;
                    $restoreReason = '当日流量重置';
                }
            } elseif ($config->traffic_mode === 'total') {
                // 总流量模式：用户流量重置日恢复
                if ($this->isUserTrafficReset($user)) {
                    $shouldRestore = true;
                    $restoreReason = '用户流量重置';
                }
            } elseif ($config->traffic_mode === 'both') {
                // 双重模式：任一条件满足即恢复
                if ($this->isDailyReset()) {
                    $shouldRestore = true;
                    $restoreReason = '当日流量重置';
                } elseif ($this->isUserTrafficReset($user)) {
                    $shouldRestore = true;
                    $restoreReason = '用户流量重置';
                }
            }
            
            if ($shouldRestore) {
                $this->restoreUserSpeedLimit($user, $restoreReason);
                $restoredUsers++;
            }
        }
        
        if ($restoredUsers > 0) {
            Log::info("恢复限速完成，共恢复 {$restoredUsers} 个用户");
        }
        
        return ['restored_users' => $restoredUsers];
    }
    
    /**
     * 恢复用户限速
     */
    private function restoreUserSpeedLimit($user, $reason)
    {
        $oldStatus = $user->auto_speedlimit_status;
        $oldSpeedLimit = $user->speed_limit;
        
        DB::transaction(function () use ($user, $reason, $oldStatus, $oldSpeedLimit) {
            // 恢复原始限速值
            $user->speed_limit = $user->original_speedlimit;
            $user->original_speedlimit = null;
            $user->auto_speedlimit_status = 0;
            $user->save();
            
            // 记录日志
            AutoSpeedlimitLog::createRestoreLog(
                $user->id,
                $oldStatus,
                0,
                $oldSpeedLimit,
                $user->speed_limit,
                $reason
            );
        });
        
        Log::info("用户限速已恢复", [
            'user_id' => $user->id,
            'email' => $user->email,
            'reason' => $reason,
            'restored_speed' => $user->speed_limit
        ]);
    }
    
    /**
     * 检查是否为每日重置时间
     */
    private function isDailyReset()
    {
        // 检查是否刚过0点（在0点后的前5分钟内）
        $now = now();
        return $now->hour == 0 && $now->minute < 5;
    }
    
    /**
     * 检查用户是否到了流量重置时间
     */
    private function isUserTrafficReset($user)
    {
        // 这里需要根据V2Board的流量重置逻辑来实现
        // 暂时返回false，后续根据实际需求调整
        // 可以检查用户的套餐重置日期等
        return false;
    }
    
    /**
     * 手动执行用户限速检查（用于测试）
     */
    public function checkSingleUser($userId)
    {
        $user = User::find($userId);
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }
        
        $config = AutoSpeedlimitConfig::getConfig();
        if (!$config) {
            return ['success' => false, 'message' => '配置不存在'];
        }
        
        $speedLimits = $config->getSpeedLimitsArray();
        if (empty($speedLimits)) {
            return ['success' => false, 'message' => '没有有效的限速配置'];
        }
        
        $changed = $this->processUserSpeedLimit($user, $config, $speedLimits);
        
        return [
            'success' => true,
            'message' => $changed ? '用户状态已更新' : '用户状态无变化',
            'user_status' => $user->auto_speedlimit_status,
            'speed_limit' => $user->speed_limit
        ];
    }
    
    /**
     * 手动恢复用户限速
     */
    public function restoreSingleUser($userId)
    {
        $user = User::find($userId);
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }
        
        if ($user->auto_speedlimit_status == 0) {
            return ['success' => false, 'message' => '用户未被限速'];
        }
        
        $this->restoreUserSpeedLimit($user, '手动恢复');
        
        return [
            'success' => true,
            'message' => '用户限速已恢复',
            'speed_limit' => $user->speed_limit
        ];
    }
    
    /**
     * 获取系统统计信息
     */
    public function getSystemStats()
    {
        $config = AutoSpeedlimitConfig::getConfig();
        $currentLimitedUsers = User::where('auto_speedlimit_status', '>', 0)->count();
        $recentStats = AutoSpeedlimitLog::getRecentStats(7);
        
        return [
            'config_enabled' => $config ? $config->enable : false,
            'current_limited_users' => $currentLimitedUsers,
            'recent_stats' => $recentStats,
            'config_summary' => $config ? $config->getConfigSummary() : null
        ];
    }

    /**
     * 使用管理员后台相同的逻辑计算用户今日流量
     * 基于 v2_stat_user 表的实际统计数据
     */
    private function getUserTodayTrafficFromStatTable($userId)
    {
        try {
            $startAt = strtotime(date('Y-m-d')); // 今天00:00
            $endAt = time(); // 当前时间

            // 使用与管理员后台相同的查询逻辑
            $statistics = DB::table('v2_stat_user')
                ->select([
                    'user_id',
                    'server_rate',
                    'u',
                    'd',
                    DB::raw('(u+d) as total')
                ])
                ->where('user_id', $userId)
                ->where('record_at', '>=', $startAt)
                ->where('record_at', '<', $endAt)
                ->where('record_type', 'd')
                ->get();

            $totalTraffic = 0;
            foreach ($statistics as $stat) {
                // 计算扣费流量：原始流量 * 服务器倍率
                $totalTraffic += ($stat->total * $stat->server_rate);
            }

            return $totalTraffic;

        } catch (\Exception $e) {
            Log::error('计算用户今日流量失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * 使用管理员后台相同逻辑计算今日流量百分比（normal模式）
     */
    private function getTodayUsedTrafficPercentNew($user)
    {
        try {
            if ($user->transfer_enable <= 0) {
                return 0;
            }

            $todayTraffic = $this->getUserTodayTrafficFromStatTable($user->id);
            $todayPercent = ($todayTraffic / $user->transfer_enable) * 100;

            return round($todayPercent, 2);
        } catch (\Exception $e) {
            Log::error('新方法计算失败', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 0; // 异常时返回0，不触发限速
        }
    }

    /**
     * 使用管理员后台相同逻辑计算今日流量百分比（remaining模式）
     */
    private function getTodayUsedTrafficPercentOfRemainingNew($user)
    {
        try {
            if ($user->transfer_enable <= 0) {
                return 0;
            }

            // 使用基于 v2_stat_user 表的准确今日流量计算
            $todayTraffic = $this->getUserTodayTrafficFromStatTable($user->id);

            // remaining模式：计算昨日结束时的剩余流量
            $totalUsed = $user->u + $user->d; // 当前总已用流量
            $yesterdayUsed = $totalUsed - $todayTraffic; // 昨日使用流量（近似）
            $yesterdayRemaining = $user->transfer_enable - $yesterdayUsed; // 昨日剩余流量

            // 特殊情况处理
            if ($yesterdayRemaining <= 0) {
                return $todayTraffic > 0 ? 100 : 100;
            }

            // 正常情况：计算今日流量占昨日剩余流量的百分比
            $todayPercent = ($todayTraffic / $yesterdayRemaining) * 100;

            // 如果今日使用超过了昨日剩余流量，返回100%
            $finalPercent = min(100, max(0, round($todayPercent, 2)));

            return $finalPercent;
        } catch (\Exception $e) {
            Log::error('新方法计算失败', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 0; // 异常时返回0，不触发限速
        }
    }
}

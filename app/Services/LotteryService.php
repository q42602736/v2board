<?php

namespace App\Services;

use App\Models\LotteryConfig;
use App\Models\LotteryLog;
use App\Models\LotteryWinner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LotteryService
{
    /**
     * 执行抽奖
     */
    public function executeLottery($configId)
    {
        return DB::transaction(function () use ($configId) {
            // 获取配置
            $config = LotteryConfig::findOrFail($configId);

            // 检查配置是否可执行
            if (!$this->canExecuteLottery($config)) {
                throw new \Exception('当前配置不可执行抽奖');
            }

            // 获取符合条件的用户
            $eligibleUsers = $this->getEligibleUsers($config);

            if ($eligibleUsers->count() == 0) {
                throw new \Exception('没有符合条件的用户，无法执行抽奖');
            }

            // 实际中奖人数为配置人数和符合条件用户数的较小值
            $actualWinnerCount = min($config->winner_count, $eligibleUsers->count());

            // 创建抽奖记录
            $lotteryLog = $this->createLotteryLog($config, $eligibleUsers->count());

            try {
                // 随机选择中奖用户（使用实际中奖人数）
                $winners = $this->selectWinners($eligibleUsers, $actualWinnerCount);

                // 发放奖励并记录中奖信息
                $winnerRecords = [];
                foreach ($winners as $winner) {
                    $winnerRecord = $this->awardUser($winner, $config, $lotteryLog);
                    $winnerRecords[] = $winnerRecord;
                }

                // 更新抽奖记录为成功
                $lotteryLog->update([
                    'status' => 'success',
                    'winner_count' => count($winnerRecords),
                    'total_reward' => collect($winnerRecords)->sum('reward_amount'),
                    'executed_at' => now()
                ]);

                Log::info('抽奖执行成功', [
                    'config_id' => $configId,
                    'config_name' => $config->name,
                    'log_id' => $lotteryLog->id,
                    'participants' => $eligibleUsers->count(),
                    'configured_winners' => $config->winner_count,
                    'actual_winners' => count($winnerRecords),
                    'round_number' => $lotteryLog->round_number
                ]);

                // 发送中奖消息到Telegram群
                if (count($winnerRecords) > 0) {
                    $this->sendLotteryNotificationToTelegram($config, $winners, $lotteryLog);
                }

                return [
                    'config_id' => $configId,
                    'config_name' => $config->name,
                    'log_id' => $lotteryLog->id,
                    'round_number' => $lotteryLog->round_number,
                    'executed_at' => $lotteryLog->executed_at,
                    'status' => 'success',
                    'participants' => $eligibleUsers->count(),
                    'configured_winners' => $config->winner_count,
                    'actual_winners' => count($winnerRecords),
                    'total_reward' => collect($winnerRecords)->sum('reward_amount'),
                    'reward_type' => $config->reward_type,
                    'message' => $eligibleUsers->count() < $config->winner_count ?
                        "符合条件用户({$eligibleUsers->count()}人)少于配置中奖人数({$config->winner_count}人)，实际中奖{$actualWinnerCount}人" :
                        "抽奖执行成功，{$actualWinnerCount}人中奖",
                    'winner_list' => collect($winnerRecords)->map(function($record) {
                        return [
                            'user_id' => $record->user_id,
                            'email' => $record->user->email ?? 'unknown',
                            'reward_amount' => $record->reward_amount,
                            'reward_type' => $record->reward_type,
                            'reward_formatted' => $record->reward_type === 'balance' ?
                                number_format($record->reward_amount / 100, 2) . '元' :
                                number_format($record->reward_amount / (1024 * 1024), 0) . 'MB'
                        ];
                    })
                ];

            } catch (\Exception $e) {
                // 更新抽奖记录为失败
                $lotteryLog->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'executed_at' => now()
                ]);

                throw $e;
            }
        });
    }

    /**
     * 检查配置是否可以执行抽奖
     */
    private function canExecuteLottery(LotteryConfig $config)
    {
        // 检查配置是否启用
        if (!$config->status) {
            return false;
        }

        // 检查今日执行次数
        $todayCount = LotteryLog::where('config_id', $config->id)
            ->whereDate('executed_at', today())
            ->where('status', 'success')
            ->count();

        if ($todayCount >= $config->frequency) {
            return false;
        }

        // 检查是否到了执行时间
        return $this->isTimeToExecute($config, $todayCount);
    }

    /**
     * 检查是否到了执行时间
     */
    private function isTimeToExecute(LotteryConfig $config, $todayCount)
    {
        // 计算时间间隔（分钟）
        $intervalMinutes = (24 * 60) / $config->frequency; // 24小时转分钟，然后除以频次

        Log::info('时间间隔计算', [
            'config_id' => $config->id,
            'frequency' => $config->frequency,
            'interval_minutes' => $intervalMinutes,
            'interval_hours' => $intervalMinutes / 60
        ]);

        // 解析开始时间
        $startTime = $config->start_time; // 格式可能是：HH:mm 或 HH:mm:ss
        $timeParts = explode(':', $startTime);
        $startHour = (int)$timeParts[0];
        $startMinute = (int)$timeParts[1];

        Log::info('开始时间解析', [
            'config_id' => $config->id,
            'raw_start_time' => $startTime,
            'parsed_hour' => $startHour,
            'parsed_minute' => $startMinute
        ]);

        // 计算今天的执行时间点
        $executionTimes = [];
        for ($i = 0; $i < $config->frequency; $i++) {
            $totalMinutes = ($startHour * 60 + $startMinute) + ($i * $intervalMinutes);

            // 处理跨天情况
            $totalMinutes = $totalMinutes % (24 * 60);

            $hour = floor($totalMinutes / 60);
            $minute = $totalMinutes % 60;

            $executionTimes[] = sprintf('%02d:%02d', $hour, $minute);
        }

        // 获取当前时间
        $currentTime = now()->format('H:i');

        // 获取今日已执行的时间点
        $executedTimes = LotteryLog::where('config_id', $config->id)
            ->whereDate('executed_at', today())
            ->where('status', 'success')
            ->orderBy('executed_at', 'asc')
            ->pluck('executed_at')
            ->map(function($datetime) {
                return $datetime->format('H:i');
            })
            ->toArray();

        Log::info('已执行时间点', [
            'config_id' => $config->id,
            'executed_times' => $executedTimes,
            'today_count' => $todayCount
        ]);

        // 检查当前时间是否匹配任何未执行的时间点
        foreach ($executionTimes as $index => $executionTime) {
            // 使用更可靠的时间设置方法
            $timeParts = explode(':', $executionTime);
            $executionDateTime = today()->setTime((int)$timeParts[0], (int)$timeParts[1], 0);
            $currentDateTime = now();

            // 检查是否在执行时间的1分钟内（更精确的时间窗口）
            $diffMinutes = abs($currentDateTime->diffInMinutes($executionDateTime));

            // 检查这个时间槽是否已经执行过（基于执行次数而不是时间匹配）
            $alreadyExecuted = ($index < $todayCount);

            Log::info('时间检查详情', [
                'config_id' => $config->id,
                'execution_time' => $executionTime,
                'current_time' => $currentTime,
                'execution_datetime' => $executionDateTime->format('Y-m-d H:i:s'),
                'current_datetime' => $currentDateTime->format('Y-m-d H:i:s'),
                'diff_minutes' => $diffMinutes,
                'execution_index' => $index,
                'today_count' => $todayCount,
                'is_within_window' => $diffMinutes <= 1,
                'already_executed' => $alreadyExecuted
            ]);

            // 只有在时间窗口内且未执行过的时间点才能执行
            if ($diffMinutes <= 1 && !$alreadyExecuted) {
                Log::info('到达执行时间', [
                    'config_id' => $config->id,
                    'execution_time' => $executionTime,
                    'current_time' => $currentTime,
                    'diff_minutes' => $diffMinutes,
                    'execution_index' => $index
                ]);
                return true;
            }
        }

        Log::info('未到执行时间', [
            'config_id' => $config->id,
            'current_time' => $currentTime,
            'execution_times' => $executionTimes,
            'executed_times' => $executedTimes,
            'today_count' => $todayCount
        ]);

        return false;
    }



    /**
     * 获取符合条件的用户
     */
    private function getEligibleUsers(LotteryConfig $config)
    {
        $totalUsers = User::where('banned', 0)->count();
        $query = User::where('banned', 0); // 排除被封禁的用户

        Log::info('开始筛选符合条件的用户', [
            'config_id' => $config->id,
            'total_active_users' => $totalUsers,
            'cooldown_rounds' => $config->cooldown_rounds
        ]);

        // 1. 检查有效套餐：plan_id != 0
        $beforeCount = $query->count();
        $query->where('plan_id', '!=', 0);
        $afterCount = $query->count();
        Log::info('有效套餐筛选', [
            'before' => $beforeCount,
            'after' => $afterCount,
            'excluded_no_plan' => $beforeCount - $afterCount
        ]);

        // 2. 检查当日流量消耗：今天有使用流量
        $beforeCount = $query->count();
        $todayStart = today()->timestamp;
        $todayEnd = today()->addDay()->timestamp;

        // 使用子查询检查当日流量消耗
        $query->whereExists(function ($subQuery) use ($todayStart, $todayEnd) {
            $subQuery->selectRaw('1')
                ->from('v2_stat_user')
                ->whereColumn('v2_stat_user.user_id', 'v2_user.id')
                ->where('record_at', '>=', $todayStart)
                ->where('record_at', '<', $todayEnd)
                ->whereRaw('(u + d) > 0'); // 有实际流量消耗
        });

        $afterCount = $query->count();
        Log::info('当日流量消耗筛选', [
            'before' => $beforeCount,
            'after' => $afterCount,
            'excluded_no_traffic' => $beforeCount - $afterCount,
            'today_start' => date('Y-m-d H:i:s', $todayStart),
            'today_end' => date('Y-m-d H:i:s', $todayEnd)
        ]);

        // 冷却期检查：排除在最近N轮中奖过的用户
        if ($config->cooldown_rounds > 0) {
            // 获取该配置最近N轮的抽奖记录
            $recentLotteryLogs = LotteryLog::where('config_id', $config->id)
                ->where('status', 'success')
                ->orderBy('executed_at', 'desc')
                ->limit($config->cooldown_rounds)
                ->pluck('id')
                ->toArray();

            if (!empty($recentLotteryLogs)) {
                // 获取这些轮次中的中奖用户
                $recentWinners = LotteryWinner::whereIn('lottery_log_id', $recentLotteryLogs)
                    ->pluck('user_id')
                    ->unique()
                    ->toArray();

                if (!empty($recentWinners)) {
                    $beforeCount = $query->count();
                    $query->whereNotIn('id', $recentWinners);
                    $afterCount = $query->count();
                    Log::info('冷却期筛选（按轮次）', [
                        'before' => $beforeCount,
                        'after' => $afterCount,
                        'excluded_users' => count($recentWinners),
                        'cooldown_rounds' => $config->cooldown_rounds,
                        'recent_lottery_logs' => $recentLotteryLogs,
                        'excluded_user_ids' => $recentWinners
                    ]);
                }
            } else {
                Log::info('冷却期检查：没有找到最近的抽奖记录', [
                    'config_id' => $config->id,
                    'cooldown_rounds' => $config->cooldown_rounds
                ]);
            }
        }

        $eligibleUsers = $query->get();
        Log::info('用户筛选完成', [
            'eligible_count' => $eligibleUsers->count(),
            'required_winners' => $config->winner_count
        ]);

        return $eligibleUsers;
    }

    /**
     * 随机选择中奖用户
     */
    private function selectWinners($eligibleUsers, $winnerCount)
    {
        return $eligibleUsers->random($winnerCount);
    }

    /**
     * 创建抽奖记录
     */
    private function createLotteryLog(LotteryConfig $config, $participantCount)
    {
        return LotteryLog::create([
            'config_id' => $config->id,
            'round_number' => $this->generateRoundNumber(),
            'status' => 'processing',
            'total_participants' => $participantCount,
            'winner_count' => 0,
            'total_reward' => 0,
            'executed_at' => now(),
            'created_at' => now()
        ]);
    }

    /**
     * 给用户发放奖励
     */
    private function awardUser(User $user, LotteryConfig $config, LotteryLog $lotteryLog)
    {
        // 发放奖励
        if ($config->reward_type === 'balance') {
            // 余额奖励
            $user->increment('balance', $config->reward_amount);
        } else {
            // 流量奖励
            $user->increment('transfer_enable', $config->reward_amount);
        }

        // 记录中奖信息
        return LotteryWinner::create([
            'lottery_log_id' => $lotteryLog->id,
            'user_id' => $user->id,
            'reward_type' => $config->reward_type,
            'reward_amount' => $config->reward_amount,
            'round_number' => $lotteryLog->round_number,
            'created_at' => now()
        ]);
    }

    /**
     * 生成抽奖轮次号
     */
    private function generateRoundNumber()
    {
        return date('Ymd') . str_pad(
            LotteryLog::whereDate('created_at', today())->count() + 1,
            3,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * 获取统计数据
     */
    public function getStatistics($configId = null)
    {
        $query = LotteryLog::query();
        if ($configId) {
            $query->where('config_id', $configId);
        }

        $totalLotteries = $query->count();
        $successLotteries = $query->where('status', 'success')->count();
        $totalParticipants = $query->sum('total_participants');
        $totalWinners = $query->sum('winner_count');
        $totalRewards = $query->sum('total_reward');

        return [
            'total_lotteries' => $totalLotteries,
            'success_lotteries' => $successLotteries,
            'success_rate' => $totalLotteries > 0 ? round($successLotteries / $totalLotteries * 100, 2) : 0,
            'total_participants' => $totalParticipants,
            'total_winners' => $totalWinners,
            'total_rewards' => $totalRewards,
            'total_rewards_formatted' => number_format($totalRewards / 100, 2),
            'avg_participants' => $totalLotteries > 0 ? round($totalParticipants / $totalLotteries, 1) : 0,
            'avg_winners' => $totalLotteries > 0 ? round($totalWinners / $totalLotteries, 1) : 0
        ];
    }

    /**
     * 检查可执行的配置
     */
    public function checkExecutableConfigs()
    {
        return LotteryConfig::where('status', 1)
            ->get()
            ->filter(function ($config) {
                return $this->canExecuteLottery($config);
            })
            ->values();
    }

    /**
     * 发送中奖消息到Telegram群
     */
    private function sendLotteryNotificationToTelegram($config, $winners, $lotteryLog)
    {
        // 检查配置是否启用了Telegram通知
        if (!$config->telegram_enabled) {
            Log::info('此抽奖配置未启用Telegram通知，跳过群发消息', [
                'config_id' => $config->id
            ]);
            return;
        }

        $botToken = $config->telegram_bot_token;
        $chatId = $config->telegram_chat_id;

        if (!$botToken || !$chatId) {
            Log::warning('Telegram配置不完整，跳过群发消息', [
                'config_id' => $config->id,
                'bot_token_exists' => !empty($botToken),
                'chat_id_exists' => !empty($chatId)
            ]);
            return;
        }

        try {
            // 格式化中奖消息
            $message = $this->formatLotteryMessage($config, $winners, $lotteryLog);

            // 发送消息到Telegram群
            $this->sendTelegramMessage($botToken, $chatId, $message);

            Log::info('抽奖中奖消息已发送到Telegram群', [
                'config_id' => $config->id,
                'winners_count' => count($winners),
                'chat_id' => $chatId
            ]);

        } catch (\Exception $e) {
            Log::error('发送Telegram抽奖消息失败', [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 格式化抽奖中奖消息
     */
    private function formatLotteryMessage($config, $winners, $lotteryLog)
    {
        $appName = config('v2board.app_name', 'V2Board');
        $rewardText = $config->reward_type === 'balance'
            ? number_format($config->reward_amount / 100, 2) . '元余额'
            : $this->formatTraffic($config->reward_amount);

        $message = "🎉 {$appName} 抽奖中奖通知\n\n";
        $message .= "📋 活动名称：{$config->name}\n";
        $message .= "🎁 奖励内容：{$rewardText}\n";
        $message .= "👥 中奖人数：" . count($winners) . "人\n";
        $message .= "🕐 开奖时间：" . $lotteryLog->executed_at->format('Y-m-d H:i:s') . "\n\n";

        $message .= "🏆 中奖用户：\n";
        foreach ($winners as $index => $winner) {
            $userEmail = $winner->email ?? 'user_' . $winner->id;
            // 隐藏邮箱中间部分保护隐私
            $maskedEmail = $this->maskEmail($userEmail);
            $message .= ($index + 1) . ". {$maskedEmail}\n";
        }

        $message .= "\n🎊 恭喜以上用户！奖励已自动发放到账户。";

        return $message;
    }

    /**
     * 隐藏邮箱中间部分保护用户隐私
     */
    private function maskEmail($email)
    {
        if (strpos($email, '@') === false) {
            return $email;
        }

        list($username, $domain) = explode('@', $email);
        $usernameLength = strlen($username);

        if ($usernameLength <= 2) {
            return $username . '@' . $domain;
        }

        $maskedUsername = substr($username, 0, 1) .
                         str_repeat('*', min($usernameLength - 2, 4)) .
                         substr($username, -1);

        return $maskedUsername . '@' . $domain;
    }

    /**
     * 格式化流量显示
     */
    private function formatTraffic($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 发送消息到Telegram
     */
    private function sendTelegramMessage($botToken, $chatId, $message, $parseMode = 'HTML', $retries = 3)
    {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => $parseMode
        ];

        for ($attempt = 0; $attempt < $retries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($result !== false && $httpCode === 200) {
                curl_close($ch);
                Log::info('Telegram消息发送成功', [
                    'chat_id' => $chatId,
                    'attempt' => $attempt + 1
                ]);
                return json_decode($result, true);
            }

            $errorMessage = curl_error($ch);
            curl_close($ch);

            Log::warning('Telegram消息发送失败，准备重试', [
                'attempt' => $attempt + 1,
                'http_code' => $httpCode,
                'error' => $errorMessage,
                'chat_id' => $chatId
            ]);

            if ($attempt < $retries - 1) {
                sleep(2); // 重试前等待2秒
            }
        }

        throw new \Exception("发送Telegram消息失败，已重试{$retries}次");
    }
}
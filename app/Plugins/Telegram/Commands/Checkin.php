<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Models\CheckinLog;
use App\Models\CheckinConfig;
use App\Plugins\Telegram\Telegram;

class Checkin extends Telegram {
    public $command = '/checkin';
    public $description = '每日签到获取流量奖励';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;

        // 检查消息对象是否有效
        if (!$message || !isset($message->chat_id)) {
            return;
        }

        // 只允许私聊使用
        if (!$message->is_private) {
            $telegramService->sendMessage($message->chat_id, '❌ 签到功能只能在私聊中使用', 'markdown');
            return;
        }

        // 查找绑定的用户
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage(
                $message->chat_id, 
                "❌ *签到失败*\n\n您还没有绑定账号，请先使用 `/bind` 命令绑定您的账号。\n\n💡 *如何绑定：*\n1. 复制您的订阅地址\n2. 发送 `/bind 您的订阅地址`", 
                'markdown'
            );
            return;
        }

        // 执行签到
        $result = CheckinLog::checkin($user->id);
        
        if ($result['success']) {
            // 签到成功
            $messageText = "✅ *签到成功！*\n\n";
            $messageText .= "🎉 {$result['message']}\n\n";
            $messageText .= "📊 *签到统计：*\n";
            $messageText .= "• 连续签到：{$result['consecutive_days']} 天\n";
            $messageText .= "• 本次奖励：{$result['reward_desc']}\n\n";
            
            // 获取用户当前状态
            $stats = CheckinLog::getUserStats($user->id);
            $messageText .= "📈 *累计数据：*\n";
            $messageText .= "• 总签到天数：{$stats['total_days']} 天\n";
            $messageText .= "• 累计流量：{$stats['total_traffic_formatted']}\n";
            
            // 获取签到配置信息
            $config = CheckinConfig::getConfigByPlanId($user->plan_id ?? null);
            if ($config) {
                $messageText .= "\n🎁 *奖励规则：*\n";
                if ($config->reward_mode === 'fixed') {
                    $messageText .= "• 每日固定：" . CheckinLog::formatBytes($config->daily_traffic) . "\n";
                    if ($config->consecutive_bonus > 0) {
                        $messageText .= "• 连续{$config->consecutive_days}天额外奖励：" . CheckinLog::formatBytes($config->consecutive_bonus) . "\n";
                    }
                } else {
                    $messageText .= "• 随机奖励：" . CheckinLog::formatBytes($config->min_traffic) . " ~ " . CheckinLog::formatBytes($config->max_traffic) . "\n";
                }
            }

            $messageText .= "\n💡 明天记得继续签到哦！";
            
        } else {
            // 签到失败
            $messageText = "❌ *签到失败*\n\n";

            // 检查是否已经签到过
            if (strpos($result['message'], '今天已经签到过了') !== false) {
                $messageText = "✅ *今日已签到*\n\n";
                $messageText .= "您今天已经签到过了，请明天再来！\n\n";
                
                // 获取今日签到记录
                $today = date('Y-m-d');
                $todayCheckin = CheckinLog::where('user_id', $user->id)
                    ->where('checkin_date', $today)
                    ->first();

                if ($todayCheckin) {
                    $messageText .= "📊 *今日签到信息：*\n";
                    $messageText .= "• 连续签到：{$todayCheckin->consecutive_days} 天\n";
                    $messageText .= "• 获得奖励：{$todayCheckin->reward_desc}\n\n";
                }

                // 获取用户统计
                $stats = CheckinLog::getUserStats($user->id);
                $messageText .= "📈 *累计数据：*\n";
                $messageText .= "• 总签到天数：{$stats['total_days']} 天\n";
                $messageText .= "• 累计流量：{$stats['total_traffic_formatted']}\n";

                $messageText .= "\n💡 明天记得继续签到哦！";
            } else {
                $messageText .= "错误信息：{$result['message']}\n\n";
                $messageText .= "请稍后重试，如果问题持续存在，请联系管理员。";
            }
        }
        
        $telegramService->sendMessage($message->chat_id, $messageText, 'markdown');
    }
}

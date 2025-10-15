<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\CheckinLog;
use App\Models\CheckinConfig;
use Illuminate\Http\Request;

class CheckinController extends Controller
{
    /**
     * 用户签到
     */
    public function checkin(Request $request)
    {
        $userId = $request->user['id'];
        $result = CheckinLog::checkin($userId);
        
        return response([
            'data' => $result
        ]);
    }

    /**
     * 获取签到状态
     */
    public function getStatus(Request $request)
    {
        $userId = $request->user['id'];
        
        $stats = CheckinLog::getUserStats($userId);
        
        // 获取用户的签到配置
        $user = \App\Models\User::find($userId);
        $config = CheckinConfig::getConfigByPlanId($user->plan_id ?? null);
        
        $data = [
            'today_checked' => $stats['today_checked'],
            'consecutive_days' => $stats['consecutive_days'],
            'total_days' => $stats['total_days'],
            'total_traffic' => $stats['total_traffic'],
            'total_traffic_formatted' => $stats['total_traffic_formatted'],
            'config' => $config ? [
                'reward_mode' => $config->reward_mode ?: 'fixed',
                'daily_traffic' => $config->daily_traffic,
                'min_traffic' => $config->min_traffic,
                'max_traffic' => $config->max_traffic,
                'daily_traffic_formatted' => $config->daily_traffic_formatted,
                'min_traffic_formatted' => $config->min_traffic_formatted,
                'max_traffic_formatted' => $config->max_traffic_formatted,
                'consecutive_bonus' => $config->consecutive_bonus,
                'consecutive_bonus_formatted' => $config->consecutive_bonus_formatted,
                'consecutive_days' => $config->consecutive_days,
                'description' => $config->description,
            ] : null
        ];
        
        return response([
            'data' => $data
        ]);
    }

    /**
     * 获取签到历史
     */
    public function getHistory(Request $request)
    {
        $userId = $request->user['id'];
        $limit = $request->input('limit', 30);
        
        $history = CheckinLog::getUserHistory($userId, $limit);
        
        return response([
            'data' => $history
        ]);
    }

    /**
     * 获取签到配置（用户可见）
     */
    public function getConfig(Request $request)
    {
        $user = \App\Models\User::find($request->user['id']);
        $config = CheckinConfig::getConfigByPlanId($user->plan_id ?? null);

        // 调试信息
        \Log::info('用户签到配置调试', [
            'user_id' => $user->id,
            'user_plan_id' => $user->plan_id,
            'config_found' => $config ? true : false,
            'config_data' => $config ? $config->toArray() : null
        ]);

        if (!$config) {
            return response([
                'data' => null
            ]);
        }

        // 强制构建完整的数据结构
        $data = [
            'id' => $config->id,
            'plan_name' => $config->plan_name ?: '默认配置',
            'reward_mode' => $config->reward_mode ?: 'fixed',
            'daily_traffic' => $config->daily_traffic ?: 0,
            'min_traffic' => $config->min_traffic ?: 0,
            'max_traffic' => $config->max_traffic ?: 0,
            'consecutive_bonus' => $config->consecutive_bonus ?: 0,
            'consecutive_days' => $config->consecutive_days ?: 0,
            'enabled' => $config->enabled,
            'daily_traffic_formatted' => $config->daily_traffic_formatted,
            'min_traffic_formatted' => $config->min_traffic_formatted,
            'max_traffic_formatted' => $config->max_traffic_formatted,
            'consecutive_bonus_formatted' => $config->consecutive_bonus_formatted,
            'description' => $config->description,
        ];

        // 调试返回数据
        \Log::info('返回给前端的配置数据', $data);
        \Log::info('原始配置对象', $config->toArray());

        return response([
            'data' => $data
        ]);
    }

    /**
     * 获取签到排行榜（可选功能）
     */
    public function getLeaderboard(Request $request)
    {
        $limit = $request->input('limit', 10);
        $type = $request->input('type', 'total'); // total: 总签到天数, consecutive: 连续签到天数
        
        if ($type === 'consecutive') {
            // 连续签到排行榜（需要复杂查询，这里简化处理）
            $leaderboard = CheckinLog::selectRaw('user_id, MAX(consecutive_days) as max_consecutive')
                ->groupBy('user_id')
                ->orderBy('max_consecutive', 'desc')
                ->limit($limit)
                ->with('user:id,email')
                ->get();
        } else {
            // 总签到天数排行榜
            $leaderboard = CheckinLog::selectRaw('user_id, COUNT(*) as total_days, SUM(reward_traffic) as total_traffic')
                ->groupBy('user_id')
                ->orderBy('total_days', 'desc')
                ->limit($limit)
                ->with('user:id,email')
                ->get();
        }
        
        // 隐藏邮箱敏感信息
        $leaderboard->each(function($item) {
            if ($item->user && $item->user->email) {
                $email = $item->user->email;
                $item->user->email = substr($email, 0, 3) . '***' . substr($email, strpos($email, '@'));
            }
        });
        
        return response([
            'data' => $leaderboard
        ]);
    }
}

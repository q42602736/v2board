<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CheckinLog;
use App\Models\CheckinConfig;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckinController extends Controller
{
    /**
     * 获取签到配置列表
     */
    public function getConfigs(Request $request)
    {
        try {
            $configs = CheckinConfig::orderBy('plan_id')->get();

            return response([
                'data' => $configs
            ]);
        } catch (\Exception $e) {
            \Log::error('获取签到配置失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response([
                'data' => [],
                'message' => '获取配置失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 保存签到配置
     */
    public function saveConfig(Request $request)
    {
        $request->validate([
            'plan_id' => 'nullable|integer',
            'reward_mode' => 'required|in:fixed,random',
            'daily_traffic' => 'required_if:reward_mode,fixed|integer|min:0',
            'min_traffic' => 'required_if:reward_mode,random|integer|min:0',
            'max_traffic' => 'required_if:reward_mode,random|integer|min:0|gte:min_traffic',
            'consecutive_bonus' => 'nullable|integer|min:0',
            'consecutive_days' => 'nullable|integer|min:0',
            'enabled' => 'boolean',
            'reset_with_traffic' => 'boolean'
        ]);

        $planId = $request->input('plan_id');
        
        // 如果是特定套餐配置，检查套餐是否存在
        if ($planId) {
            $plan = Plan::find($planId);
            if (!$plan) {
                return response([
                    'data' => ['success' => false, 'message' => '套餐不存在']
                ]);
            }
            $planName = $plan->name;
        } else {
            $planName = '默认配置';
        }

        $data = [
            'plan_name' => $planName,
            'reward_mode' => $request->input('reward_mode', 'fixed'),
            'daily_traffic' => $request->input('daily_traffic', 0),
            'min_traffic' => $request->input('min_traffic'),
            'max_traffic' => $request->input('max_traffic'),
            'consecutive_bonus' => $request->input('consecutive_bonus', 0),
            'consecutive_days' => $request->input('consecutive_days', 0),
            'enabled' => $request->input('enabled', true),
            'reset_with_traffic' => $request->boolean('reset_with_traffic'),
        ];

        try {
            $config = CheckinConfig::createOrUpdate($planId, $data);
            
            return response([
                'data' => ['success' => true, 'message' => '配置保存成功', 'config' => $config]
            ]);
        } catch (\Exception $e) {
            return response([
                'data' => ['success' => false, 'message' => '配置保存失败：' . $e->getMessage()]
            ]);
        }
    }

    /**
     * 删除签到配置
     */
    public function deleteConfig(Request $request)
    {
        $configId = $request->input('id');
        
        $config = CheckinConfig::find($configId);
        if (!$config) {
            return response([
                'data' => ['success' => false, 'message' => '配置不存在']
            ]);
        }

        // 允许删除所有配置，包括默认配置

        try {
            $config->delete();
            
            return response([
                'data' => ['success' => true, 'message' => '配置删除成功']
            ]);
        } catch (\Exception $e) {
            return response([
                'data' => ['success' => false, 'message' => '配置删除失败：' . $e->getMessage()]
            ]);
        }
    }

    /**
     * 获取签到统计
     */
    public function getStats(Request $request)
    {
        $startDate = $request->input('start_date', date('Y-m-d', strtotime('-30 days')));
        $endDate = $request->input('end_date', date('Y-m-d'));

        // 总体统计
        $totalStats = [
            'total_users' => CheckinLog::distinct('user_id')->count(),
            'total_checkins' => CheckinLog::count(),
            'total_traffic' => CheckinLog::sum('reward_traffic'),
            'today_checkins' => CheckinLog::whereDate('checkin_date', date('Y-m-d'))->count(),
        ];

        // 每日签到统计
        $dailyStats = CheckinLog::selectRaw('checkin_date, COUNT(*) as checkin_count, SUM(reward_traffic) as total_traffic')
            ->whereBetween('checkin_date', [$startDate, $endDate])
            ->groupBy('checkin_date')
            ->orderBy('checkin_date')
            ->get();

        // 套餐签到统计
        $planStats = CheckinLog::selectRaw('plan_id, COUNT(*) as checkin_count, SUM(reward_traffic) as total_traffic')
            ->whereNotNull('plan_id')
            ->groupBy('plan_id')
            ->get();

        // 手动加载套餐信息
        $planIds = $planStats->pluck('plan_id')->unique();
        $plans = Plan::whereIn('id', $planIds)->get()->keyBy('id');

        $planStats->each(function($stat) use ($plans) {
            $stat->plan = $plans->get($stat->plan_id);
        });

        return response([
            'data' => [
                'total_stats' => $totalStats,
                'daily_stats' => $dailyStats,
                'plan_stats' => $planStats,
            ]
        ]);
    }

    /**
     * 获取签到记录
     */
    public function getLogs(Request $request)
    {
        $pageSize = $request->input('pageSize', 20);
        $current = $request->input('current', 1);
        $userId = $request->input('user_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = CheckinLog::with('user:id,email');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($startDate) {
            $query->where('checkin_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('checkin_date', '<=', $endDate);
        }

        $total = $query->count();
        $logs = $query->orderBy('created_at', 'desc')
            ->forPage($current, $pageSize)
            ->get();

        return response([
            'data' => [
                'data' => $logs,
                'total' => $total,
                'current' => $current,
                'pageSize' => $pageSize,
            ]
        ]);
    }

    /**
     * 获取可用套餐列表
     */
    public function getPlans(Request $request)
    {
        try {
            // 检查Plan模型是否存在
            if (!class_exists(\App\Models\Plan::class)) {
                return response([
                    'data' => []
                ]);
            }

            $plans = \App\Models\Plan::select('id', 'name', 'transfer_enable')
                ->where('show', 1)
                ->orderBy('sort')
                ->get();

            return response([
                'data' => $plans
            ]);
        } catch (\Exception $e) {
            \Log::error('获取套餐列表失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response([
                'data' => [],
                'message' => '获取套餐失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 批量设置套餐签到配置
     */
    public function batchSetConfig(Request $request)
    {
        $request->validate([
            'configs' => 'required|array',
            'configs.*.plan_id' => 'required|integer',
            'configs.*.daily_traffic' => 'required|integer|min:0',
            'configs.*.consecutive_bonus' => 'integer|min:0',
            'configs.*.consecutive_days' => 'integer|min:1',
            'configs.*.reset_with_traffic' => 'boolean',
        ]);

        $configs = $request->input('configs');
        $successCount = 0;
        $failedCount = 0;
        $messages = [];

        DB::beginTransaction();
        try {
            foreach ($configs as $configData) {
                $plan = Plan::find($configData['plan_id']);
                if (!$plan) {
                    $failedCount++;
                    $messages[] = "套餐ID {$configData['plan_id']} 不存在";
                    continue;
                }

                $data = [
                    'plan_name' => $plan->name,
                    'daily_traffic' => $configData['daily_traffic'],
                    'consecutive_bonus' => $configData['consecutive_bonus'] ?? 0,
                    'consecutive_days' => $configData['consecutive_days'] ?? 7,
                    'enabled' => true,
                    'reset_with_traffic' => (bool)($configData['reset_with_traffic'] ?? false),
                ];

                CheckinConfig::createOrUpdate($configData['plan_id'], $data);
                $successCount++;
            }

            DB::commit();

            return response([
                'data' => [
                    'success' => true,
                    'message' => "批量设置完成，成功 {$successCount} 个，失败 {$failedCount} 个",
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'failed_messages' => $messages
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response([
                'data' => [
                    'success' => false,
                    'message' => '批量设置失败：' . $e->getMessage()
                ]
            ]);
        }
    }
}

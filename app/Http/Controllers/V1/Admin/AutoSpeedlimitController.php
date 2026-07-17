<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutoSpeedlimitConfig;
use App\Models\AutoSpeedlimitLog;
use App\Models\User;
use App\Services\AutoSpeedlimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AutoSpeedlimitController extends Controller
{
    protected $autoSpeedlimitService;

    public function __construct(AutoSpeedlimitService $autoSpeedlimitService)
    {
        $this->autoSpeedlimitService = $autoSpeedlimitService;
    }

    /**
     * 获取自动限速配置
     */
    public function getConfig()
    {
        try {
            $config = AutoSpeedlimitConfig::getConfig();
            
            return response()->json([
                'data' => $config,
                'summary' => $config->getConfigSummary()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '获取配置失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 更新自动限速配置
     */
    public function updateConfig(Request $request)
    {
        $currentConfig = AutoSpeedlimitConfig::getConfig();
        $limitBasis = $request->input('limit_basis', $currentConfig->limit_basis ?: 'ratio');
        $maxThreshold = $limitBasis === 'daily_fixed' ? 999.99 : 100;

        $validator = Validator::make($request->all(), [
            'enable' => 'required|boolean',
            'limit_basis' => 'sometimes|in:ratio,daily_fixed',
            'traffic_mode' => 'required|in:daily,total,both',
            'daily_calc_mode' => 'required|in:total,remaining',
            'threshold_1' => "nullable|numeric|min:0.01|max:{$maxThreshold}",
            'speed_1' => 'nullable|integer|min:1',
            'threshold_2' => "nullable|numeric|min:0.01|max:{$maxThreshold}",
            'speed_2' => 'nullable|integer|min:1',
            'threshold_3' => "nullable|numeric|min:0.01|max:{$maxThreshold}",
            'speed_3' => 'nullable|integer|min:1',
            'threshold_4' => "nullable|numeric|min:0.01|max:{$maxThreshold}",
            'speed_4' => 'nullable|integer|min:1',
            'threshold_5' => "nullable|numeric|min:0.01|max:{$maxThreshold}",
            'speed_5' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => '参数验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->all();
            
            // 验证阈值和限速的配对
            for ($i = 1; $i <= 5; $i++) {
                $threshold = $data["threshold_$i"] ?? null;
                $speed = $data["speed_$i"] ?? null;
                
                // 如果阈值和限速只有一个有值，则报错
                if (($threshold !== null && $speed === null) || ($threshold === null && $speed !== null)) {
                    return response()->json([
                        'message' => "阈值{$i}和限速{$i}必须同时设置或同时为空"
                    ], 422);
                }
            }
            
            $config = AutoSpeedlimitConfig::updateConfig($data);
            
            // 验证配置的有效性
            $errors = $config->validateConfig();
            if (!empty($errors)) {
                return response()->json([
                    'message' => '配置验证失败',
                    'errors' => $errors
                ], 422);
            }
            
            return response()->json([
                'message' => '配置更新成功',
                'data' => $config,
                'summary' => $config->getConfigSummary()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '配置更新失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取限速日志
     */
    public function getLogs(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);
            $userId = $request->get('user_id');
            $action = $request->get('action');
            
            $query = AutoSpeedlimitLog::with('user:id,email')
                ->orderBy('created_at', 'desc');
            
            if ($userId) {
                $query->where('user_id', $userId);
            }
            
            if ($action) {
                $query->where('action', $action);
            }
            
            $logs = $query->paginate($perPage);
            
            // 格式化日志数据
            $logs->getCollection()->transform(function ($log) {
                return [
                    'id' => $log->id,
                    'user_id' => $log->user_id,
                    'user_email' => $log->user ? $log->user->email : '用户已删除',
                    'action' => $log->action,
                    'action_desc' => $log->getActionDescription(),
                    'old_status' => $log->old_status,
                    'new_status' => $log->new_status,
                    'old_status_desc' => $log->getOldStatusDescription(),
                    'new_status_desc' => $log->getNewStatusDescription(),
                    'old_speedlimit' => $log->old_speedlimit,
                    'new_speedlimit' => $log->new_speedlimit,
                    'old_speedlimit_desc' => $log->getOldSpeedlimitDescription(),
                    'new_speedlimit_desc' => $log->getNewSpeedlimitDescription(),
                    'trigger_info' => $log->trigger_info,
                    'daily_percent' => $log->daily_percent,
                    'total_percent' => $log->total_percent,
                    'created_at' => $log->created_at,
                ];
            });
            
            return response()->json($logs);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '获取日志失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取当前被限速的用户列表
     */
    public function getLimitedUsers(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);
            
            $users = User::where('auto_speedlimit_status', '>', 0)
                ->orderBy('auto_speedlimit_status', 'desc')
                ->orderBy('id', 'asc')
                ->paginate($perPage);
            
            // 获取配置以计算流量百分比
            $config = AutoSpeedlimitConfig::getConfig();
            $autoSpeedlimitService = $this->autoSpeedlimitService;
            
            $users->getCollection()->transform(function ($user) use ($config, $autoSpeedlimitService) {
                $dailyStats = $autoSpeedlimitService->getUserTodayTrafficStats(
                    $user,
                    $config ? $config->daily_calc_mode : 'total'
                );
                $totalPercent = $user->getTrafficUsagePercent();
                
                return [
                    'id' => $user->id,
                    'email' => $user->email,
                    'auto_speedlimit_status' => $user->auto_speedlimit_status,
                    'speed_limit' => $user->speed_limit,
                    'original_speedlimit' => $user->original_speedlimit,
                    'daily_percent' => $dailyStats['daily_percent'],
                    'total_percent' => round($totalPercent, 2),
                    'transfer_enable' => $user->transfer_enable,
                    'u' => $user->u,
                    'd' => $user->d,
                    'today_used' => $dailyStats['today_used'],
                    'remaining' => $user->getRemainingTraffic(),
                ];
            });
            
            return response()->json($users);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '获取限速用户列表失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手动执行限速检查
     */
    public function executeCheck()
    {
        try {
            $result = $this->autoSpeedlimitService->executeAutoSpeedlimitCheck();
            
            return response()->json([
                'message' => $result['message'],
                'stats' => $result['stats']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '执行限速检查失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手动检查单个用户
     */
    public function checkUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:v2_user,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => '参数验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->autoSpeedlimitService->checkSingleUser($request->user_id);
            
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '检查用户失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手动恢复单个用户
     */
    public function restoreUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:v2_user,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => '参数验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->autoSpeedlimitService->restoreSingleUser($request->user_id);
            
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '恢复用户失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取系统统计信息
     */
    public function getStats()
    {
        try {
            $stats = $this->autoSpeedlimitService->getSystemStats();
            
            return response()->json([
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '获取统计信息失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 清理旧日志
     */
    public function cleanLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'keep_days' => 'required|integer|min:1|max:365'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => '参数验证失败',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $deletedCount = AutoSpeedlimitLog::cleanOldLogs($request->keep_days);
            
            return response()->json([
                'message' => "清理完成，删除了 {$deletedCount} 条旧日志",
                'deleted_count' => $deletedCount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '清理日志失败: ' . $e->getMessage()
            ], 500);
        }
    }
}

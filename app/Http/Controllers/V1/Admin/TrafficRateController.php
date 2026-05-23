<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrafficRateConfig;
use App\Models\TrafficRateLog;
use App\Models\TrafficRateBackup;
use App\Services\TrafficRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TrafficRateController extends Controller
{
    protected $trafficRateService;

    public function __construct(TrafficRateService $trafficRateService)
    {
        $this->trafficRateService = $trafficRateService;
    }

    /**
     * 获取配置列表
     */
    public function getConfigs(Request $request)
    {
        try {
            $configs = TrafficRateConfig::orderBy('created_at', 'desc')
            ->get()
            ->map(function ($config) {
                return [
                    'id' => $config->id,
                    'name' => $config->name,
                    'status' => $config->status,
                    'start_time' => $config->start_time,
                    'end_time' => $config->end_time,
                    'days_of_week' => $config->days_of_week,
                    'target_rate' => (float) $config->target_rate,
                    'node_filter' => $config->node_filter,
                    'node_ids' => $config->node_ids ?: [],
                    'node_filter_desc' => $this->getNodeFilterDescription($config),
                    'backup_enabled' => (bool) $config->backup_enabled,
                    'auto_restore' => (bool) $config->auto_restore,
                    'description' => $config->description,
                    'telegram_notify_enabled' => (bool) $config->telegram_notify_enabled,
                    'is_active' => $this->isCurrentlyActive($config),
                    'latest_log' => $config->logs()->orderBy('executed_at', 'desc')->first(),
                    'created_at' => $config->created_at ? $config->created_at->format('Y-m-d H:i:s') : null
                ];
            });

            return response([
                'data' => $configs
            ]);
        } catch (\Exception $e) {
            Log::error('获取流量倍率配置失败', [
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
     * 创建配置
     */
    public function createConfig(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'start_time' => 'required|date_format:H:i:s',
                'end_time' => 'required|date_format:H:i:s',
                'days_of_week' => 'nullable|string|regex:/^[1-7](,[1-7])*$/',
                'target_rate' => 'required|numeric|min:0.01|max:100',
                'node_filter' => ['required', Rule::in(['all', 'include', 'exclude'])],
                'node_ids' => 'nullable|array',
                'node_ids.*.server_type' => 'required_with:node_ids|string',
                'node_ids.*.server_id' => 'required_with:node_ids|integer|min:1',
                'backup_enabled' => 'boolean',
                'auto_restore' => 'boolean',
                'description' => 'nullable|string|max:500',
                'telegram_notify_enabled' => 'boolean'
            ]);

            // 创建临时配置对象检查时间冲突
            $tempConfig = new TrafficRateConfig($validated);
            if ($tempConfig->hasTimeConflict()) {
                return response([
                    'message' => '时间段与现有配置冲突，请选择其他时间段'
                ], 422);
            }

            $config = TrafficRateConfig::create($validated);

            return response([
                'data' => $config,
                'message' => '配置创建成功'
            ]);
        } catch (\Exception $e) {
            Log::error('创建流量倍率配置失败', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);

            return response([
                'message' => '创建配置失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 更新配置
     */
    public function updateConfig(Request $request, $id)
    {
        try {
            $config = TrafficRateConfig::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'start_time' => 'required|date_format:H:i:s',
                'end_time' => 'required|date_format:H:i:s',
                'days_of_week' => 'nullable|string|regex:/^[1-7](,[1-7])*$/',
                'target_rate' => 'required|numeric|min:0.01|max:100',
                'node_filter' => ['required', Rule::in(['all', 'include', 'exclude'])],
                'node_ids' => 'nullable|array',
                'node_ids.*.server_type' => 'required_with:node_ids|string',
                'node_ids.*.server_id' => 'required_with:node_ids|integer|min:1',
                'backup_enabled' => 'boolean',
                'auto_restore' => 'boolean',
                'description' => 'nullable|string|max:500',
                'telegram_notify_enabled' => 'boolean'
            ]);

            // 检查时间冲突（排除当前配置）
            $tempConfig = new TrafficRateConfig($validated);
            if ($tempConfig->hasTimeConflict($id)) {
                return response([
                    'message' => '时间段与其他配置冲突，请选择其他时间段'
                ], 422);
            }

            $config->update($validated);

            return response([
                'data' => $config,
                'message' => '配置更新成功'
            ]);
        } catch (\Exception $e) {
            Log::error('更新流量倍率配置失败', [
                'error' => $e->getMessage(),
                'config_id' => $id,
                'data' => $request->all()
            ]);

            return response([
                'message' => '更新配置失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 删除配置
     */
    public function deleteConfig($id)
    {
        try {
            $config = TrafficRateConfig::findOrFail($id);

            // 检查是否有未恢复的备份
            if (!TrafficRateBackup::canSafelyDeleteConfig($id)) {
                return response([
                    'message' => '该配置还有未恢复的备份记录，无法删除。请先恢复或手动清理备份。'
                ], 422);
            }

            $config->delete();

            return response([
                'message' => '配置删除成功'
            ]);
        } catch (\Exception $e) {
            Log::error('删除流量倍率配置失败', [
                'error' => $e->getMessage(),
                'config_id' => $id
            ]);

            return response([
                'message' => '删除配置失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 切换配置状态
     */
    public function toggleStatus($id)
    {
        try {
            $config = TrafficRateConfig::findOrFail($id);
            $config->update(['status' => !$config->status]);

            return response([
                'data' => $config,
                'message' => $config->status ? '配置已启用' : '配置已禁用'
            ]);
        } catch (\Exception $e) {
            Log::error('切换配置状态失败', [
                'error' => $e->getMessage(),
                'config_id' => $id
            ]);

            return response([
                'message' => '操作失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手动执行配置
     */
    public function executeConfig(Request $request, $id)
    {
        try {
            $config = TrafficRateConfig::findOrFail($id);
            
            if (!$config->status) {
                return response([
                    'message' => '配置未启用，无法执行'
                ], 422);
            }

            $result = $this->trafficRateService->executeConfig($config, 'start');

            return response([
                'data' => $result,
                'message' => '配置执行成功'
            ]);
        } catch (\Exception $e) {
            Log::error('手动执行配置失败', [
                'error' => $e->getMessage(),
                'config_id' => $id
            ]);

            return response([
                'message' => '执行失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手动恢复配置
     */
    public function restoreConfig($id)
    {
        try {
            $config = TrafficRateConfig::findOrFail($id);
            
            $result = $this->trafficRateService->executeConfig($config, 'end');

            return response([
                'data' => $result,
                'message' => '配置恢复成功'
            ]);
        } catch (\Exception $e) {
            Log::error('手动恢复配置失败', [
                'error' => $e->getMessage(),
                'config_id' => $id
            ]);

            return response([
                'message' => '恢复失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取执行记录
     */
    public function getLogs(Request $request)
    {
        try {
            $configId = $request->get('config_id');
            $limit = $request->get('limit', 20);

            $query = TrafficRateLog::with('config');

            if ($configId) {
                $query->where('config_id', $configId);
            }

            $logs = $query->orderBy('executed_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'config_name' => $log->config->name ?? '已删除配置',
                        'execution_type' => $log->execution_type,
                        'execution_type_desc' => $log->getExecutionTypeDescription(),
                        'target_rate' => $log->target_rate,
                        'affected_nodes' => $log->affected_nodes,
                        'status' => $log->status,
                        'status_desc' => $log->getStatusDescription(),
                        'status_color' => $log->getStatusColor(),
                        'executed_at' => $log->getFormattedExecutedAt(),
                        'error_message' => $log->getShortErrorMessage(),
                        'duration' => $log->getExecutionDuration()
                    ];
                });

            return response([
                'data' => $logs
            ]);
        } catch (\Exception $e) {
            Log::error('获取执行记录失败', [
                'error' => $e->getMessage()
            ]);

            return response([
                'data' => [],
                'message' => '获取记录失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取统计数据
     */
    public function getStats()
    {
        try {
            $totalConfigs = TrafficRateConfig::count();
            $activeConfigs = TrafficRateConfig::where('status', 1)->count();
            $currentlyRunning = TrafficRateConfig::getActiveConfigs()->count();
            
            $todayStats = TrafficRateLog::getTodayStats();
            $backupStats = TrafficRateBackup::getBackupStats();

            return response([
                'data' => [
                    'configs' => [
                        'total' => $totalConfigs,
                        'active' => $activeConfigs,
                        'inactive' => $totalConfigs - $activeConfigs,
                        'currently_running' => $currentlyRunning
                    ],
                    'executions' => $todayStats,
                    'backups' => $backupStats
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('获取统计数据失败', [
                'error' => $e->getMessage()
            ]);

            return response([
                'data' => [],
                'message' => '获取统计失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取可用节点列表
     */
    public function getAvailableNodes()
    {
        try {
            $nodes = $this->trafficRateService->getAllServerNodes();

            return response([
                'data' => $nodes
            ]);
        } catch (\Exception $e) {
            Log::error('获取节点列表失败', [
                'error' => $e->getMessage()
            ]);

            return response([
                'data' => [],
                'message' => '获取节点失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 清理过期备份
     */
    public function cleanupBackups()
    {
        try {
            $deletedCount = TrafficRateBackup::cleanupOldBackups();

            return response([
                'data' => ['deleted_count' => $deletedCount],
                'message' => "成功清理 {$deletedCount} 条过期备份记录"
            ]);
        } catch (\Exception $e) {
            Log::error('清理备份失败', [
                'error' => $e->getMessage()
            ]);

            return response([
                'message' => '清理失败：' . $e->getMessage()
            ], 500);
        }
    }





    /**
     * 获取节点筛选描述
     */
    private function getNodeFilterDescription(TrafficRateConfig $config)
    {
        switch ($config->node_filter) {
            case 'all':
                return '全部节点';
            case 'include':
                $nodeIds = is_string($config->node_ids) ? json_decode($config->node_ids, true) : $config->node_ids;
                $count = is_array($nodeIds) ? count($nodeIds) : 0;
                return "包含 {$count} 个节点";
            case 'exclude':
                $nodeIds = is_string($config->node_ids) ? json_decode($config->node_ids, true) : $config->node_ids;
                $count = is_array($nodeIds) ? count($nodeIds) : 0;
                return "排除 {$count} 个节点";
            default:
                return '未知';
        }
    }

    /**
     * 检查配置是否当前活跃
     */
    private function isCurrentlyActive(TrafficRateConfig $config)
    {
        if (!$config->status) {
            return false;
        }

        $now = now();
        $currentTime = $now->format('H:i:s');
        $currentDayOfWeek = $now->dayOfWeek === 0 ? 7 : $now->dayOfWeek;

        // 检查星期
        $daysOfWeek = $config->days_of_week ? explode(',', $config->days_of_week) : [1,2,3,4,5,6,7];
        if (!in_array($currentDayOfWeek, array_map('intval', $daysOfWeek))) {
            return false;
        }

        // 检查时间段
        if ($config->start_time < $config->end_time) {
            // 同一天内的时间段
            return $currentTime >= $config->start_time && $currentTime < $config->end_time;
        } else {
            // 跨天的时间段
            return $currentTime >= $config->start_time || $currentTime < $config->end_time;
        }
    }
}

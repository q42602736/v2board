<?php

namespace App\Services;

use App\Models\TrafficRateConfig;
use App\Models\TrafficRateLog;
use App\Models\TrafficRateBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrafficRateService
{
    /**
     * 服务器表列表
     */
    protected $serverTables = [
        'v2_server_hysteria',
        'v2_server_shadowsocks',
        'v2_server_trojan',
        'v2_server_vmess',
        'v2_server_vless'
    ];

    /**
     * 执行定时任务
     */
    public function executeScheduledTasks()
    {
        Log::info('开始执行流量倍率定时任务');

        try {
            // 检查需要开始的配置
            $this->checkAndStartConfigs();
            
            // 检查需要结束的配置
            $this->checkAndEndConfigs();

            Log::info('流量倍率定时任务执行完成');
        } catch (\Exception $e) {
            Log::error('流量倍率定时任务执行失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 检查并开始需要执行的配置
     */
    protected function checkAndStartConfigs()
    {
        $configs = TrafficRateConfig::getConfigsToStart();
        
        Log::info('检查需要开始的配置', ['count' => $configs->count()]);

        foreach ($configs as $config) {
            try {
                $this->executeConfig($config, 'start');
                Log::info('配置开始执行成功', ['config_id' => $config->id, 'name' => $config->name]);
            } catch (\Exception $e) {
                Log::error('配置开始执行失败', [
                    'config_id' => $config->id,
                    'name' => $config->name,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 检查并结束需要恢复的配置
     */
    protected function checkAndEndConfigs()
    {
        $configs = TrafficRateConfig::getConfigsToEnd();
        
        Log::info('检查需要结束的配置', ['count' => $configs->count()]);

        foreach ($configs as $config) {
            try {
                $this->executeConfig($config, 'end');
                Log::info('配置结束恢复成功', ['config_id' => $config->id, 'name' => $config->name]);
            } catch (\Exception $e) {
                Log::error('配置结束恢复失败', [
                    'config_id' => $config->id,
                    'name' => $config->name,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 执行配置
     */
    public function executeConfig(TrafficRateConfig $config, $executionType)
    {
        try {
            if ($executionType === 'start') {
                $affectedNodes = $this->applyRateToServers($config);
            } else {
                $affectedNodes = $this->restoreRateFromBackup($config);
            }

            // 创建执行日志
            try {
                $log = TrafficRateLog::createLog(
                    $config->id,
                    $executionType,
                    $executionType === 'start' ? $config->target_rate : 0,
                    $affectedNodes
                );
                $log->markAsSuccess($affectedNodes);

                // 发送Telegram通知
                if ($affectedNodes > 0) {
                    $this->sendTrafficRateNotificationToTelegram($config, $executionType, $affectedNodes, $log);
                }
            } catch (\Exception $logError) {
                return [
                    'success' => false,
                    'affected_nodes' => $affectedNodes,
                    'message' => "执行成功但日志创建失败: " . $logError->getMessage()
                ];
            }

            return [
                'success' => true,
                'affected_nodes' => $affectedNodes,
                'message' => "执行成功，影响节点数: {$affectedNodes}，日志ID: {$log->id}"
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * 应用倍率到服务器
     */
    protected function applyRateToServers(TrafficRateConfig $config)
    {
        $affectedNodes = 0;

        // 开始应用倍率到服务器

        // 解析node_ids（如果是JSON字符串）
        $nodeIds = $config->node_ids;
        if (is_string($nodeIds)) {
            $nodeIds = json_decode($nodeIds, true) ?: [];
        }
        if (!is_array($nodeIds)) {
            $nodeIds = [];
        }

        // 真实执行版本：实际更新数据库
        foreach ($this->serverTables as $table) {
            try {
                // 检查表是否存在
                if (!DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                // 检查表是否有rate字段
                $columns = DB::getSchemaBuilder()->getColumnListing($table);
                if (!in_array('rate', $columns)) {
                    continue;
                }

                // 构建查询
                $query = DB::table($table);

                // 应用节点筛选
                if ($config->node_filter === 'include' && !empty($nodeIds)) {
                    // 筛选包含的节点
                    $includeIds = [];
                    foreach ($nodeIds as $nodeInfo) {
                        if (is_array($nodeInfo) &&
                            isset($nodeInfo['server_type']) &&
                            isset($nodeInfo['server_id']) &&
                            $nodeInfo['server_type'] === $table) {
                            $includeIds[] = $nodeInfo['server_id'];
                        }
                    }
                    if (!empty($includeIds)) {
                        $query->whereIn('id', $includeIds);
                    } else {
                        continue;
                    }
                } elseif ($config->node_filter === 'exclude' && !empty($nodeIds)) {
                    // 筛选排除的节点
                    $excludeIds = [];
                    foreach ($nodeIds as $nodeInfo) {
                        if (is_array($nodeInfo) &&
                            isset($nodeInfo['server_type']) &&
                            isset($nodeInfo['server_id']) &&
                            $nodeInfo['server_type'] === $table) {
                            $excludeIds[] = $nodeInfo['server_id'];
                        }
                    }
                    if (!empty($excludeIds)) {
                        $query->whereNotIn('id', $excludeIds);
                    }
                }

                // 获取需要更新的记录（只更新倍率大于目标倍率的节点）
                $updateQuery = clone $query;
                $updateQuery->where('rate', '>', $config->target_rate);
                $count = $updateQuery->count();
                if ($count == 0) {
                    continue;
                }

                // 如果启用备份，先备份当前倍率（只备份实际会被更新的节点）
                if ($config->backup_enabled) {
                    try {
                        $servers = $updateQuery->get();
                        $backupData = [];

                        foreach ($servers as $server) {
                            // 智能备份策略：如果当前倍率等于目标倍率，说明可能已经被执行过
                            // 这种情况下，我们需要查找是否有更早的备份记录
                            $originalRate = $server->rate ?? 1.0;

                            if ($originalRate == $config->target_rate) {
                                // 当前倍率等于目标倍率，可能已经被修改过
                                // 查找是否有今天更早的备份记录
                                $earlierBackup = TrafficRateBackup::where('server_type', $table)
                                    ->where('server_id', $server->id)
                                    ->where('restored', false)
                                    ->whereDate('created_at', today())
                                    ->orderBy('created_at', 'asc')
                                    ->first();

                                if ($earlierBackup) {
                                    // 使用更早的备份记录中的原始倍率
                                    $originalRate = $earlierBackup->original_rate;
                                } else {
                                    // 没有找到备份记录，使用默认值1.0
                                    $originalRate = 1.0;
                                }
                            }

                            $backupData[] = [
                                'server_type' => $table,
                                'server_id' => $server->id,
                                'original_rate' => $originalRate
                            ];
                        }

                        if (!empty($backupData)) {
                            TrafficRateBackup::createBatchBackup($config->id, $backupData);
                        }
                    } catch (\Exception $backupError) {
                        // 备份失败不影响更新操作
                    }
                }

                // 实际更新倍率（只更新倍率大于目标倍率的节点）
                $updated = $updateQuery->update(['rate' => $config->target_rate]);
                $affectedNodes += $updated;

            } catch (\Exception $e) {
                // 跳过出错的表
            }
        }

        return $affectedNodes;
    }

    /**
     * 从备份恢复倍率
     */
    protected function restoreRateFromBackup(TrafficRateConfig $config)
    {
        $affectedNodes = 0;

        // 开始恢复倍率

        if ($config->backup_enabled) {
            // 从备份恢复原始倍率
            $backups = TrafficRateBackup::getUnrestoredBackups($config->id);

            // 记录调试信息到日志
            \Log::info("恢复配置 {$config->id}，找到 {$backups->count()} 条备份记录");

            if ($backups->count() == 0) {
                \Log::error("没有找到配置ID {$config->id} 的未恢复备份记录");
                return 0; // 没有备份记录，返回0
            }

            foreach ($backups as $backup) {
                try {
                    // 检查表是否存在
                    if (!DB::getSchemaBuilder()->hasTable($backup->server_type)) {
                        continue;
                    }

                    // 恢复原始倍率
                    $updated = DB::table($backup->server_type)
                        ->where('id', $backup->server_id)
                        ->update(['rate' => $backup->original_rate]);

                    \Log::info("恢复节点 {$backup->server_type}#{$backup->server_id}，原始倍率: {$backup->original_rate}，更新行数: {$updated}");

                    if ($updated > 0) {
                        // 标记为已恢复
                        $backup->markAsRestored();
                        $affectedNodes++;
                        \Log::info("节点恢复成功，已标记为已恢复");
                    } else {
                        \Log::warning("节点恢复失败，没有更新任何行");
                    }

                } catch (\Exception $e) {
                    // 跳过出错的备份
                }
            }
        } else {
            // 如果没有启用备份，恢复到默认倍率1.0
            foreach ($this->serverTables as $table) {
                try {
                    // 检查表是否存在
                    if (!DB::getSchemaBuilder()->hasTable($table)) {
                        continue;
                    }

                    // 检查表是否有rate字段
                    $columns = DB::getSchemaBuilder()->getColumnListing($table);
                    if (!in_array('rate', $columns)) {
                        continue;
                    }

                    // 恢复到默认倍率1.0
                    $updated = DB::table($table)->update(['rate' => 1.0]);
                    $affectedNodes += $updated;

                } catch (\Exception $e) {
                    // 跳过出错的表
                }
            }
        }

        return $affectedNodes;
    }

    /**
     * 获取所有服务器节点
     */
    public function getAllServerNodes()
    {
        $nodes = [];

        foreach ($this->serverTables as $table) {
            try {
                $servers = DB::table($table)
                    ->select('id', 'name', 'rate')
                    ->get();

                foreach ($servers as $server) {
                    $nodes[] = [
                        'id' => $server->id,
                        'name' => $server->name ?? "节点{$server->id}",
                        'type' => $this->getServerTypeDescription($table),
                        'rate' => $server->rate,
                        'server_type' => $table,
                        'full_id' => "{$table}_{$server->id}"
                    ];
                }
            } catch (\Exception $e) {
                Log::warning("获取服务器表 {$table} 失败", ['error' => $e->getMessage()]);
            }
        }

        return $nodes;
    }

    /**
     * 获取服务器类型描述
     */
    protected function getServerTypeDescription($table)
    {
        $typeMap = [
            'v2_server_hysteria' => 'Hysteria',
            'v2_server_shadowsocks' => 'Shadowsocks',
            'v2_server_trojan' => 'Trojan',
            'v2_server_vmess' => 'VMess',
            'v2_server_vless' => 'VLESS'
        ];

        return $typeMap[$table] ?? $table;
    }

    /**
     * 获取配置影响的节点预览
     */
    public function getConfigAffectedNodes(TrafficRateConfig $config)
    {
        $affectedNodes = [];

        foreach ($this->serverTables as $table) {
            try {
                $query = DB::table($table)->select('id', 'name', 'rate');

                // 应用节点筛选
                if ($config->node_filter === 'include' && !empty($config->node_ids)) {
                    // 筛选包含的节点：检查server_type和server_id的组合
                    $includeIds = [];
                    foreach ($config->node_ids as $nodeInfo) {
                        if (is_array($nodeInfo) && $nodeInfo['server_type'] === $table) {
                            $includeIds[] = $nodeInfo['server_id'];
                        }
                    }
                    if (!empty($includeIds)) {
                        $query->whereIn('id', $includeIds);
                    } else {
                        continue; // 跳过这个表，因为没有匹配的节点
                    }
                } elseif ($config->node_filter === 'exclude' && !empty($config->node_ids)) {
                    // 筛选排除的节点：检查server_type和server_id的组合
                    $excludeIds = [];
                    foreach ($config->node_ids as $nodeInfo) {
                        if (is_array($nodeInfo) && $nodeInfo['server_type'] === $table) {
                            $excludeIds[] = $nodeInfo['server_id'];
                        }
                    }
                    if (!empty($excludeIds)) {
                        $query->whereNotIn('id', $excludeIds);
                    }
                }

                $servers = $query->get();

                foreach ($servers as $server) {
                    $affectedNodes[] = [
                        'server_type' => $table,
                        'server_id' => $server->id,
                        'name' => $server->name ?? "节点{$server->id}",
                        'current_rate' => $server->rate, // 实时获取当前倍率
                        'target_rate' => $config->target_rate,
                        'type_desc' => $this->getServerTypeDescription($table)
                    ];
                }
            } catch (\Exception $e) {
                Log::warning("预览服务器表 {$table} 失败", ['error' => $e->getMessage()]);
            }
        }

        return $affectedNodes;
    }

    /**
     * 强制恢复所有未恢复的备份
     */
    public function forceRestoreAllBackups()
    {
        $allBackups = TrafficRateBackup::where('restored', false)->get();
        $restoredCount = 0;

        foreach ($allBackups as $backup) {
            try {
                DB::table($backup->server_type)
                    ->where('id', $backup->server_id)
                    ->update(['rate' => $backup->original_rate]);

                $backup->markAsRestored();
                $restoredCount++;

            } catch (\Exception $e) {
                Log::error('强制恢复备份失败', [
                    'backup_id' => $backup->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $restoredCount;
    }

    /**
     * 发送流量倍率通知到Telegram群
     */
    private function sendTrafficRateNotificationToTelegram($config, $executionType, $affectedNodes, $log)
    {
        // 检查配置是否启用了Telegram通知
        if (!$config->telegram_notify_enabled) {
            Log::info('此流量倍率配置未启用Telegram通知，跳过群发消息', [
                'config_id' => $config->id
            ]);
            return;
        }

        // 使用抽奖系统的Telegram配置（复用已有配置）
        $lotteryConfig = \App\Models\LotteryConfig::where('telegram_enabled', true)
            ->whereNotNull('telegram_bot_token')
            ->whereNotNull('telegram_chat_id')
            ->first();

        if (!$lotteryConfig) {
            Log::warning('未找到可用的Telegram配置，跳过流量倍率通知', [
                'config_id' => $config->id,
                'message' => '请先在抽奖系统中配置Telegram机器人'
            ]);
            return;
        }

        $botToken = $lotteryConfig->telegram_bot_token;
        $chatId = $lotteryConfig->telegram_chat_id;

        try {
            // 格式化通知消息
            $message = $this->formatTrafficRateMessage($config, $executionType, $affectedNodes, $log);

            // 发送消息到Telegram群
            $this->sendTelegramMessage($botToken, $chatId, $message);

            Log::info('流量倍率通知消息已发送到Telegram群', [
                'config_id' => $config->id,
                'execution_type' => $executionType,
                'affected_nodes' => $affectedNodes,
                'chat_id' => $chatId,
                'using_lottery_config' => $lotteryConfig->id
            ]);

        } catch (\Exception $e) {
            Log::error('发送Telegram流量倍率通知失败', [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 格式化流量倍率通知消息
     */
    private function formatTrafficRateMessage($config, $executionType, $affectedNodes, $log)
    {
        $appName = config('v2board.app_name', 'V2Board');

        if ($executionType === 'start') {
            // 应用倍率通知
            $message = "🚀 <b>{$appName} 流量倍率调整通知</b>\n\n";
            $message .= "📋 <b>配置名称：</b>{$config->name}\n";
            $message .= "⚡ <b>操作类型：</b>应用流量倍率限制\n";
            $message .= "📊 <b>目标倍率：</b><code>{$config->target_rate}x</code>\n";
            $message .= "🖥️ <b>影响节点：</b>{$affectedNodes} 个节点\n";
            $message .= "🕐 <b>执行时间：</b>" . $log->executed_at->format('Y-m-d H:i:s') . "\n";

            if (!empty($config->description)) {
                $message .= "📝 <b>说明：</b>{$config->description}\n";
            }

            // 添加节点筛选信息
            $filterText = $this->getNodeFilterDescription($config);
            if ($filterText) {
                $message .= "🎯 <b>节点范围：</b>{$filterText}\n";
            }

            $message .= "\n✅ <b>流量倍率已成功应用！</b>\n";
            $message .= "💡 <i>只有倍率高于 {$config->target_rate}x 的节点被调整</i>";

        } else {
            // 恢复倍率通知
            $message = "🔄 <b>{$appName} 流量倍率恢复通知</b>\n\n";
            $message .= "📋 <b>配置名称：</b>{$config->name}\n";
            $message .= "⚡ <b>操作类型：</b>恢复原始倍率\n";
            $message .= "🖥️ <b>恢复节点：</b>{$affectedNodes} 个节点\n";
            $message .= "🕐 <b>恢复时间：</b>" . $log->executed_at->format('Y-m-d H:i:s') . "\n";

            $message .= "\n✅ <b>节点倍率已恢复至原始设置！</b>";
        }

        return $message;
    }

    /**
     * 获取节点筛选描述
     */
    private function getNodeFilterDescription($config)
    {
        switch ($config->node_filter) {
            case 'all':
                return '全部节点';
            case 'include':
                $count = is_array($config->node_ids) ? count($config->node_ids) : 0;
                return "指定的 {$count} 个节点";
            case 'exclude':
                $count = is_array($config->node_ids) ? count($config->node_ids) : 0;
                return "排除 {$count} 个节点后的其他节点";
            default:
                return '';
        }
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

        for ($i = 0; $i < $retries; $i++) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $result = json_decode($response, true);
                    if ($result && $result['ok']) {
                        return true;
                    }
                }

                if ($i === $retries - 1) {
                    throw new \Exception("HTTP {$httpCode}: {$response}");
                }

                // 重试前等待
                sleep(1);

            } catch (\Exception $e) {
                if ($i === $retries - 1) {
                    throw $e;
                }
                sleep(1);
            }
        }

        return false;
    }
}

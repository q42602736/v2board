<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrafficRateConfig extends Model
{
    protected $table = 'v2_traffic_rate_config';

    protected $fillable = [
        'name',
        'status',
        'start_time',
        'end_time',
        'days_of_week',
        'target_rate',
        'node_filter',
        'node_ids',
        'backup_enabled',
        'auto_restore',
        'description',
        'telegram_notify_enabled'
    ];

    protected $casts = [
        'status' => 'boolean',
        'target_rate' => 'decimal:2',
        'backup_enabled' => 'boolean',
        'auto_restore' => 'boolean',
        'node_ids' => 'array',
        'telegram_notify_enabled' => 'boolean'
    ];

    /**
     * 获取执行记录
     */
    public function logs(): HasMany
    {
        return $this->hasMany(TrafficRateLog::class, 'config_id');
    }

    /**
     * 获取备份记录
     */
    public function backups(): HasMany
    {
        return $this->hasMany(TrafficRateBackup::class, 'config_id');
    }

    /**
     * 获取当前应该执行的配置
     */
    public static function getActiveConfigs()
    {
        $now = now();
        $currentTime = $now->format('H:i:s');
        $currentDayOfWeek = $now->dayOfWeek === 0 ? 7 : $now->dayOfWeek; // 转换为1-7格式

        return self::where('status', 1)
            ->where(function ($query) use ($currentTime, $currentDayOfWeek) {
                $query->where(function ($subQuery) use ($currentTime) {
                    // 同一天内的时间段 (如 09:00-17:00)
                    $subQuery->where('start_time', '<=', $currentTime)
                        ->where('end_time', '>', $currentTime)
                        ->whereRaw('start_time < end_time');
                })->orWhere(function ($subQuery) use ($currentTime) {
                    // 跨天的时间段 (如 23:00-06:00)
                    $subQuery->where(function ($innerQuery) use ($currentTime) {
                        $innerQuery->where('start_time', '<=', $currentTime)
                            ->whereRaw('start_time > end_time');
                    })->orWhere(function ($innerQuery) use ($currentTime) {
                        $innerQuery->where('end_time', '>', $currentTime)
                            ->whereRaw('start_time > end_time');
                    });
                });
            })
            ->where(function ($query) use ($currentDayOfWeek) {
                $query->whereRaw("FIND_IN_SET(?, days_of_week)", [$currentDayOfWeek])
                    ->orWhereNull('days_of_week')
                    ->orWhere('days_of_week', '');
            })
            ->get();
    }

    /**
     * 获取需要开始执行的配置
     */
    public static function getConfigsToStart()
    {
        $now = now();
        $currentTime = $now->format('H:i:s');
        $oneMinuteAgo = $now->copy()->subMinute()->format('H:i:s');
        $currentDayOfWeek = $now->dayOfWeek === 0 ? 7 : $now->dayOfWeek;

        return self::where('status', 1)
            ->where(function ($query) use ($currentTime) {
                // 检查当前时间是否在配置的时间段内
                $query->where(function ($timeQuery) use ($currentTime) {
                    // 同一天内的时间段
                    $timeQuery->whereColumn('start_time', '<', 'end_time')
                        ->where('start_time', '<=', $currentTime)
                        ->where('end_time', '>', $currentTime);
                })->orWhere(function ($timeQuery) use ($currentTime) {
                    // 跨天的时间段
                    $timeQuery->whereColumn('start_time', '>', 'end_time')
                        ->where(function ($crossDayQuery) use ($currentTime) {
                            $crossDayQuery->where('start_time', '<=', $currentTime)
                                ->orWhere('end_time', '>', $currentTime);
                        });
                });
            })
            ->where(function ($query) use ($currentDayOfWeek) {
                $query->whereRaw("FIND_IN_SET(?, days_of_week)", [$currentDayOfWeek])
                    ->orWhereNull('days_of_week')
                    ->orWhere('days_of_week', '');
            })
            ->whereDoesntHave('logs', function ($query) {
                $query->where('execution_type', 'start')
                    ->where('status', 'success')
                    ->whereDate('executed_at', today());
            })
            ->get();
    }

    /**
     * 获取需要结束执行的配置
     */
    public static function getConfigsToEnd()
    {
        $now = now();
        $currentTime = $now->format('H:i:s');
        $oneMinuteAgo = $now->copy()->subMinute()->format('H:i:s');
        $currentDayOfWeek = $now->dayOfWeek === 0 ? 7 : $now->dayOfWeek;

        return self::where('status', 1)
            ->where(function ($query) use ($currentTime) {
                // 检查当前时间是否刚好到达或超过结束时间
                $query->where(function ($subQuery) use ($currentTime) {
                    // 同一天内的时间段结束
                    $subQuery->where('end_time', '<=', $currentTime)
                        ->whereRaw('start_time < end_time');
                })->orWhere(function ($subQuery) use ($currentTime) {
                    // 跨天时间段在第二天结束
                    $subQuery->where('end_time', '<=', $currentTime)
                        ->whereRaw('start_time > end_time');
                });
            })
            ->where(function ($query) use ($currentDayOfWeek) {
                $query->whereRaw("FIND_IN_SET(?, days_of_week)", [$currentDayOfWeek])
                    ->orWhereNull('days_of_week')
                    ->orWhere('days_of_week', '');
            })
            ->whereHas('logs', function ($query) {
                $query->where('execution_type', 'start')
                    ->where('status', 'success')
                    ->whereDate('executed_at', today());
            })
            ->whereDoesntHave('logs', function ($query) {
                $query->where('execution_type', 'end')
                    ->where('status', 'success')
                    ->whereDate('executed_at', today());
            })
            ->get();
    }

    /**
     * 检查时间冲突
     */
    public function hasTimeConflict($excludeId = null)
    {
        // 获取当前配置的星期设置
        $currentDays = $this->getDaysOfWeekArray();

        $query = self::where('status', 1)
            ->where(function ($q) use ($currentDays) {
                // 检查星期是否有重叠
                foreach ($currentDays as $day) {
                    $q->orWhereRaw("FIND_IN_SET(?, days_of_week)", [$day]);
                }
            })
            ->where(function ($q) {
                // 检查时间段是否有重叠
                $q->where(function ($subQ) {
                    // 情况1：两个都是同一天内的时间段
                    $subQ->whereRaw('start_time < end_time') // 现有配置是同一天
                        ->where(function ($innerQ) {
                            $innerQ->where(function ($timeQ) {
                                // 新配置也是同一天，检查重叠
                                if ($this->isWithinSameDay()) {
                                    $timeQ->where('start_time', '<', $this->end_time)
                                        ->where('end_time', '>', $this->start_time);
                                } else {
                                    // 新配置跨天，与同一天配置必有重叠
                                    $timeQ->whereRaw('1=1');
                                }
                            });
                        });
                })->orWhere(function ($subQ) {
                    // 情况2：现有配置是跨天的
                    $subQ->whereRaw('start_time > end_time') // 现有配置跨天
                        ->where(function ($innerQ) {
                            if ($this->isWithinSameDay()) {
                                // 新配置是同一天，检查是否与跨天配置重叠
                                $innerQ->where('start_time', '<=', $this->end_time)
                                    ->orWhere('end_time', '>', $this->start_time);
                            } else {
                                // 两个都跨天，检查重叠
                                $innerQ->where('start_time', '<', $this->end_time)
                                    ->orWhere('end_time', '>', $this->start_time);
                            }
                        });
                });
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * 检查是否是同一天内的时间段
     */
    private function isWithinSameDay()
    {
        return $this->start_time < $this->end_time;
    }

    /**
     * 获取星期数组
     */
    public function getDaysOfWeekArray()
    {
        if (empty($this->days_of_week)) {
            return [1, 2, 3, 4, 5, 6, 7]; // 默认每天
        }

        return array_map('intval', explode(',', $this->days_of_week));
    }

    /**
     * 获取影响的节点列表
     */
    public function getAffectedNodes()
    {
        $serverTables = [
            'v2_server_hysteria',
            'v2_server_shadowsocks',
            'v2_server_trojan',
            'v2_server_vmess',
            'v2_server_vless'
        ];

        $affectedNodes = [];

        foreach ($serverTables as $table) {
            $query = \DB::table($table)->select('id', 'name', 'rate');

            if ($this->node_filter === 'include' && !empty($this->node_ids)) {
                // 筛选包含的节点：检查server_type和server_id的组合
                $includeIds = [];
                foreach ($this->node_ids as $nodeInfo) {
                    if (is_array($nodeInfo) && $nodeInfo['server_type'] === $table) {
                        $includeIds[] = $nodeInfo['server_id'];
                    }
                }
                if (!empty($includeIds)) {
                    $query->whereIn('id', $includeIds);
                } else {
                    continue; // 跳过这个表，因为没有匹配的节点
                }
            } elseif ($this->node_filter === 'exclude' && !empty($this->node_ids)) {
                // 筛选排除的节点：检查server_type和server_id的组合
                $excludeIds = [];
                foreach ($this->node_ids as $nodeInfo) {
                    if (is_array($nodeInfo) && $nodeInfo['server_type'] === $table) {
                        $excludeIds[] = $nodeInfo['server_id'];
                    }
                }
                if (!empty($excludeIds)) {
                    $query->whereNotIn('id', $excludeIds);
                }
            }

            $nodes = $query->get();
            foreach ($nodes as $node) {
                $affectedNodes[] = [
                    'server_type' => $table,
                    'server_id' => $node->id,
                    'name' => $node->name ?? "节点{$node->id}",
                    'current_rate' => $node->rate
                ];
            }
        }

        return $affectedNodes;
    }

    /**
     * 格式化节点筛选描述
     */
    public function getNodeFilterDescription()
    {
        switch ($this->node_filter) {
            case 'all':
                return '所有节点';
            case 'include':
                $count = is_array($this->node_ids) ? count($this->node_ids) : 0;
                if ($count > 0) {
                    $types = [];
                    foreach ($this->node_ids as $nodeInfo) {
                        if (is_array($nodeInfo) && isset($nodeInfo['server_type'])) {
                            $type = str_replace('v2_server_', '', $nodeInfo['server_type']);
                            $types[] = ucfirst($type);
                        }
                    }
                    $uniqueTypes = array_unique($types);
                    return "包含 {$count} 个节点 (" . implode(', ', $uniqueTypes) . ")";
                }
                return "包含 {$count} 个指定节点";
            case 'exclude':
                $count = is_array($this->node_ids) ? count($this->node_ids) : 0;
                if ($count > 0) {
                    $types = [];
                    foreach ($this->node_ids as $nodeInfo) {
                        if (is_array($nodeInfo) && isset($nodeInfo['server_type'])) {
                            $type = str_replace('v2_server_', '', $nodeInfo['server_type']);
                            $types[] = ucfirst($type);
                        }
                    }
                    $uniqueTypes = array_unique($types);
                    return "排除 {$count} 个节点 (" . implode(', ', $uniqueTypes) . ")";
                }
                return "排除 {$count} 个指定节点";
            default:
                return '未知';
        }
    }

    /**
     * 检查配置是否正在执行中
     */
    public function isCurrentlyActive()
    {
        $now = now();
        return $this->status && 
               $this->start_time <= $now && 
               $this->end_time > $now;
    }

    /**
     * 获取最近的执行记录
     */
    public function getLatestLog()
    {
        return $this->logs()
            ->orderBy('executed_at', 'desc')
            ->first();
    }
}

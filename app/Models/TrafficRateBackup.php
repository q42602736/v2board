<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficRateBackup extends Model
{
    protected $table = 'v2_traffic_rate_backup';

    protected $fillable = [
        'config_id',
        'server_type',
        'server_id',
        'original_rate',
        'backup_time',
        'restored',
        'restored_at'
    ];

    protected $casts = [
        'original_rate' => 'decimal:2',
        'restored' => 'boolean',
        'backup_time' => 'datetime',
        'restored_at' => 'datetime'
    ];

    /**
     * 获取关联的配置
     */
    public function config(): BelongsTo
    {
        return $this->belongsTo(TrafficRateConfig::class, 'config_id');
    }

    /**
     * 创建备份记录
     */
    public static function createBackup($configId, $serverType, $serverId, $originalRate)
    {
        return self::create([
            'config_id' => $configId,
            'server_type' => $serverType,
            'server_id' => $serverId,
            'original_rate' => $originalRate,
            'backup_time' => now(),
            'restored' => false
        ]);
    }

    /**
     * 批量创建备份记录
     */
    public static function createBatchBackup($configId, $serverData)
    {
        $backups = [];
        foreach ($serverData as $data) {
            $backups[] = [
                'config_id' => $configId,
                'server_type' => $data['server_type'],
                'server_id' => $data['server_id'],
                'original_rate' => $data['original_rate'],
                'backup_time' => now(),
                'restored' => false,
                'created_at' => now(),
                'updated_at' => now()
            ];
        }
        
        return self::insert($backups);
    }

    /**
     * 标记为已恢复
     */
    public function markAsRestored()
    {
        return $this->update([
            'restored' => true,
            'restored_at' => now()
        ]);
    }

    /**
     * 获取配置的未恢复备份
     */
    public static function getUnrestoredBackups($configId)
    {
        return self::where('config_id', $configId)
            ->where('restored', false)
            ->get();
    }

    /**
     * 获取服务器的备份记录
     */
    public static function getServerBackup($configId, $serverType, $serverId)
    {
        return self::where('config_id', $configId)
            ->where('server_type', $serverType)
            ->where('server_id', $serverId)
            ->where('restored', false)
            ->first();
    }

    /**
     * 批量标记为已恢复
     */
    public static function markBatchAsRestored($configId)
    {
        return self::where('config_id', $configId)
            ->where('restored', false)
            ->update([
                'restored' => true,
                'restored_at' => now()
            ]);
    }

    /**
     * 获取服务器类型描述
     */
    public function getServerTypeDescription()
    {
        $typeMap = [
            'v2_server_hysteria' => 'Hysteria',
            'v2_server_shadowsocks' => 'Shadowsocks',
            'v2_server_trojan' => 'Trojan',
            'v2_server_vmess' => 'VMess',
            'v2_server_vless' => 'VLESS'
        ];

        return $typeMap[$this->server_type] ?? $this->server_type;
    }

    /**
     * 获取服务器名称
     */
    public function getServerName()
    {
        try {
            $server = \DB::table($this->server_type)
                ->where('id', $this->server_id)
                ->first();
            
            return $server->name ?? "节点{$this->server_id}";
        } catch (\Exception $e) {
            return "节点{$this->server_id}";
        }
    }

    /**
     * 获取备份统计
     */
    public static function getBackupStats($configId = null)
    {
        $query = self::query();
        
        if ($configId) {
            $query->where('config_id', $configId);
        }
        
        return [
            'total' => $query->count(),
            'restored' => $query->where('restored', true)->count(),
            'pending' => $query->where('restored', false)->count(),
            'by_type' => $query->selectRaw('server_type, COUNT(*) as count')
                ->groupBy('server_type')
                ->pluck('count', 'server_type')
                ->toArray()
        ];
    }

    /**
     * 清理过期备份（超过30天的已恢复备份）
     */
    public static function cleanupOldBackups()
    {
        $thirtyDaysAgo = now()->subDays(30);
        
        return self::where('restored', true)
            ->where('restored_at', '<', $thirtyDaysAgo)
            ->delete();
    }

    /**
     * 获取配置的备份详情
     */
    public static function getConfigBackupDetails($configId)
    {
        return self::where('config_id', $configId)
            ->selectRaw('server_type, COUNT(*) as count, AVG(original_rate) as avg_rate')
            ->groupBy('server_type')
            ->get()
            ->map(function ($item) {
                return [
                    'server_type' => $item->server_type,
                    'type_name' => (new self(['server_type' => $item->server_type]))->getServerTypeDescription(),
                    'count' => $item->count,
                    'avg_rate' => round($item->avg_rate, 2)
                ];
            });
    }

    /**
     * 检查是否可以安全删除配置
     */
    public static function canSafelyDeleteConfig($configId)
    {
        $pendingBackups = self::where('config_id', $configId)
            ->where('restored', false)
            ->count();
            
        return $pendingBackups === 0;
    }

    /**
     * 获取恢复进度
     */
    public function getRestoreProgress($configId)
    {
        $total = self::where('config_id', $configId)->count();
        $restored = self::where('config_id', $configId)->where('restored', true)->count();
        
        return $total > 0 ? round(($restored / $total) * 100, 2) : 0;
    }
}

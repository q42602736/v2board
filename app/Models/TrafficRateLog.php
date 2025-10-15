<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficRateLog extends Model
{
    protected $table = 'v2_traffic_rate_log';

    protected $fillable = [
        'config_id',
        'execution_type',
        'affected_nodes',
        'target_rate',
        'executed_at',
        'status',
        'error_message'
    ];

    protected $casts = [
        'target_rate' => 'decimal:2',
        'affected_nodes' => 'integer',
        'executed_at' => 'datetime'
    ];

    /**
     * 获取关联的配置
     */
    public function config(): BelongsTo
    {
        return $this->belongsTo(TrafficRateConfig::class, 'config_id');
    }

    /**
     * 创建执行记录
     */
    public static function createLog($configId, $executionType, $targetRate, $affectedNodes = 0)
    {
        return self::create([
            'config_id' => $configId,
            'execution_type' => $executionType,
            'target_rate' => $targetRate,
            'affected_nodes' => $affectedNodes,
            'executed_at' => now(),
            'status' => 'pending'
        ]);
    }

    /**
     * 标记为成功
     */
    public function markAsSuccess($affectedNodes = null)
    {
        $data = ['status' => 'success'];
        if ($affectedNodes !== null) {
            $data['affected_nodes'] = $affectedNodes;
        }
        
        return $this->update($data);
    }

    /**
     * 标记为失败
     */
    public function markAsFailed($errorMessage)
    {
        return $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage
        ]);
    }

    /**
     * 获取执行类型描述
     */
    public function getExecutionTypeDescription()
    {
        return $this->execution_type === 'start' ? '开始执行' : '结束恢复';
    }

    /**
     * 获取状态描述
     */
    public function getStatusDescription()
    {
        switch ($this->status) {
            case 'success':
                return '成功';
            case 'failed':
                return '失败';
            case 'processing':
                return '处理中';
            default:
                return '未知';
        }
    }

    /**
     * 获取状态颜色
     */
    public function getStatusColor()
    {
        switch ($this->status) {
            case 'success':
                return 'success';
            case 'failed':
                return 'error';
            case 'processing':
                return 'warning';
            default:
                return 'default';
        }
    }

    /**
     * 获取今日执行统计
     */
    public static function getTodayStats()
    {
        $today = today();
        
        return [
            'total' => self::whereDate('executed_at', $today)->count(),
            'success' => self::whereDate('executed_at', $today)->where('status', 'success')->count(),
            'failed' => self::whereDate('executed_at', $today)->where('status', 'failed')->count(),
            'processing' => self::whereDate('executed_at', $today)->where('status', 'processing')->count(),
            'start_executions' => self::whereDate('executed_at', $today)->where('execution_type', 'start')->count(),
            'end_executions' => self::whereDate('executed_at', $today)->where('execution_type', 'end')->count()
        ];
    }

    /**
     * 获取最近的执行记录
     */
    public static function getRecentLogs($limit = 10)
    {
        return self::with('config')
            ->orderBy('executed_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * 获取配置的执行历史
     */
    public static function getConfigHistory($configId, $limit = 20)
    {
        return self::where('config_id', $configId)
            ->orderBy('executed_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * 检查配置今日是否已执行过指定类型
     */
    public static function hasExecutedToday($configId, $executionType)
    {
        return self::where('config_id', $configId)
            ->where('execution_type', $executionType)
            ->where('status', 'success')
            ->whereDate('executed_at', today())
            ->exists();
    }

    /**
     * 获取执行时长（秒）
     */
    public function getExecutionDuration()
    {
        if ($this->status === 'processing') {
            return now()->diffInSeconds($this->executed_at);
        }
        
        // 对于已完成的任务，可以根据更新时间计算
        return $this->updated_at ? $this->updated_at->diffInSeconds($this->executed_at) : 0;
    }

    /**
     * 格式化执行时间
     */
    public function getFormattedExecutedAt()
    {
        return $this->executed_at->format('Y-m-d H:i:s');
    }

    /**
     * 获取简短的错误信息
     */
    public function getShortErrorMessage($maxLength = 100)
    {
        if (!$this->error_message) {
            return '';
        }
        
        return strlen($this->error_message) > $maxLength 
            ? substr($this->error_message, 0, $maxLength) . '...'
            : $this->error_message;
    }
}

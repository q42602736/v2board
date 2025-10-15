<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LotteryConfig;
use App\Models\LotteryLog;
use App\Services\LotteryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class LotteryController extends Controller
{
    protected $lotteryService;

    public function __construct(LotteryService $lotteryService)
    {
        $this->lotteryService = $lotteryService;
    }

    /**
     * 测试方法 - 验证控制器是否可访问
     */
    public function test()
    {
        return response()->json([
            'message' => 'LotteryController is working!',
            'timestamp' => now()->toDateTimeString()
        ]);
    }

    /**
     * 获取抽奖配置列表
     */
    public function getConfigs(Request $request)
    {
        try {
            // 添加调试日志
            Log::info('LotteryController::getConfigs 被调用');

            // 尝试从数据库获取真实数据
            $configs = LotteryConfig::orderBy('id', 'desc')->get();

            Log::info('从数据库获取到配置数量: ' . $configs->count());

            return response()->json([
                'data' => $configs
            ]);
        } catch (\Exception $e) {
            Log::error('获取抽奖配置失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'data' => [],
                'message' => '获取配置失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 创建抽奖配置
     */
    public function createConfig(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'status' => 'required|boolean',
                'start_time' => 'required|date_format:H:i',
                'frequency' => 'required|integer|min:1|max:1440',
                'winner_count' => 'required|integer|min:1|max:100',
                'reward_type' => ['required', Rule::in(['balance', 'traffic'])],
                'reward_amount' => 'required|numeric|min:0.01',
                'cooldown_rounds' => 'required|integer|min:0|max:365',
                // Telegram 通知配置
                'telegram_enabled' => 'nullable|boolean',
                'telegram_bot_token' => 'nullable|string|max:500',
                'telegram_chat_id' => 'nullable|string|max:100'
            ]);

            // 处理单位转换
            if ($validated['reward_type'] === 'balance') {
                // 余额：元转分
                $validated['reward_amount'] = $validated['reward_amount'] * 100;
            }

            $config = LotteryConfig::create($validated);

            return response([
                'data' => $config,
                'message' => '创建成功'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response([
                'message' => '验证失败',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('创建抽奖配置失败', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);

            return response([
                'message' => '创建失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 更新抽奖配置
     */
    public function updateConfig(Request $request, $id)
    {
        try {
            $config = LotteryConfig::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'status' => 'required|boolean',
                'start_time' => 'required|date_format:H:i',
                'frequency' => 'required|integer|min:1|max:1440',
                'winner_count' => 'required|integer|min:1|max:100',
                'reward_type' => ['required', Rule::in(['balance', 'traffic'])],
                'reward_amount' => 'required|numeric|min:0.01',
                'cooldown_rounds' => 'required|integer|min:0|max:365',
                // Telegram 通知配置
                'telegram_enabled' => 'nullable|boolean',
                'telegram_bot_token' => 'nullable|string|max:500',
                'telegram_chat_id' => 'nullable|string|max:100'
            ]);

            // 处理单位转换
            if ($validated['reward_type'] === 'balance') {
                // 余额：元转分
                $validated['reward_amount'] = $validated['reward_amount'] * 100;
            }

            $config->update($validated);

            return response([
                'data' => $config->fresh(),
                'message' => '更新成功'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response([
                'message' => '配置不存在'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response([
                'message' => '验证失败',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('更新抽奖配置失败', [
                'id' => $id,
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);

            return response([
                'message' => '更新失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 删除抽奖配置
     */
    public function deleteConfig($id)
    {
        try {
            $config = LotteryConfig::findOrFail($id);
            
            // 检查是否有关联的抽奖记录
            $hasLogs = LotteryLog::where('config_id', $id)->exists();
            if ($hasLogs) {
                return response([
                    'message' => '该配置已有抽奖记录，无法删除'
                ], 400);
            }

            $config->delete();

            return response([
                'data' => true,
                'message' => '删除成功'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response([
                'message' => '配置不存在'
            ], 404);
        } catch (\Exception $e) {
            Log::error('删除抽奖配置失败', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response([
                'message' => '删除失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取单个配置详情
     */
    public function getConfig($id)
    {
        try {
            $config = LotteryConfig::findOrFail($id);

            return response([
                'data' => $config
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response([
                'message' => '配置不存在'
            ], 404);
        } catch (\Exception $e) {
            Log::error('获取抽奖配置详情失败', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response([
                'message' => '获取失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手动执行抽奖
     */
    public function executeLottery(Request $request, $configId)
    {
        try {
            Log::info('LotteryController::executeLottery 被调用', ['config_id' => $configId]);

            // 分步测试，先检查基础组件
            $config = LotteryConfig::findOrFail($configId);
            Log::info('配置检查通过', ['config_id' => $configId]);

            // 检查User模型是否可用
            try {
                $userCount = \App\Models\User::where('banned', 0)->count();
                Log::info('User模型检查通过', ['user_count' => $userCount]);
            } catch (\Exception $e) {
                Log::error('User模型检查失败', ['error' => $e->getMessage()]);
                throw new \Exception('User模型访问失败: ' . $e->getMessage());
            }

            // 检查LotteryLog模型是否可用
            try {
                $logCount = \App\Models\LotteryLog::count();
                Log::info('LotteryLog模型检查通过', ['log_count' => $logCount]);
            } catch (\Exception $e) {
                Log::error('LotteryLog模型检查失败', ['error' => $e->getMessage()]);
                throw new \Exception('LotteryLog模型访问失败: ' . $e->getMessage());
            }

            // 检查LotteryWinner模型是否可用
            try {
                $winnerCount = \App\Models\LotteryWinner::count();
                Log::info('LotteryWinner模型检查通过', ['winner_count' => $winnerCount]);
            } catch (\Exception $e) {
                Log::error('LotteryWinner模型检查失败', ['error' => $e->getMessage()]);
                throw new \Exception('LotteryWinner模型访问失败: ' . $e->getMessage());
            }

            // 如果所有检查都通过，执行简化的真实抽奖
            Log::info('开始执行简化抽奖逻辑');

            // 1. 获取符合条件的用户（简化版）
            $todayStart = today()->timestamp;
            $todayEnd = today()->addDay()->timestamp;

            $eligibleUsers = \App\Models\User::where('banned', 0)
                ->where('plan_id', '!=', 0) // 有有效套餐
                ->whereExists(function ($subQuery) use ($todayStart, $todayEnd) {
                    $subQuery->selectRaw('1')
                        ->from('v2_stat_user')
                        ->whereColumn('v2_stat_user.user_id', 'v2_user.id')
                        ->where('record_at', '>=', $todayStart)
                        ->where('record_at', '<', $todayEnd)
                        ->whereRaw('(u + d) > 0'); // 当日有流量消耗
                })
                ->get();

            Log::info('符合条件用户', ['count' => $eligibleUsers->count()]);

            if ($eligibleUsers->count() == 0) {
                throw new \Exception('没有符合条件的用户');
            }

            // 2. 确定实际中奖人数
            $actualWinners = min($config->winner_count, $eligibleUsers->count());

            // 3. 随机选择中奖用户
            $winners = $eligibleUsers->random($actualWinners);
            Log::info('选择中奖用户', ['winner_count' => $winners->count()]);

            // 4. 创建抽奖记录
            $roundNumber = date('Ymd') . '001'; // 简化的轮次号
            $lotteryLog = \App\Models\LotteryLog::create([
                'config_id' => $config->id,
                'round_number' => $roundNumber,
                'status' => 'processing',
                'total_participants' => $eligibleUsers->count(),
                'winner_count' => 0,
                'total_reward' => 0,
                'executed_at' => now(),
                'created_at' => now()
            ]);

            Log::info('创建抽奖记录', ['log_id' => $lotteryLog->id]);

            // 5. 发放奖励并记录中奖信息
            $winnerRecords = [];
            foreach ($winners as $winner) {
                Log::info('处理中奖用户', ['user_id' => $winner->id, 'current_balance' => $winner->balance]);

                // 发放奖励
                if ($config->reward_type === 'balance') {
                    $winner->increment('balance', $config->reward_amount);
                    Log::info('发放余额奖励', [
                        'user_id' => $winner->id,
                        'reward_amount' => $config->reward_amount,
                        'new_balance' => $winner->fresh()->balance
                    ]);
                } else {
                    $winner->increment('transfer_enable', $config->reward_amount);
                    Log::info('发放流量奖励', [
                        'user_id' => $winner->id,
                        'reward_amount' => $config->reward_amount
                    ]);
                }

                // 记录中奖信息
                $winnerRecord = \App\Models\LotteryWinner::create([
                    'lottery_log_id' => $lotteryLog->id,
                    'user_id' => $winner->id,
                    'reward_type' => $config->reward_type,
                    'reward_amount' => $config->reward_amount,
                    'round_number' => $roundNumber,
                    'created_at' => now()
                ]);

                $winnerRecords[] = $winnerRecord;
                Log::info('记录中奖信息', ['winner_record_id' => $winnerRecord->id]);
            }

            // 6. 更新抽奖记录为成功
            $lotteryLog->update([
                'status' => 'success',
                'winner_count' => count($winnerRecords),
                'total_reward' => collect($winnerRecords)->sum('reward_amount'),
                'executed_at' => now()
            ]);

            Log::info('抽奖执行完成', [
                'log_id' => $lotteryLog->id,
                'winners' => count($winnerRecords)
            ]);

            // 发送中奖消息到Telegram群
            if (count($winnerRecords) > 0) {
                $this->sendLotteryNotificationToTelegram($config, $winners, $lotteryLog);
            }

            $result = [
                'config_id' => $configId,
                'config_name' => $config->name,
                'log_id' => $lotteryLog->id,
                'round_number' => $roundNumber,
                'executed_at' => $lotteryLog->executed_at,
                'status' => 'success',
                'participants' => $eligibleUsers->count(),
                'winners' => count($winnerRecords),
                'total_reward' => collect($winnerRecords)->sum('reward_amount'),
                'message' => '抽奖执行成功',
                'winner_list' => $winners->map(function($winner) use ($config) {
                    return [
                        'user_id' => $winner->id,
                        'email' => $winner->email ?? 'unknown',
                        'reward_amount' => $config->reward_amount,
                        'reward_type' => $config->reward_type
                    ];
                })
            ];

            return response()->json([
                'data' => $result,
                'message' => '抽奖执行成功'
            ]);
        } catch (\Exception $e) {
            Log::error('手动执行抽奖失败', [
                'config_id' => $configId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'message' => '执行失败：' . $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'error' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * 获取抽奖记录列表
     */
    public function getLogs(Request $request)
    {
        try {
            $query = LotteryLog::with('config')
                ->orderBy('executed_at', 'desc');

            // 筛选条件
            if ($request->has('config_id') && $request->config_id) {
                $query->where('config_id', $request->config_id);
            }

            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            if ($request->has('start_date') && $request->start_date) {
                $query->whereDate('executed_at', '>=', $request->start_date);
            }

            if ($request->has('end_date') && $request->end_date) {
                $query->whereDate('executed_at', '<=', $request->end_date);
            }

            // 分页
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 20);
            $offset = ($page - 1) * $limit;

            $total = $query->count();
            $logs = $query->offset($offset)->limit($limit)->get();

            return response([
                'data' => [
                    'data' => $logs,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('获取抽奖记录失败', [
                'error' => $e->getMessage(),
                'params' => $request->all()
            ]);

            return response([
                'data' => [
                    'data' => [],
                    'total' => 0
                ],
                'message' => '获取记录失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取中奖记录列表
     */
    public function getWinners(Request $request)
    {
        try {
            $query = LotteryWinner::with(['user', 'lotteryLog.config'])
                ->orderBy('created_at', 'desc');

            // 筛选条件
            if ($request->has('user_id') && $request->user_id) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->has('reward_type') && $request->reward_type) {
                $query->where('reward_type', $request->reward_type);
            }

            if ($request->has('round_number') && $request->round_number) {
                $query->where('round_number', 'like', '%' . $request->round_number . '%');
            }

            if ($request->has('start_date') && $request->start_date) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }

            if ($request->has('end_date') && $request->end_date) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            // 分页
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 20);
            $offset = ($page - 1) * $limit;

            $total = $query->count();
            $winners = $query->offset($offset)->limit($limit)->get();

            return response([
                'data' => [
                    'data' => $winners,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('获取中奖记录失败', [
                'error' => $e->getMessage(),
                'params' => $request->all()
            ]);

            return response([
                'data' => [
                    'data' => [],
                    'total' => 0
                ],
                'message' => '获取记录失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取抽奖统计数据
     */
    public function getStatistics(Request $request)
    {
        try {
            Log::info('LotteryController::getStatistics 被调用');

            $configId = $request->get('config_id');

            // 使用服务获取抽奖统计
            $lotteryStats = $this->lotteryService->getStatistics($configId);

            // 获取今日统计
            $todayStats = [
                'today_lotteries' => \App\Models\LotteryLog::whereDate('executed_at', today())->count(),
                'today_winners' => \App\Models\LotteryWinner::whereDate('created_at', today())->count(),
                'today_rewards' => \App\Models\LotteryWinner::whereDate('created_at', today())->sum('reward_amount')
            ];

            // 获取配置统计
            $configStats = [
                'total_configs' => LotteryConfig::count(),
                'enabled_configs' => LotteryConfig::where('status', 1)->count(),
                'balance_configs' => LotteryConfig::where('reward_type', 'balance')->count(),
                'traffic_configs' => LotteryConfig::where('reward_type', 'traffic')->count()
            ];

            return response()->json([
                'data' => array_merge($lotteryStats, $todayStats, $configStats)
            ]);
        } catch (\Exception $e) {
            Log::error('获取抽奖统计失败', [
                'error' => $e->getMessage(),
                'params' => $request->all()
            ]);

            return response()->json([
                'data' => [],
                'message' => '获取统计失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取可执行的抽奖配置
     */
    public function getExecutableConfigs(Request $request)
    {
        try {
            Log::info('LotteryController::getExecutableConfigs 被调用');

            // 使用服务获取可执行的配置
            $configs = $this->lotteryService->checkExecutableConfigs();

            return response()->json([
                'data' => $configs
            ]);
        } catch (\Exception $e) {
            Log::error('获取可执行配置失败', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'data' => [],
                'message' => '获取失败：' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 发送中奖消息到Telegram群（控制器版本）
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
                $maskedEmail = $this->maskEmail($userEmail);
                $message .= ($index + 1) . ". {$maskedEmail}\n";
            }

            $message .= "\n🎊 恭喜以上用户！奖励已自动发放到账户。";

            // 发送消息
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
                return json_decode($result, true);
            }

            $errorMessage = curl_error($ch);
            curl_close($ch);

            Log::warning('Telegram消息发送失败，准备重试', [
                'attempt' => $attempt + 1,
                'http_code' => $httpCode,
                'error' => $errorMessage
            ]);

            if ($attempt < $retries - 1) {
                sleep(2);
            }
        }

        throw new \Exception("发送Telegram消息失败，已重试{$retries}次");
    }
}

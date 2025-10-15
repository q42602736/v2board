<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Utils\Helper;

class ResetSubscribeTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:subscribe-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重置所有用户的订阅token';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $expireMinutes = (int)config('v2board.subscribe_expire_minutes', 0);

        if ($expireMinutes <= 0) {
            // 功能未启用，静默退出
            return;
        }

        // 检查上次重置时间
        $lastResetKey = 'last_subscribe_token_reset';
        $lastReset = \Cache::get($lastResetKey, 0);
        $now = time();
        $expireSeconds = $expireMinutes * 60;

        // 如果还没到重置时间，退出
        if (($now - $lastReset) < $expireSeconds) {
            return;
        }

        $this->info("开始重置所有用户订阅token...");

        $users = User::all();
        $count = 0;

        foreach ($users as $user) {
            $user->token = Helper::guid();
            if ($user->save()) {
                $count++;
            }
        }

        // 记录本次重置时间
        \Cache::forever($lastResetKey, $now);

        $this->info("成功重置 {$count} 个用户的订阅token");
    }
}

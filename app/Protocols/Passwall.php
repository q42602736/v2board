<?php

namespace App\Protocols;

use App\Utils\Helper;

class Passwall
{
    public $flag = 'passwall';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $uri = '';

        foreach ($this->servers as $server) {
            $uri .= $this->buildUri($this->user['uuid'], $server);
        }
        return base64_encode($uri);
    }

    private function buildUri($uuid, $server)
    {
        $type = $server['type'];
        $method = "build" . ucfirst($type) . "Uri";

        if (method_exists(Helper::class, $method)) {
            return Helper::$method($uuid, $server);
        }

        return '';
    }

}

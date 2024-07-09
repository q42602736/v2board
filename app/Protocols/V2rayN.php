<?php

namespace App\Protocols;


use App\Utils\Helper;

class V2rayN
{
    public $flag = 'v2rayn';
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
            if ($server['type'] === 'vmess'){
                $uri .= self::buildVmess($this->user['uuid'], $server);
            } else {
                $uri .= Helper::buildUri($this->user['uuid'], $server);
            }
        }
        return base64_encode($uri);
    }

    public static function buildVmess($uuid, $server)
    {
        $config = [
            "v" => "2",
            "ps" => $server['name'],
            "add" => Helper::formatHost($server['host']),
            "port" => (string)$server['port'],
            "id" => $uuid,
            "aid" => '0',
            "scy" => 'auto',
            "net" => $server['network'],
            "type" => 'none',
            "host" => '',
            "path" => '',
            "tls" => $server['tls'] ? "tls" : "",
            "fp" => 'chrome',
        ];

        if ($server['tls']) {
            $tlsSettings = $server['tls_settings'] ?? $server['tlsSettings'] ?? [];
            $config['sni'] = $tlsSettings['server_name'] ?? $tlsSettings['serverName'] ?? '';
        }
        
        $network = (string)$server['network'];
        $networkSettings = $server['networkSettings'] ?? [];
    
        switch ($network) {
            case 'tcp':
                if (!empty($networkSettings['header']['type']) && $networkSettings['header']['type'] === 'http') {
                    $config['type'] = $networkSettings['header']['type'];
                    $config['host'] = $networkSettings['header']['request']['headers']['Host'][0] ?? null;
                    $config['path'] = $networkSettings['header']['request']['path'][0] ?? null;
                }
                break;
    
            case 'ws':
                $config['path'] = $networkSettings['path'] ?? null;
                $config['host'] = $networkSettings['headers']['Host'] ?? null;
                break;
    
            case 'grpc':
                $config['path'] = $networkSettings['serviceName'] ?? null;
                break;
            
            case 'quic':
                $config['host'] = $networkSettings['security'] ?? null;
                if (!empty($config['host'])) {
                    if (isset($networkSettings['key'])) {
                        $config['path'] = $networkSettings['key'];
                    }
                }
                $config['type'] = $networkSettings['header']['type'] ?? 'none';
                break;

            case 'kcp':
                if (isset($networkSettings['seed'])) {
                    $config['path'] = $networkSettings['seed'];
                }
                $config['type'] = $networkSettings['header']['type'] ?? 'none';
                break;

            case 'httpupgrade':
                $config['path'] = $networkSettings['path'] ?? null;
                $config['host'] = $networkSettings['headers']['Host'] ?? null;
                break;
            
            case 'splithttp':
                $config['path'] = $networkSettings['path'] ?? null;
                $config['host'] = $networkSettings['headers']['Host'] ?? null;
                break;
        }

        return "vmess://" . base64_encode(json_encode($config)) . "\r\n";
    }
}

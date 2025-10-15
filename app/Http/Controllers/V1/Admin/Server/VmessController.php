<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerVmessSave;
use App\Http\Requests\Admin\ServerVmessUpdate;
use App\Models\ServerVmess;
use Illuminate\Http\Request;

class VmessController extends Controller
{
    public function save(ServerVmessSave $request)
    {
        $params = $request->validated();

        // 处理XHTTP协议的类型转换
        if ($params['network'] == 'xhttp' && isset($params['networkSettings'])) {
            $ns = $params['networkSettings'];
            if (isset($ns['extra']) && is_array($ns['extra'])) {
                $extra = $ns['extra'];

                // 布尔值转换
                if (isset($extra['noGRPCHeader'])) {
                    $extra['noGRPCHeader'] = filter_var($extra['noGRPCHeader'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($extra['noSSEHeader'])) {
                    $extra['noSSEHeader'] = filter_var($extra['noSSEHeader'], FILTER_VALIDATE_BOOLEAN);
                }

                // 数字转换
                if (isset($extra['scMaxBufferedPosts'])) {
                    $extra['scMaxBufferedPosts'] = (int)$extra['scMaxBufferedPosts'];
                }

                // XMUX配置处理
                if (isset($extra['xmux']) && is_array($extra['xmux'])) {
                    $xmux = $extra['xmux'];
                    if (isset($xmux['hKeepAlivePeriod'])) {
                        $xmux['hKeepAlivePeriod'] = (int)$xmux['hKeepAlivePeriod'];
                    }
                    $extra['xmux'] = $xmux;
                }

                // 下载设置处理
                if (isset($extra['downloadSettings']) && is_array($extra['downloadSettings'])) {
                    $downloadSettings = $extra['downloadSettings'];
                    if (isset($downloadSettings['port'])) {
                        $downloadSettings['port'] = (int)$downloadSettings['port'];
                    }
                    if (isset($downloadSettings['host'])) {
                        $downloadSettings['host'] = (string)$downloadSettings['host'];
                    }
                    $extra['downloadSettings'] = $downloadSettings;
                }

                // 上传设置处理
                if (isset($extra['uploadSettings']) && is_array($extra['uploadSettings'])) {
                    $uploadSettings = $extra['uploadSettings'];
                    if (isset($uploadSettings['port'])) {
                        $uploadSettings['port'] = (int)$uploadSettings['port'];
                    }
                    if (isset($uploadSettings['host'])) {
                        $uploadSettings['host'] = (string)$uploadSettings['host'];
                    }
                    $extra['uploadSettings'] = $uploadSettings;
                }

                $ns['extra'] = $extra;
            }
            $params['networkSettings'] = $ns;
        }

        if ($request->input('id')) {
            $server = ServerVmess::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server->update($params);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        if (!ServerVmess::create($params)) {
            abort(500, '创建失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerVmess::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        return response([
            'data' => $server->delete()
        ]);
    }

    public function update(ServerVmessUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerVmess::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        try {
            $server->update($params);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerVmess::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, '服务器不存在');
        }
        if (!ServerVmess::create($server->toArray())) {
            abort(500, '复制失败');
        }

        return response([
            'data' => true
        ]);
    }
}

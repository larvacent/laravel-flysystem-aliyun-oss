<?php
/**
 * @copyright Copyright (c) 2018 Larva Information Technology Co., Ltd.
 * @link http://www.larvacent.com/
 * @license http://www.larvacent.com/license/
 */

namespace Larva\Flysystem\AliyunOss;

use OSS\OssClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

/**
 * 阿里云OSS服务提供者
 * @package XuTL\Flysystem\AliyunOss
 */
class AliyunOssServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        Storage::extend('oss', function ($app, $config) {
            $client = new OssClient(
                $config['access_id'],
                $config['access_key'],
                $config['endpoint'],
                $config['isCName'] ?? false,
                $config['securityToken'] ?? null,
                $config['proxy'] ?? null
            );
            $client->setTimeout($config['timeout'] ?? 3600);
            $client->setConnectTimeout($config['connectTimeout'] ?? 10);
            $client->setUseSSL($config['useSSL'] ?? false);
            $adapter = new AliyunOssAdapter(
                $client,
                $config['bucket'],
                $config['prefix'] ?? null
            );
            $filesystem = new Filesystem($adapter);
            return $filesystem;
        });
    }


    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}

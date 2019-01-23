<?php
/**
 * @copyright Copyright (c) 2018 Larva Information Technology Co., Ltd.
 * @link http://www.larvacent.com/
 * @license http://www.larvacent.com/license/
 */

namespace Larva\Flysystem\AliyunOss;

use Illuminate\Contracts\Filesystem\Filesystem;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\StreamedTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use OSS\Core\OssException;
use OSS\OssClient;
use RuntimeException;

/**
 * 阿里云适配器
 * @package XuTL\Flysystem\AliyunOss
 */
class AliyunOssAdapter extends AbstractAdapter
{

    use StreamedTrait;

    /**
     * Aliyun Oss Client.
     *
     * @var \OSS\OssClient
     */
    protected $client;

    /**
     * bucket name.
     *
     * @var string
     */
    protected $bucket;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var array
     */
    protected static $mappingOptions = [
        'mimetype' => OssClient::OSS_CONTENT_TYPE,
        'size' => OssClient::OSS_LENGTH,
        'filename' => OssClient::OSS_CONTENT_DISPOSTION,
        'headers' => OssClient::OSS_HEADERS,
        'Content-Length' => OssClient::OSS_CONTENT_LENGTH,
        'Content-Md5' => OssClient::OSS_CONTENT_MD5,
        'Content-Type' => OssClient::OSS_CONTENT_TYPE,
        'Content-Disposition'=>OssClient::OSS_CONTENT_DISPOSTION,
        'Cache-Control'=>OssClient::OSS_CACHE_CONTROL,
        'Expires'=>OssClient::OSS_EXPIRES,
        'x-oss-callback' => OssClient::OSS_CALLBACK,
        'x-oss-callback-var' => OssClient::OSS_CALLBACK_VAR,
    ];

    /**
     * Constructor.
     *
     * @param OssClient $client
     * @param string $bucket
     * @param string $prefix
     * @param array $options
     */
    public function __construct(OssClient $client, $bucket, $prefix = null, array $options = [])
    {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->setPathPrefix($prefix);
        $this->options = array_merge($this->options, $options);
    }

    /**
     * Get the Aliyun Oss Client bucket.
     *
     * @return string
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * Get the Aliyun Oss Client instance.
     *
     * @return \OSS\OssClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->getOptionsFromConfig($config);
        if (!isset($options[OssClient::OSS_LENGTH])) {
            $options[OssClient::OSS_LENGTH] = Util::contentSize($contents);
        }
        if (!isset($options[OssClient::OSS_CONTENT_TYPE])) {
            $options[OssClient::OSS_CONTENT_TYPE] = Util::guessMimeType($path, $contents);
        }
        try {
            $this->client->putObject($this->bucket, $object, $contents, $options);
        } catch (OssException $e) {
            return false;
        }
        $type = 'file';
        $result = compact('type', 'path', 'contents');
        $result['mimetype'] = $options[OssClient::OSS_CONTENT_TYPE];
        $result['size'] = $options[OssClient::OSS_LENGTH];
        return $result;
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     * @return bool
     */
    public function rename($path, $newpath)
    {
        if (!$this->copy($path, $newpath)) {
            return false;
        }
        return $this->delete($path);
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $object = $this->applyPathPrefix($path);
        $newobject = $this->applyPathPrefix($newpath);
        try {
            $this->client->copyObject($this->bucket, $object, $this->bucket, $newobject);
        } catch (OssException $e) {
            return false;
        }
        return true;
    }

    /**
     * Delete a file.
     *
     * @param string $path
     * @return bool
     */
    public function delete($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $this->client->deleteObject($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }
        return true;
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     * @return bool
     */
    public function deleteDir($dirname)
    {
        try {
            $list = $this->listContents($dirname, true);
            $objects = [];
            foreach ($list as $val) {
                if ($val['type'] === 'file') {
                    $objects[] = $this->applyPathPrefix($val['path']);
                } else {
                    $objects[] = $this->applyPathPrefix($val['path']) . '/';
                }
            }
            $this->client->deleteObjects($this->bucket, $objects);
        } catch (OssException $e) {
            return false;
        }
        return true;
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        $object = $this->applyPathPrefix($dirname);
        $options = $this->getOptionsFromConfig($config);
        try {
            $this->client->createObjectDir($this->bucket, $object, $options);
        } catch (OssException $e) {
            return false;
        }
        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        $location = $this->applyPathPrefix($path);
        try {
            $this->client->putObjectAcl(
                $this->bucket,
                $location,
                ($visibility == Filesystem::VISIBILITY_PUBLIC ) ? 'public-read' : 'private'
            );
        } catch (OssException $e) {
            return false;
        }
        return $this->getMetadata($path);
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     * @return array|bool|null
     */
    public function has($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $exists = $this->client->doesObjectExist($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }
        return $exists;
    }

    /**
     * Read a file.
     *
     * @param string $path
     * @return array|false
     */
    public function read($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $contents = $this->client->getObject($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }
        return compact('contents', 'path');
    }

    /**
     * 获取对象访问Url
     * @param string $path
     * @return string
     * @throws \OSS\Core\OssException
     */
    public function getUrl($path)
    {
        $location = $this->applyPathPrefix($path);
        if (($this->client->getObjectAcl($this->bucket, $location)) == 'private') {
            throw new RuntimeException('This object does not support retrieving URLs.');
        }
        //SDK未提供获取 hostname的公开方法，这里变相获取
        $temporaryUrl = $this->getTemporaryUrl($path, now()->addMinutes(60), []);
        $urls = parse_url($temporaryUrl);
        return $urls['scheme'] .'://'. $urls['host'] . $urls['path'];
    }

    /**
     * 获取文件临时访问路径
     * @param $path
     * @param $expiration
     * @param $options
     * @return string
     * @throws \OSS\Core\OssException
     */
    public function getTemporaryUrl($path, $expiration, $options)
    {
        $location = $this->applyPathPrefix($path);
        $timeout = $expiration->getTimestamp() - time();
        $temporaryUrl = $this->client->signUrl($this->bucket, $location, $timeout, OssClient::OSS_HTTP_GET, $options);
        return $temporaryUrl;
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool $recursive
     * @return array
     * @throws OssException
     */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = rtrim($this->applyPathPrefix($directory), '\\/');
        if ($directory) $directory .= '/';
        $bucket = $this->bucket;
        $delimiter = '/';
        $nextMarker = '';
        $maxKeys = 1000;
        $options = [
            'delimiter' => $delimiter,
            'prefix' => $directory,
            'max-keys' => $maxKeys,
            'marker' => $nextMarker,
        ];
        $listObjectInfo = $this->client->listObjects($bucket, $options);
        $objectList = $listObjectInfo->getObjectList(); // 文件列表
        $prefixList = $listObjectInfo->getPrefixList(); // 目录列表
        $result = [];
        foreach ($objectList as $objectInfo) {
            if ($objectInfo->getSize() === 0 && $directory === $objectInfo->getKey()) {
                $result[] = [
                    'type' => 'dir',
                    'path' => $this->removePathPrefix(rtrim($objectInfo->getKey(), '/')),
                    'timestamp' => strtotime($objectInfo->getLastModified()),
                ];
                continue;
            }
            $result[] = [
                'type' => 'file',
                'path' => $this->removePathPrefix($objectInfo->getKey()),
                'timestamp' => strtotime($objectInfo->getLastModified()),
                'size' => $objectInfo->getSize(),
            ];
        }
        foreach ($prefixList as $prefixInfo) {
            if ($recursive) {
                $next = $this->listContents($this->removePathPrefix($prefixInfo->getPrefix()), $recursive);
                $result = array_merge($result, $next);
            } else {
                $result[] = [
                    'type' => 'dir',
                    'path' => $this->removePathPrefix(rtrim($prefixInfo->getPrefix(), '/')),
                    'timestamp' => 0,
                ];
            }
        }
        return $result;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     * @return array|false
     */
    public function getMetadata($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $result = $this->client->getObjectMeta($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }
        return [
            'type' => 'file',
            'dirname' => Util::dirname($path),
            'path' => $path,
            'timestamp' => strtotime($result['last-modified']),
            'mimetype' => $result['content-type'],
            'size' => $result['content-length'],
        ];
    }

    /**
     * Get the size of a file.
     *
     * @param string $path
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     * @return array|false
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the last modified time of a file as a timestamp.
     *
     * @param string $path
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path
     * @return array|false
     */
    public function getVisibility($path)
    {
        $location = $this->applyPathPrefix($path);
        try {
            $response = $this->client->getObjectAcl($this->bucket, $location);
        } catch (OssException $e) {
            return false;
        }
        return [
            'visibility' => $response,
        ];
    }

    /**
     * Get options from the config.
     *
     * @param Config $config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = $this->options;

        if ($visibility = $config->get('visibility')) {
            // For local reference
            $options['visibility'] = $visibility;
            // For external reference
            $options['headers']['x-oss-object-acl'] = $visibility === Filesystem::VISIBILITY_PUBLIC ? 'public-read' : 'private';
        }

        if ($mimetype = $config->get('mimetype')) {
            // For local reference
            $options['mimetype'] = $mimetype;
            // For external reference
            $options['ContentType'] = $mimetype;
        }

        foreach (static::$mappingOptions as $option) {
            if (!$config->has($option)) {
                continue;
            }
            $options[$option] = $config->get($option);
        }

        return $options;
    }
}

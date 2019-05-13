<?php
/**
 * Created by PhpStorm.
 * User: 白猫
 * Date: 2019/5/8
 * Time: 11:08
 */

namespace GoSwoole\Plugins\Cache\Aspect;

use Go\Aop\Aspect;
use Go\Aop\Intercept\MethodInvocation;
use Go\Lang\Annotation\Around;
use GoSwoole\BaseServer\Plugins\Logger\GetLogger;
use GoSwoole\BaseServer\Server\Server;
use GoSwoole\Plugins\Cache\Annotation\Cacheable;
use GoSwoole\Plugins\Cache\Annotation\CacheEvict;
use GoSwoole\Plugins\Cache\Annotation\CachePut;
use GoSwoole\Plugins\Cache\CacheConfig;
use GoSwoole\Plugins\Cache\CacheStorage;
use GoSwoole\Plugins\Redis\GetRedis;

/**
 * Caching aspect
 */
class CachingAspect implements Aspect
{
    use GetLogger;
    use GetRedis;
    /**
     * @var CacheStorage
     */
    private $cacheStorage;


    /**
     * @var CacheConfig
     */
    private $config;

    public function __construct(CacheStorage $cacheStorage)
    {
        $this->cacheStorage = $cacheStorage;
        $this->config = Server::$instance->getContainer()->get(CacheConfig::class);
    }


    /**
     *
     * @param MethodInvocation $invocation Invocation
     *
     * @Around("@execution(GoSwoole\Plugins\Cache\Annotation\Cacheable)")
     * @return mixed
     */
    public function aroundCacheable(MethodInvocation $invocation)
    {
        $obj = $invocation->getThis();
        $class = is_object($obj) ? get_class($obj) : $obj;
        $cacheable = $invocation->getMethod()->getAnnotation(Cacheable::class);
        //初始化计算环境
        $p = $invocation->getArguments();
        //计算key
        $key = eval("return (" . $cacheable->key . ");");
        if (empty($key)) {
            $this->warn("cache key is empty,ignore this cache");
            //执行
            return $invocation->proceed();
        } else {
            $this->debug("cache get namespace:{$cacheable->namespace} key:{$key}");
            //计算condition
            $condition = true;
            if (!empty($cacheable->condition)) {
                $condition = eval("return (" . $cacheable->condition . ");");
            }
            $data = null;
            $data = $this->getCache($key, $cacheable);
            //获取到缓存就返回
            if ($data != null) {
                $this->debug("cache Hit!");
                return serverUnSerialize($data);
            }


            if($this->config->getLockTimeout() > 0 && $condition){
                if($this->config->getLockAlive() < $this->config->getLockTimeout()){
                    $this->alert('cache 缓存配置项 lockAlive 必须大于 lockTimeout, 请立即修正参数');
                }

                if($token = $this->cacheStorage->lock($key, $this->config->getLockAlive())){
                    $result = $invocation->proceed();
                    $data = serverSerialize($result);
                    $this->setCache($key, $data, $cacheable);
                    $this->cacheStorage->unlock($key, $token);

                }else{
                    $i = 0;
                    do{
                        $result = $this->getCache($key, $cacheable);
                        if($result) break;
                        usleep($this->config->getLockWait() * 1000);
                        $i += $this->config->getLockWait();
                        if($i >= $this->config->getLockTimeout()){
                            $this->warn('lock wait timeout ' . $key .','. $i);
                            break;
                        }else{
                            $this->debug('lock wait ' . $key .','. $i);
                        }
                    }while($i <= $this->config->getLockTimeout());
                }
            }else{
                $result = $invocation->proceed();
            }
        }
        return $result;
    }




    /**
     * This advice intercepts an execution of cachePut methods
     *
     * Logic is pretty simple: we look for the value in the cache and if it's not present here
     * then invoke original method and store it's result in the cache.
     *
     * Real-life examples will use APC or Memcache to store value in the cache
     *
     * @param MethodInvocation $invocation Invocation
     *
     * @Around("@execution(GoSwoole\Plugins\Cache\Annotation\CachePut)")
     * @return mixed
     */
    public function aroundCachePut(MethodInvocation $invocation)
    {
        $obj = $invocation->getThis();
        $class = is_object($obj) ? get_class($obj) : $obj;
        $cachePut = $invocation->getMethod()->getAnnotation(CachePut::class);
        //初始化计算环境
        $p = $invocation->getArguments();
        //计算key
        $key = eval("return (" . $cachePut->key . ");");
        if (empty($key)) {
            $this->warn("cache key is empty,ignore this cache");
            //执行
            $result = $invocation->proceed();
        } else {
            $this->debug("cache put namespace:{$cachePut->namespace} key:{$key}");

            //计算condition
            $condition = true;
            if (!empty($cachePut->condition)) {
                $condition = eval("return (" . $cachePut->condition . ");");
            }
            //执行
            $result = $invocation->proceed();
            //可以缓存就缓存
            if ($condition) {
                $data = serverSerialize($result);
                if (empty($cachePut->namespace)) {
                    $this->cacheStorage->set($key, $data, $cachePut->time);
                } else {
                    $this->cacheStorage->setFromNameSpace($cachePut->namespace, $key, $data);
                }
            }
        }
        return $result;
    }

    /**
     * This advice intercepts an execution of cacheEvict methods
     *
     * Logic is pretty simple: we look for the value in the cache and if it's not present here
     * then invoke original method and store it's result in the cache.
     *
     * Real-life examples will use APC or Memcache to store value in the cache
     *
     * @param MethodInvocation $invocation Invocation
     *
     * @Around("@execution(GoSwoole\Plugins\Cache\Annotation\CacheEvict)")
     * @return mixed
     */
    public function aroundCacheEvict(MethodInvocation $invocation)
    {
        $obj = $invocation->getThis();
        $class = is_object($obj) ? get_class($obj) : $obj;
        $cacheEvict = $invocation->getMethod()->getAnnotation(CacheEvict::class);
        //初始化计算环境
        $p = $invocation->getArguments();
        //计算key
        $key = eval("return (" . $cacheEvict->key . ");");
        if (empty($key)) {
            $this->warn("cache key is empty,ignore this cache");
            //执行
            $result = $invocation->proceed();
        } else {
            $this->debug("cache evict namespace:{$cacheEvict->namespace} key:{$key}");
            $result = null;
            if ($cacheEvict->beforeInvocation) {
                //执行
                $result = $invocation->proceed();
            }
            if (empty($cacheEvict->namespace)) {
                $this->cacheStorage->remove($key);
            } else {
                if ($cacheEvict->allEntries) {
                    $this->cacheStorage->removeNameSpace($cacheEvict->namespace);
                } else {
                    $this->cacheStorage->removeFromNameSpace($cacheEvict->namespace, $key);
                }
            }
            if (!$cacheEvict->beforeInvocation) {
                //执行
                $result = $invocation->proceed();
            }
        }
        return $result;
    }

    public function getCache($key,Cacheable $cacheable){
        if (empty($cacheable->namespace)) {
            $data = $this->cacheStorage->get($key);
        } else {
            $data = $this->cacheStorage->getFromNameSpace($cacheable->namespace, $key);
        }
        return $data;
    }

    public function setCache($key, $data,Cacheable $cacheable): void
    {

        if (empty($cacheable->namespace)) {
            $ret = $this->cacheStorage->set($key, $data, $cacheable->time);
        } else {
            $ret = $this->cacheStorage->setFromNameSpace($cacheable->namespace, $key, $data);
        }

        if(!$ret){
            $this->warn('cache key:'.$key.' set fail ');
        }
    }
}

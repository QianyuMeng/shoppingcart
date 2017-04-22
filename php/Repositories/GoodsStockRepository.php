<?php
/*
 * 商品库存Repository
 * @author mengqianyu <mqycn@sina.cn> 
 * @version 1.0
 * ================================
 */

namespace Repositories;

use Exception;

class GoodsStockRepository
{
    const STOCK_LOCK_COUNT_HASH = 'GOODS_STOCK_LOCK';//redis hash,记录商品的总锁定数量
    const STOCK_LOCK_COUNT_LOG_HASH = 'GOODS_STOCK_LOCK_COUNT_LOG'; //redis hash,记录商品锁定数量用户日志，用于过期自动释放锁定库存
    const STOCK_LOCK_TIME_LOG_SET = 'GOODS_STOCK_LOCK_TIME_LOG'; //redis sorted set,记录商品锁定持续时间用户日志，用于过期自动释放锁定库存
    const LOCK_TIME = 1800;

    //旧版活动商品库存，来自ActivityPanicService
    const REDIS_ACTIVITY_GOODS_NUM = 'com.juanpi.in.activity.goods.sale';
    //商品数据缓存redis key 有效期，来自ActivityPanicService
    const REDIS_ACTIVITY_PANIC_KEY_EXPIRE = 60 * 24 * 3600;

    private $_redis;
    private $_lastError;

    public function __construct($redis)
    {
        $this->_lastError = '';
        $this->_redis = $redis;
    }

    /**
     * 锁定商品库存
     * @param $skuId int
     * @param $uid int
     * @param $count int 锁定库存数量
     * @param $time int 库存锁定过期时间（时间戳）
     * @return boolean
     */
    public function lock($skuId, $uid, $count, $time = 0)
    {
        $time = $time ? $time : time() + self::LOCK_TIME;
        $key = $this->getLogKey($skuId, $uid);
        try {
            $this->_redis->multi()
                ->hIncrBy(self::STOCK_LOCK_COUNT_HASH, $skuId, $count)
                ->zAdd(self::STOCK_LOCK_TIME_LOG_SET, $time, $key)
                ->hIncrBy(self::STOCK_LOCK_COUNT_LOG_HASH, $key, $count)
                ->exec();
        } catch (Exception $e) {
            $this->_lastError = 'Exception';
            return false;
        }
        return true;
    }

    /**
     * 解除锁定商品库存
     * @param $skuId int
     * @param $uid int
     * @param $count int 解除锁定库存数量
     * @return boolean
     */
    public function unlock($skuId, $uid, $count)
    {
        $key = $this->getLogKey($skuId, $uid);
        try {
            $ret = $this->_redis->multi()
                ->hIncrBy(self::STOCK_LOCK_COUNT_HASH, $skuId, -$count)
                ->hIncrBy(self::STOCK_LOCK_COUNT_LOG_HASH, $key, -$count)
                ->zRem(self::STOCK_LOCK_TIME_LOG_SET, $key)
                ->exec();
            if ($ret[0] <= 0) {
                $this->_redis->hDel(self::STOCK_LOCK_COUNT_HASH, $skuId);
            }
            if ($ret[1] <= 0) {
                $this->_redis->hDel(self::STOCK_LOCK_COUNT_LOG_HASH, $key);
            }
        } catch (Exception $e) {
            $this->_lastError = 'Exception';
            return false;
        }
        return true;
    }

    /**
     * 获取某商品锁定库存的数量
     * @param $skuId int
     * @return int/false
     */
    public function getLockCount($skuId)
    {
        try {
            $count = $this->_redis->hGet(self::STOCK_LOCK_COUNT_HASH, $skuId);
        } catch (Exception $e) {
            $this->_lastError = 'Exception';
            return false;
        }
        return intval($count);
    }

    /**
     * 批量获取商品锁定库存的数量
     * @param $skuIds array
     * @return array/false
     */
    public function getLockCounts(array $skuIds)
    {
        try {
            $counts = $this->_redis->hMget(self::STOCK_LOCK_COUNT_HASH, $skuIds);
        } catch (Exception $e) {
            $this->_lastError = 'Exception';
            return false;
        }
        return $counts;
    }


    /**
     * 更新库存锁定的过期时间
     * @param $skuId int
     * @param $uid int
     * @param $time int 库存锁定过期时间（时间戳）
     * @return boolean
     */
    public function updateLockTime($skuId, $uid, $time = 0)
    {
        $time = $time ? $time : time() + self::LOCK_TIME;
        $key = $this->getLogKey($skuId, $uid);
        try {
            $this->_redis->zAdd(self::STOCK_LOCK_TIME_LOG_SET, $time, $key);//redis sorted set
        } catch (Exception $e) {
            $this->_lastError = 'Exception';
            return false;
        }
        return true;
    }


    /**
     * 获取key
     * @param $skuId int
     * @param $uid int
     * @return string
     */
    public function getLogKey($skuId, $uid)
    {
        return intval($skuId) . '.' . intval($uid);
    }


    /**
     * 获取错误信息
     * @return string
     */
    public function getLastError()
    {
        return $this->_lastError;
    }

    /**
     * 释放已过期的库存锁定(用于计划任务)
     * @param $time int 库存锁定过期时间（时间戳）
     * @param $limit int
     * @return boolean
     */
    public function unlockExpired($time = 0, $limit = 1000)
    {
        $time = $time ? $time : time();
        $isMore = true;
        while ($isMore) {
            $isMore = $this->_unlockExpired($time, $limit);
        }

    }

    /**
     * 释放已过期的库存锁定(用于计划任务)
     * @param $time int 库存锁定过期时间（时间戳）
     * @param $limit int
     * @return boolean
     */
    private function _unlockExpired($time, $limit)
    {
        $data = $this->_redis->zRangeByScore(self::STOCK_LOCK_TIME_LOG_SET, 0, $time, ['limit' => [0, $limit]]);
        $isMore = count($data) == $limit ? true : false;
        foreach ($data as $key) {
            $skuId = explode('.', $key);
            $skuId = $skuId[0];
            $count = $this->_redis->hGet(self::STOCK_LOCK_COUNT_LOG_HASH, $key);
            $ret = $this->_redis->multi()
                ->hIncrBy(self::STOCK_LOCK_COUNT_HASH, $skuId, -$count)
                ->hDel(self::STOCK_LOCK_COUNT_LOG_HASH, $key)
                ->zRem(self::STOCK_LOCK_TIME_LOG_SET, $key)
                ->exec();
            if ($ret[0] <= 0) {
                $this->_redis->hDel(self::STOCK_LOCK_COUNT_HASH, $skuId);
            }
        }
        return $isMore;

    }


    /**
     * 活动商品 - 锁库存
     * 取自ActivityPanicService
     * @param $scheduleId
     * @param $goodsId
     * @param $num
     * @return int
     */
    public function lockScheduleGoodsStock($scheduleId, $goodsId, $num)
    {
        $redis = RedisHandle::getInstance(RedisHandle::_configMapi)->redis();
        $key = static::REDIS_ACTIVITY_GOODS_NUM . '.' . $goodsId . '-' . $scheduleId;
        $num = $redis->incrBy($key, $num);
        //防止过期,所以不停的刷新时间
        $redis->expire($key, self::REDIS_ACTIVITY_PANIC_KEY_EXPIRE);
        return intval($num);
    }


    /**
     * 活动商品 - 释放库存
     * 取自ActivityPanicService
     * @param $scheduleId
     * @param $goodsId int
     * @param $num int
     * @return int
     */
    public function unlockScheduleGoodsStock($scheduleId, $goodsId, $num)
    {
        $redis = RedisHandle::getInstance(RedisHandle::_configMapi)->redis();
        $key = static::REDIS_ACTIVITY_GOODS_NUM . '.' . $goodsId . '-' . $scheduleId;
        $num = $redis->decrBy($key, $num);
        $redis->expire($key, self::REDIS_ACTIVITY_PANIC_KEY_EXPIRE);
        return intval($num);
    }
}

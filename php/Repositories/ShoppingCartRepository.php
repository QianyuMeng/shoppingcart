<?php
/**
 * 购物车Repository
 * @author mengqianyu <mqycn@sina.cn> 
 * @version 1.0
 * ================================
 */

namespace Repositories;

use Exception;

class ShoppingCartRepository 
{
	const CART_DEADLINE_SET = 'SHOPPING_CART_DEADLINE';//redis sorted set,用于记录购物车的过期时间
	const SKUS_PREFIX = 'CART_SKUS_';
	const INFOS_PREFIX = 'CART_INFOS_';
	const SKUS_HISTORY_PREFIX = 'CART_SKUS_HISTORY_';
	const INFOS_HISTORY_PREFIX = 'CART_INFOS_HISTORY_';
	const CART_EFFECTIVE_TIME = 1800; //购物车有效时间30分钟 
	const CART_EFFECTIVE = 1; //购物车有效 
	const CART_NOEFFECTIVE = 2; //购物车失效 

	private $_redis;
	private $_lastError;

	public function __construct($redis)
	{
		$this->_lastError = '';
		$this->_redis = $redis;
	}

	/**
	 * 添加商品到购物车
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @param $goodsId int
	 * @param $count int 购买数量
	 * @param $activityId int
	 * @param $scheduleId int
	 * @return boolean
	 */
	public function addItem($uid, $country, $skuId, $goodsId, $count, $activityId, $scheduleId)
	{
		$time = time();
		$skusKey = $this->getSkusKey($uid, $country);
		$infosKey = $this->getInfosKey($uid, $country);
		$data = ['goodsId_'.$skuId => $goodsId, 'activityId_'.$skuId => $activityId, 'scheduleId_'.$skuId => $scheduleId];
		try {
			$isExists = $this->_redis->hExists($infosKey, 'count_'.$skuId);
			$ret = $this->_redis->multi()
						 ->zAdd($skusKey, $time, $skuId)
						 ->hMset($infosKey, $data)
						 ->hIncrBy($infosKey, 'count_'.$skuId, $count)
						 ->exec();
			if ($ret[0] && !$isExists) {
				$this->_addDeadline($uid, $country);
			}
		} catch (Exception $e) {
			return false;
		}
		return true;
	}

	/**
	 * 删除购物车中的商品 
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @return boolean
	 */
	public function delItem($uid, $country, $skuId)
	{
		$skusKey = $this->getSkusKey($uid, $country);
		$infosKey = $this->getInfosKey($uid, $country);	
		try {
			$this->_redis->multi()
						 ->zRem($skusKey, $skuId)
						 ->hDel($infosKey, 'goodsId_'.$skuId, 'count_'.$skuId, 'activityId_'.$skuId, 'scheduleId_'.$skuId)
						 ->exec();
		} catch (Exception $e) {
			return false;
		}
		return true;
	}
	
	/**
	 * 增加购物车某sku的数量
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @param $count int
	 * @return int/false
	 */
	public function increSkuCount($uid, $country, $skuId, $count=1)
	{
		$infosKey = $this->getInfosKey($uid, $country);	
		try {
			$ret = $this->_redis->hIncrBy($infosKey, 'count_'.$skuId, $count);
		} catch (Exception $e) {
			return false;
		}
		return $ret;
	}

	/**
	 * 获取购物车某sku的数量
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @return int/false
	 */
	public function getSkuCount($uid, $country, $skuId)
	{
		$infosKey = $this->getInfosKey($uid, $country);	
		try {
			$ret = $this->_redis->hGet($infosKey, 'count_'.$skuId);
		} catch (Exception $e) {
			return false;
		}
		return intval($ret);
	}

	/**
	 * 判断购物车中是否有某sku
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @return int/false
	 */
	public function isExists($uid, $country, $skuId)
	{
		$infosKey = $this->getInfosKey($uid, $country);	
		try {
			return $this->_redis->hExists($infosKey, 'count_'.$skuId);
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * 获取购物车详细信息列表
	 * @param $uid int 
	 * @param $country int
	 * @param $isRevert boolean 是否按加入时间降序排序	 
	 * @return array/false
	 */
	public function getItems($uid, $country, $isRevert=true)
	{
		$skusKey = $this->getSkusKey($uid, $country);
		$infosKey = $this->getInfosKey($uid, $country);
		try {
			$list = $isRevert ? $this->_redis->zRevRange($skusKey, 0, -1, true) : $this->_redis->zRange($skusKey, 0, -1, true);	
			$infos = $this->_redis->hGetAll($infosKey); 
		} catch (Exception $e) {
			return false;
		}
		foreach ($list as $key => $val) {
			$temp['skuId'] = $key;	
			$temp['goodsId'] = !empty($infos['goodsId_'.$key]) ? intval($infos['goodsId_'.$key]) : 0;	
			$temp['count'] = !empty($infos['count_'.$key]) ? intval($infos['count_'.$key]) : 0;	
			$temp['activityId'] = !empty($infos['activityId_'.$key]) ? intval($infos['activityId_'.$key]) : 0;	
			$temp['scheduleId'] = !empty($infos['scheduleId_'.$key]) ? intval($infos['scheduleId_'.$key]) : 0;	
			$temp['addTime'] = intval($val);	
			$list[$key] = $temp;
		}
		return $list;
	}

	/**
	 * 获取购物车某商品的购买数量
	 * @param $uid int 
	 * @param $country int
	 * @param $goodsId int	 
	 * @return int
	 */
	public function getGoodsCount($uid, $country, $goodsId)
	{
		$skusKey = $this->getSkusKey($uid, $country);
		$infosKey = $this->getInfosKey($uid, $country);
		try {
			$skuIds = $this->_redis->zRange($skusKey, 0, -1);	
			$infos = $this->_redis->hGetAll($infosKey); 
		} catch (Exception $e) {
			return false;
		}
		$count = 0;
		$goodsId = intval($goodsId);
		foreach ($skuIds as $skuId) {
			$gid = !empty($infos['goodsId_'.$skuId]) ? intval($infos['goodsId_'.$skuId]) : 0;
			$count += $goodsId == $gid ? $infos['count_'.$skuId] : 0;
		}
		return $count;
	}
	
	/**
	 * 获取购物车单行数据
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @return array/false
	 */
	public function getItem($uid, $country, $skuId)
	{
		$infosKey = $this->getInfosKey($uid, $country);
		try {
			$infos = $this->_redis->hMget($infosKey, ['goodsId_'.$skuId, 'count_'.$skuId, 'activityId_'.$skuId, 'scheduleId_'.$skuId]); 
		} catch (Exception $e) {
			return false;
		}
		$data['goodsId'] = !empty($infos['goodsId_'.$skuId]) ? intval($infos['goodsId_'.$skuId]) : 0;	
		$data['count'] = !empty($infos['count_'.$skuId]) ? intval($infos['count_'.$skuId]) : 0;	
		$data['activityId'] = !empty($infos['activityId_'.$skuId]) ? intval($infos['activityId_'.$skuId]) : 0;	
		$data['scheduleId'] = !empty($infos['scheduleId_'.$skuId]) ? intval($infos['scheduleId_'.$skuId]) : 0;
		return $data;
	}

	/**
	 * 获取购物车skuid列表
	 * @param $uid int 
	 * @param $country int
	 * @param $isRevert boolean 是否按加入时间降序排序	 
	 * @return array/false
	 */
	public function getSkuIds($uid, $country, $isRevert=true)
	{
		$skusKey = $this->getSkusKey($uid, $country);
		try {
			$skuIds = $isRevert ? $this->_redis->zRevRange($skusKey, 0, -1) : $this->_redis->zRange($skusKey, 0, -1);
		} catch (Exception $e) {
			return false;
		}
		return $skuIds;
	}


	/**
	 * 获取购物车skuid列表
	 * @param $uid int 
	 * @param $country int
	 * @param $goodsId int
	 * @return array/false
	 */
	public function getSkuIdsByGoodsId($uid, $country, $goodsId)
	{
		$skusKey = $this->getSkusKey($uid, $country);
		$infosKey = $this->getInfosKey($uid, $country);
		try {
			$skuIds = $this->_redis->zRange($skusKey, 0, -1);	
			$infos = $this->_redis->hGetAll($infosKey); 
		} catch (Exception $e) {
			return false;
		}
		$ret = [];
		$goodsId = intval($goodsId);
		foreach ($skuIds as $skuId) {
			$gid = !empty($infos['goodsId_'.$skuId]) ? intval($infos['goodsId_'.$skuId]) : 0;
			if ($goodsId == $gid) {
				$ret[] = $skuId;
			}
		}
		return $ret;
	}

	/**
	 * 更新购物车生效时间
	 * @param $uid int 
	 * @param $country int
	 * @param $time int
	 * @return boolean
	 */
	public function updateEffectiveTime($uid, $country, $time = 0)
	{
		$time = $time ? $time : time() + self::CART_EFFECTIVE_TIME;
		try {
			$this->_addDeadline($uid, $country, $time);
		} catch (Exception $e) {
			return false;
		}
		return true;	
	}

	/**
	 * 获取购物车有效时间
	 * @param $uid int 
	 * @param $country int
	 * @return int/false 单位为毫秒
	 */
	public function getEffectiveTime($uid, $country)
	{
		try {
			$deadline = $this->_getDeadline($uid, $country);	
		} catch (Exception $e) {
			return false;
		}
		$leftTime = $deadline - time();
		if ($leftTime <= 0) {
			//$this->toHistory($uid, $country);
			return 0;
		}
		return  $leftTime * 1000 ;
	}

	/**
	 * 设置购物车优惠券信息
	 * @param $uid int 
	 * @param $country int
	 * @param $couponId int
	 * @param $couponAmount int
	 * @return boolean
	 */
	public function setCoupon($uid, $country, $couponId, $couponAmount)
	{
		$infosKey = $this->getInfosKey($uid, $country);
		try {
			$this->_redis->hMset($infosKey, ['couponId' => $couponId, 'couponAmount' => $couponAmount]);
		} catch (Exception $e) {
			return false;
		}
		return true;	
	}

	/**
	 * 获取购物车优惠券信息
	 * @param $uid int 
	 * @param $country int
	 * @return array/false
	 */
	public function getCoupon($uid, $country)
	{
		$infosKey = $this->getInfosKey($uid, $country);
		try {
			$ret = $this->_redis->hMget($infosKey, ['couponId', 'couponAmount']);
		} catch (Exception $e) {
			return false;
		}
		return $ret;	
	}


	/**
	 * 获取购物车状态（是否失效）
	 * @param $uid int 
	 * @param $country int
	 * @return int/false 1:有效,2:失效,false:服务器出错 
	 */
	public function getStatus($uid, $country)
	{
		try {
			$deadline = $this->_getDeadline($uid, $country);	
		} catch (Exception $e) {
			return false;
		}
		return $deadline > time() ? self::CART_EFFECTIVE : self::CART_NOEFFECTIVE;
	}

	/**
	 * 获取购物车不同sku的数量
	 * @param $uid int 
	 * @param $country int
	 * @return int/false
	 */
	public function getItemTotal($uid, $country)
	{
		$skusKey = $this->getSkusKey($uid, $country);
		try {
			$ret = $this->_redis->zCard($skusKey);	
		} catch (Exception $e) {
			return false;
		}
		return $ret;
	}

	/**
	 * 获取购物车所有sku总数 
	 * @param $uid int 
	 * @param $country int
	 * @return int/false
	 */
	public function getSkuTotal($uid, $country)
	{
		$skusKey = $this->getSkusKey($uid, $country);
		$infosKey = $this->getInfosKey($uid, $country);
		try {
			$list = $this->_redis->zRange($skusKey, 0, -1);	
			$infos = $this->_redis->hGetAll($infosKey); 
		} catch (Exception $e) {
			return false;
		}
		$total = 0;
		foreach ($list as $skuId) {
			$total += !empty($infos['count_'.$skuId]) ? intval($infos['count_'.$skuId]) : 0;	
		}
		return $total;
	}
	
	/**
	 * 当前购物车商品转为历史商品
	 * @param $uid int 
	 * @param $country int
	 * @return boolean
	 */
	public function toHistory($uid, $country)
	{
		$skusKey = $this->getSkusKey($uid, $country);
		$infosKey = $this->getInfosKey($uid, $country);
		$skusHistoryKey = $this->getSkusHistoryKey($uid, $country);
		$infosHistoryKey = $this->getInfosHistoryKey($uid, $country);
		$deadlineKey = $this->getDeadlineKey($uid, $country);
		try {
			$this->_redis->multi()
						 ->rename($skusKey, $skusHistoryKey)
						 ->rename($infosKey, $infosHistoryKey)
						 ->zRem(self::CART_DEADLINE_SET, $deadlineKey)
						 ->exec();
		} catch (Exception $e) {
			return false;
		}
		return true;
	}

	/**
	 * 获取购物车详细历史信息列表
	 * @param $uid int 
	 * @param $country int
	 * @param $isRevert boolean 是否按加入时间降序排序	 
	 * @return array/false
	 */
	public function getHistoryItems($uid, $country, $isRevert=true)
	{
		$skusHistoryKey = $this->getSkusHistoryKey($uid, $country);
		$infosHistoryKey = $this->getInfosHistoryKey($uid, $country);
		try {
			$list = $isRevert ? $this->_redis->zRevRange($skusHistoryKey, 0, -1, true) : $this->_redis->zRange($skusHistoryKey, 0, -1, true);	
			$infos = $this->_redis->hGetAll($infosHistoryKey); 
		} catch (Exception $e) {
			return false;
		}
		foreach ($list as $key => $val) {
			$temp['skuId'] = $key;	
			$temp['goodsId'] = !empty($infos['goodsId_'.$key]) ? intval($infos['goodsId_'.$key]) : 0;	
			$temp['count'] = !empty($infos['count_'.$key]) ? intval($infos['count_'.$key]) : 0;	
			$temp['activityId'] = !empty($infos['activityId_'.$key]) ? intval($infos['activityId_'.$key]) : 0;	
			$temp['scheduleId'] = !empty($infos['scheduleId_'.$key]) ? intval($infos['scheduleId_'.$key]) : 0;
			$temp['addTime'] = intval($val);	
			$list[$key] = $temp;
		}
		return $list;
	}

	/**
	 * 获取购物车历史skuid列表
	 * @param $uid int 
	 * @param $country int
	 * @param $isRevert boolean 是否按加入时间降序排序	 
	 * @return array/false
	 */
	public function getHistorySkuIds($uid, $country, $isRevert=true)
	{
		$skusHistoryKey = $this->getSkusHistoryKey($uid, $country);
		try {
			$ret = $isRevert ? $this->_redis->zRevRange($skusHistoryKey, 0, -1) : $this->_redis->zRange($skusHistoryKey, 0, -1);
		} catch (Exception $e) {
			return false;
		}
		return $ret;
	}

	/**
	 * 删除购物车历史中的商品 
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @return boolean
	 */
	public function delHistoryItem($uid, $country, $skuId)
	{
		$skusHistoryKey = $this->getSkusHistoryKey($uid, $country);
		$infosHistoryKey = $this->getInfosHistoryKey($uid, $country);	
		try {
			$this->_redis->multi()
						 ->zRem($skusHistoryKey, $skuId)
						 ->hDel($infosHistoryKey, 'goodsId_'.$skuId,'count_'.$skuId)
						 ->exec();
		} catch (Exception $e) {
			return false;
		}
		return true;
	}

	/**
	 * 清空购物车商品
	 * @param $uid int 
	 * @param $country int
	 * @return boolean
	 */
	public function clear($uid, $country)
	{
		$skusKey = $this->getSkusKey($uid, $country);
		$infosKey = $this->getInfosKey($uid, $country);
		$deadlineKey = $this->getDeadlineKey($uid, $country);
		try {	
			$this->_redis->multi()
						 ->del($skusKey)
						 ->del($infosKey)
						 ->zRem(self::CART_DEADLINE_SET, $deadlineKey)
						 ->exec();
		} catch (Exception $e) {
			return false;
		}
		return true;
	}
	
	/**
	 * 清空购物车历史商品
	 * @param $uid int 
	 * @param $country int
	 * @return boolean
	 */
	public function clearHistory($uid, $country)
	{
		$skusHistoryKey = $this->getSkusHistoryKey($uid, $country);
		$infosHistoryKey = $this->getInfosHistoryKey($uid, $country);
		try {
			$this->_redis->multi()
						 ->del($skusHistoryKey)
						 ->del($infosHistoryKey)
						 ->exec();
		} catch (Exception $e) {
			return false;
		}
		return true;
	}

			
	/**
	 * 获取购物车key infos
	 * @param $time int 过期时间（时间戳）
	 * @param $limit int 
	 * @return array
	 */
	public function getCartKeyInfos($time, $limit)
	{
		$data = $this->_redis->zRangeByScore(self::CART_DEADLINE_SET, 0, $time, ['limit'=>[0, $limit]]);
		$ret = [];
		foreach ($data as $key) {
			$keys = explode('_', $key.'_0');
			$ret[] = ['uid'=> intval($keys[0]), 'country'=> intval($keys[1])];
			
		}
		return $ret;
			
	}

	/**
	 * 获取购物车过期时间 key
	 * @param $uid int 
	 * @param $country int
	 * @return string
	 */
	public function getDeadlineKey($uid, $country)
	{
		return intval($uid).'_'.intval($country);	
	}

	
	/**
	 * 获取购物车sku信息key
	 * @param $uid int 
	 * @param $country int
	 * @return string
	 */
	public function getSkusKey($uid, $country)
	{
		return self::SKUS_PREFIX.intval($uid).'_'.intval($country);	
	}
	
	/**
	 * 获取购物车详细信息key
	 * @param $uid int 
	 * @param $country int
	 * @return string
	 */
	public function getInfosKey($uid, $country)
	{
		return self::INFOS_PREFIX.intval($uid).'_'.intval($country);	
	}

	/**
	 * 获取购物车sku历史信息key
	 * @param $uid int 
	 * @param $country int
	 * @return string
	 */
	public function getSkusHistoryKey($uid, $country)
	{
		return self::SKUS_HISTORY_PREFIX.intval($uid).'_'.intval($country);	
	}

	/**
	 * 获取购物车详细历史信息key
	 * @param $uid int 
	 * @param $country int
	 * @return string
	 */
	public function getInfosHistoryKey($uid, $country)
	{
		return self::INFOS_HISTORY_PREFIX.intval($uid).'_'.intval($country);	
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
	 * 添加购物车过期时间（时间戳）
	 * @param $uid int 
	 * @param $country int
	 * @param $time int 过期时间（时间戳）
	 * @return boolean 
	 */
	private function _addDeadline($uid, $country, $time=0)
	{
		$time = $time ? $time : time() + self::CART_EFFECTIVE_TIME;
		$deadlineKey = $this->getDeadlineKey($uid, $country);	
		return $this->_redis->zAdd(self::CART_DEADLINE_SET, $time, $deadlineKey);//redis sorted set
	}

	/**
	 * 获取购物车过期时间（时间戳）
	 * @param $uid int 
	 * @param $country int
	 * @return int 
	 */
	private function _getDeadline($uid, $country)
	{
		$deadlineKey = $this->getDeadlineKey($uid, $country);
		return (int) $this->_redis->zScore(self::CART_DEADLINE_SET, $deadlineKey);	
	}
	
}

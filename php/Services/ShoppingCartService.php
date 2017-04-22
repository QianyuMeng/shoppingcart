<?php
/*
 * 购物车service
 * @author mengqianyu <mqycn@sina.cn> 
 * @version 1.0
 * ==================================
 * 注意！！！
 * 所有方法返回false时说明发生了错误，
 * 此时可用getLastError()方法获取错误码和错误信息，错误码说明如下
 * 44444	服务器异常，操作失败
 * 20001	购物车已满
 * 20002	购物车已失效
 * 30003	超出商品限购数量
 *
*/

namespace Services;

use Services\BaseService;
use Repositories\ShoppingCartRepository;
use Repositories\GoodsStockRepository;

class ShoppingCartService extends BaseService
{
	const CART_MAX_SIZE = 20; //购物车可加入不同sku的最大数量
	const MAX_SHOPPING_COUNT = 200; //单品最大购买数量 
	const CART_EFFECTIVE_TIME = ShoppingCartRepository::CART_EFFECTIVE_TIME; //购物车有效时间
	const CART_EFFECTIVE = ShoppingCartRepository::CART_EFFECTIVE; //购物车有效 
	const CART_NOEFFECTIVE = ShoppingCartRepository::CART_NOEFFECTIVE; //购物车失效

    private $_scRep;
	private $_stockRep;

    public function __construct(ShoppingCartRepository $scRep, GoodsStockRepository $stockRep)
    {
        $this->_scRep = $scRep;
        $this->_stockRep = $stockRep;
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
	public function addCartItem($uid, $country, $skuId, $goodsId, $count, $activityId=0, $scheduleId=0)
	{
		$this->_toHistory($uid, $country);
		$total = $this->_scRep->getItemTotal($uid, $country);
		if ($total >= self::CART_MAX_SIZE) {
			$this->setLastError(20001, "Shopping cart is full");
			return false;		
		}
		$isExists = $this->_scRep->isExists($uid, $country, $skuId);
		$ret = $this->_scRep->addItem($uid, $country, $skuId, $goodsId, $count, $activityId, $scheduleId);
		if ($ret !== false) {
			$historySkuIds = $this->_scRep->getHistorySkuIds($uid, $country); 
			if (!empty($historySkuIds) && in_array($skuId, $historySkuIds)) {
				$this->_scRep->delHistoryItem($uid, $country, $skuId);
			}

			//锁定库存
			if (!empty($scheduleId)) {
				$this->_stockRep->lockScheduleGoodsStock($scheduleId, $goodsId, $count);	
			} else {
				$this->_stockRep->lock($skuId, $uid, $count);
			}

			//如果是第一次加入的sku,延长购物车所有商品库存锁定过期时间
			if (!$isExists) {
				$this->_resetStockLockTime($uid, $country);
			}
		}
		return $this->result($ret);
	}

	/**
	 * 删除购物车中的商品 
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @return boolean
	 */
	public function delCartItem($uid, $country, $skuId)
	{
		$status = $this->_scRep->getStatus($uid, $country);
		if ($status == self::CART_NOEFFECTIVE) {
			$this->_toHistory($uid, $country);
			$this->setLastError(20002, "Shopping cart Expired");
			return false;
		}
		$item = $this->_scRep->getItem($uid, $country, $skuId);
		$ret = $this->_scRep->delItem($uid, $country, $skuId);
		if ($ret !== false && $item !== false) {
			//释放库存
			if (!empty($item['scheduleId'])) {
				$this->_stockRep->unlockScheduleGoodsStock($item['scheduleId'], $item['goodsId'], $item['count']);	
			} else {
				$this->_stockRep->unlock($skuId, $uid, $item['count']);
			}	
		}
		if ($this->_scRep->getItemTotal($uid, $country) == 0) {
			$this->_scRep->clear($uid, $country);
		}
		return $this->result($ret);
	}

	/**
	 * 增加购物车某sku的数量
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @param $count int
	 * @return int/false
	 */
	public function increCartSkuCount($uid, $country, $skuId, $count=1)
	{
		$status = $this->_scRep->getStatus($uid, $country);
		if ($status == self::CART_NOEFFECTIVE) {
			$this->_toHistory($uid, $country);
			$this->setLastError(20002, "Shopping cart Expired");
			return false;
		}
		$skuCount = $this->_scRep->getSkuCount($uid, $country, $skuId);
		if (($skuCount + $count) > self::MAX_SHOPPING_COUNT) {
			$this->setLastError(30003, "The goods can only buy ".self::MAX_SHOPPING_COUNT." pieces at most");
			return false;
		}
		$ret = $this->_scRep->increSkuCount($uid, $country, $skuId, $count);
		$item = $this->_scRep->getItem($uid, $country, $skuId);
		if ($ret !== false && $item !== false) {
			//锁定库存
			if (!empty($item['scheduleId'])) {
				$this->_stockRep->lockScheduleGoodsStock($item['scheduleId'], $item['goodsId'], $count);	
			} else {
				$this->_stockRep->lock($skuId, $uid, $count);
			}	
		}
		return $this->result($ret);
	}

	/**
	 * 减少购物车某sku的数量
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @param $count int
	 * @return int/false
	 */
	public function decreCartSkuCount($uid, $country, $skuId, $count=1)
	{
		$status = $this->_scRep->getStatus($uid, $country);
		if ($status == self::CART_NOEFFECTIVE) {
			$this->_toHistory($uid, $country);
			$this->setLastError(20002, "Shopping cart Expired");
			return false;
		}
		$item = $this->_scRep->getItem($uid, $country, $skuId);
		if (empty($item['count'])){
			return $this->result(false);
		}
		if ($item['count'] <= $count) {
			$count = $item['count'];
			$ret = $this->_scRep->delItem($uid, $country, $skuId);
		} else {
			$ret = $this->_scRep->increSkuCount($uid, $country, $skuId, -$count);
		}
		if ($ret !== false && $item !== false) {
			//释放库存
			if (!empty($item['scheduleId'])) {
				$this->_stockRep->unlockScheduleGoodsStock($item['scheduleId'], $item['goodsId'], $count);	
			} else {
				$this->_stockRep->unlock($skuId, $uid, $count);
			}	
		}
		return $this->result($ret);
	}


	/**
	 * 减少购物车某sku的数量
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @param $count int
	 * @return boolean
	 */
	public function decreCartSkuCountByGoodsId($uid, $country, $goodsId, $count=1)
	{
		$status = $this->_scRep->getStatus($uid, $country);
		if ($status == self::CART_NOEFFECTIVE) {
			$this->_toHistory($uid, $country);
			$this->setLastError(20002, "Shopping cart Expired");
			return false;
		}
		$skuIds = $this->_scRep->getSkuIdsByGoodsId($uid, $country, $goodsId);
		if (!is_array($skuIds)) {
			return $this->result(false);
		}
		foreach ($skuIds as $skuId) {
			if ($count <= 0) {
				break;
			}
			$dec = $count;
			$item = $this->_scRep->getItem($uid, $country, $skuId);
			if ($item == false) {
				return $this->result(false);
			}
			if ($item['count'] <= $dec) {
				$dec = $item['count'];
				$ret = $this->_scRep->delItem($uid, $country, $skuId);
			} else {
				$ret = $this->_scRep->increSkuCount($uid, $country, $skuId, -$dec);
			}
			if ($ret !== false) {
				//释放库存
				if (!empty($item['scheduleId'])) {
					$this->_stockRep->unlockScheduleGoodsStock($item['scheduleId'], $item['goodsId'], $dec);	
				} else {
					$this->_stockRep->unlock($skuId, $uid, $dec);
				}	
			}
			$count -= $dec;
		}
		return true;
	}

	/**
	 * 获取购物车商品列表
	 * @param $uid int 
	 * @param $country int
	 * @param $isRevert boolean 是否按加入时间降序排序	 
	 * @return array/false
	 */
	public function getCartItems($uid, $country, $isRevert=true)
	{
		$this->_toHistory($uid, $country);
		$ret = $this->_scRep->getItems($uid, $country, $isRevert);
		return $this->result($ret);
	}

	/**
	 * 获取购物车单行数据
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int	 
	 * @return array/false
	 */
	public function getCartItem($uid, $country, $skuId)
	{
		$this->_toHistory($uid, $country);
		$ret = $this->_scRep->getItem($uid, $country, $skuId);
		return $this->result($ret);
	}

	/**
	 * 获取购物车详细信息
	 * @param $uid int 
	 * @param $country int
	 * @param $isRevert boolean 是否按加入时间降序排序	 
	 * @return array/false
	 */
	public function getCartInfos($uid, $country, $isRevert=true)
	{
		$this->_toHistory($uid, $country);
		$items = $this->_scRep->getItems($uid, $country, $isRevert);
		$items =  $this->result($items);
		if (!is_array($items)) {
			return false;	
		}
		$coupon = $this->_scRep->getCoupon($uid, $country);
		$data = [
			'effectiveTime' => $this->_scRep->getEffectiveTime($uid, $country),
			'couponId'		=> intval($coupon['couponId']),
			'couponAmount'	=> floatval($coupon['couponAmount']),
			'items'			=> $items,
		];
		return $data;
	}

	/**
	 * 获取购物车skuid列表
	 * @param $uid int 
	 * @param $country int
	 * @param $isRevert boolean 是否按加入时间降序排序	 
	 * @return array/false
	 */
	public function getCartSkuIds($uid, $country, $isRevert=true)
	{
		$this->_toHistory($uid, $country);
		$ret = $this->_scRep->getSkuIds($uid, $country, $isRevert);
		return $this->result($ret);
	}

	/**
	 * 重置购物车生效时间
	 * @param $uid int 
	 * @param $country int
	 * @return boolean
	 */
	public function resetCartEffectiveTime($uid, $country)
	{
		$ret = $this->_scRep->updateEffectiveTime($uid, $country);
		if ($ret !== false) {
			//延长库存锁定过期时间
			$this->_resetStockLockTime($uid, $country);	
		}
		return $this->result($ret);
	}

	/**
	 * 获取购物车生效时间
	 * @param $uid int 
	 * @param $country int
	 * @return int/false
	 */
	public function getCartEffectiveTime($uid, $country)
	{
		$this->_toHistory($uid, $country);
		$ret = $this->_scRep->getEffectiveTime($uid, $country);
		return $this->result($ret);
	}

	/**
	 * 设置购物车优惠券信息
	 * @param $uid int 
	 * @param $country int
	 * @param $couponId int
	 * @param $couponAmount int
	 * @return boolean
	 */
	public function setCartCoupon($uid, $country, $couponId, $couponAmount)
	{
		$status = $this->_scRep->getStatus($uid, $country);
		if ($status == self::CART_NOEFFECTIVE) {
			$this->_scRep->toHistory($uid, $country);
			$this->setLastError(20002, "Shopping cart Expired");
			return false;
		}
		$ret = $this->_scRep->setCoupon($uid, $country, $couponId, $couponAmount);
		return $this->result($ret);	
	}

	/**
	 * 获取购物车优惠券信息
	 * @param $uid int 
	 * @param $country int
	 * @return array/false
	 */
	public function getCartCoupon($uid, $country)
	{
		$this->_toHistory($uid, $country);
		$ret = $this->_scRep->getCoupon($uid, $country);
		return $this->result($ret);	
	}

    /**
     * 获取CouponId
     * @param $user int 
     * @param $countryCode int
     * @return int
     */
    public function getCartCouponId($user, $countryCode)
    {
        $coupon = $this->getCartCoupon($user,$countryCode);
        return $coupon ? $coupon['couponId'] : 0;
    }

	/**
	 * 获取购物车状态（是否失效）
	 * @param $uid int 
	 * @param $country int
	 * @return int/false CART_EFFECTIVE:有效,CART_NOECTIVE:失效,false:服务器出错 
	 */
	public function getCartStatus($uid, $country)
	{
		$ret = $this->_scRep->getStatus($uid, $country);
		return $this->result($ret);
	}

	/**
	 * 获取购物车不同sku的数量
	 * @param $uid int 
	 * @param $country int
	 * @return int/false
	 */
	public function getCartItemTotal($uid, $country)
	{
		$this->_toHistory($uid, $country);
		$ret = $this->_scRep->getItemTotal($uid, $country);
		return $this->result($ret);
	}

	/**
	 * 获取购物车所有sku总数 
	 * @param $uid int 
	 * @param $country int
	 * @return int/false
	 */
	public function getCartSkuTotal($uid, $country)
	{
		$this->_toHistory($uid, $country);
		$ret = $this->_scRep->getSkuTotal($uid, $country);
		return $this->result($ret);
	}

	/**
	 * 获取购物车某sku的购买数量 
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @return int/false
	 */
	public function getCartSkuCount($uid, $country, $skuId)
	{
		$this->_toHistory($uid, $country);
		$ret = $this->_scRep->getSkuCount($uid, $country, $skuId);
		return $this->result($ret);
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
		$ret = $this->_scRep->getGoodsCount($uid, $country, $goodsId);
		return $this->result($ret);
	}

	/**
	 * 删除购物车历史中的商品 
	 * @param $uid int 
	 * @param $country int
	 * @param $skuId int
	 * @return boolean
	 */
	public function delCartHistoryItem($uid, $country, $skuId)
	{
		$ret = $this->_scRep->delHistoryItem($uid, $country, $skuId);
		return $this->result($ret);
	}

	/**
	 * 获取购物车详细历史信息列表
	 * @param $uid int 
	 * @param $country int
	 * @param $isRevert boolean 是否按加入时间降序排序	 
	 * @return array/false
	 */
	public function getCartHistoryItems($uid, $country, $isRevert=true)
	{
		$ret = $this->_scRep->getHistoryItems($uid, $country, $isRevert);
		return $this->result($ret);
	}

	/**
	 * 获取购物车历史skuid列表
	 * @param $uid int 
	 * @param $country int
	 * @param $isRevert boolean 是否按加入时间降序排序	 
	 * @return array/false
	 */
	public function getCartHistorySkuIds($uid, $country, $isRevert=true)
	{
		$ret = $this->_scRep->getHistorySkuIds($uid, $country, $isRevert);
		return $this->result($ret);
	}

	/**
	 * 清空购物车商品
	 * @param $uid int 
	 * @param $country int
	 * @param $isUnlockStock boolean
	 * @param $isUnlockSchedule boolean
	 * @return boolean
	 */
	public function clearCart($uid, $country, $isUnlockStock=false, $isUnlockSchedule=false)
	{
		$items = $this->_scRep->getItems($uid, $country);
		$ret = $this->_scRep->clear($uid, $country);
		if (!$ret) {
			return false;	
		}
		//释放库存
		if ($isUnlockStock) {
			foreach ($items as $item) {
				if (!empty($item['scheduleId'])) {
					$isUnlockSchedule && $this->_stockRep->unlockScheduleGoodsStock($item['scheduleId'], $item['goodsId'], $item['count']);	
					continue;
				}
				$this->_stockRep->unlock($item['skuId'], $uid, $item['count']);
			}
		}
		return true;
	}

	/**
	 * 清空购物车历史商品
	 * @param $uid int 
	 * @param $country int
	 * @return boolean
	 */
	public function clearCartHistory($uid, $country)
	{
		$ret = $this->_scRep->clearHistory($uid, $country);
		return $this->result($ret);
	}

	/**
	 * 清空已过期的购物车(用于计划任务)
	 * @param $time int 过期时间（时间戳）
	 * @param $limit int 
	 * @return boolean
	 */
	public function clearExpired($time = 0, $limit = 1000)
	{
		$time = $time ? $time : time();
		$isMore = true;
		while ($isMore)	{
			$isMore = $this->_clearExpired($time, $limit);
		}
			
	}

	/**
	 * 清空已过期的购物车(用于计划任务)
	 * @param $time int 过期时间（时间戳）
	 * @param $limit int 
	 * @return boolean
	 */
	private function _clearExpired($time, $limit)
	{
		$data = $this->_scRep->getCartKeyInfos($time, $limit);
		$isMore = count($data) == $limit ? true : false;
		foreach ($data as $val) {
			$items = $this->_scRep->getItems($val['uid'], $val['country']);
			//释放库存
			foreach ($items as $item) {
				if (!empty($item['scheduleId'])) {
					$this->_stockRep->unlockScheduleGoodsStock($item['scheduleId'], $item['goodsId'], $item['count']);	
					continue;
				}
				$this->_stockRep->unlock($item['skuId'], $val['uid'], $item['count']);
			}	
			$this->_scRep->toHistory($val['uid'], $val['country']);
		} 
		return $isMore;

	}

	/**
	 * 当前购物车商品转为历史商品
	 * @param $uid int 
	 * @param $country int
	 * @return boolean
	 */
	private function _toHistory($uid, $country)
	{
		$status = $this->_scRep->getStatus($uid, $country);
		if ($status != self::CART_NOEFFECTIVE) {
			return false;	
		}
		$items = $this->_scRep->getItems($uid, $country);
		$ret = $this->_scRep->toHistory($uid, $country);
		if (!$ret) {
			return false;	
		}
		//释放库存
		foreach ($items as $item) {
			if (!empty($item['scheduleId'])) {
				$this->_stockRep->unlockScheduleGoodsStock($item['scheduleId'], $item['goodsId'], $item['count']);	
				continue;
			} 
			$this->_stockRep->unlock($item['skuId'], $uid, $item['count']);
		}	
		return true;
	}

	/**
	 * 重置库存锁定过期时间
	 * @param $uid int 
	 * @param $country int
	 * @return boolean
	 */
	private function _resetStockLockTime($uid, $country)
	{
		$items = $this->_scRep->getItems($uid, $country);
		$time = time() + self::CART_EFFECTIVE_TIME;
		foreach ($items as $item) {
			if (empty($item['scheduleId'])) {
				$this->_stockRep->updateLockTime($item['skuId'], $uid, $time);	
			} 
		}
				
	}

	/**
	 * 服务器异常时设置错误信息
	 * @param $result mixed
	 * @return mixed
	 */
	private function result($result)
	{
		if ($result === false) {
			$this->setLastError(44444, "Operation failed, please try again");	
		}
		return 	$result;
	}

}

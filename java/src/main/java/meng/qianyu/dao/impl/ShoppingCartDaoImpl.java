package meng.qianyu.dao.impl;

import java.util.*;

import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.data.redis.RedisConnectionFailureException;
import org.springframework.data.redis.core.BoundHashOperations;
import org.springframework.data.redis.core.StringRedisTemplate;
import redis.clients.jedis.exceptions.JedisConnectionException;
import meng.qianyu.dao.ShoppingCartDao;
import meng.qianyu.domain.ShoppingCartItem;

/**
 * 购物车DAO
 *
 * @author MengQianyu <mqycn@sina.cn>
 * @version 2017-03-16
 */
public class ShoppingCartDaoImpl implements ShoppingCartDao {

	/**
	 * redis sorted set,用于记录购物车的过期时间
	 */
	private static final String CART_DEADLINE_SORTED_SET = "SHOPPING_CART_DEADLINE";

	/**
	 * redis sorted set, 用于对item按时间排序以及快速取出所有skuId
	 */
	private static final String CART_SKUS_PREFIX = "CART_SKUS_";

	/**
	 * redis hash,用于记录item详细信息
	 */
	private static final String CART_ITEM_INFOS_PREFIX = "CART_INFOS_";

	/**
	 * redis sorted set, 用于对item按时间排序以及快速取出所有skuId（上一次已过期的数据）
	 */
	private static final String CART_HISTORY_SKUS_PREFIX = "CART_SKUS_HISTORY_";

	/**
	 * redis hash,用于记录item详细信息（上一次已过期的数据）
	 */
	private static final String CART_HISTORY_ITEM_INFOS_PREFIX = "CART_INFOS_HISTORY_";

	/**
	 * 购物车有效时间（毫秒）
	 */
	private static final int CART_EFFECTIVE_TIME = 1800000;

	@Autowired
	private StringRedisTemplate redisTemplate;

	public boolean addItem(String identity, ShoppingCartItem item) {
		String skusKey = getSkusSortedSetKey(identity);
		String infosKey = getItemInfosHashKey(identity);
		String skuId = item.getSkuId();
		Map<String, String> infos = new HashMap<String, String>();

		infos.put(getItemGoodsIdKey(skuId), item.getGoodsId());
		infos.put(getItemAddTimeKey(skuId), String.valueOf(item.getAddTime()));

		try {
			boolean isExists = redisTemplate.boundHashOps(infosKey).hasKey(getItemCountKey(skuId));

			redisTemplate.setEnableTransactionSupport(true);
			redisTemplate.multi();
			redisTemplate.boundZSetOps(skusKey).add(skuId, item.getAddTime());
			redisTemplate.boundHashOps(infosKey).putAll(infos);
			redisTemplate.boundHashOps(infosKey).increment(getItemCountKey(skuId), item.getCount());
			redisTemplate.exec();

			if (!isExists) {
				addDeadline(identity);
			}
		} catch (RedisConnectionFailureException e) {
			return false;
		}

		return true;
	}

	public boolean delItem(String identity, String skuId) {
		String skusKey = getSkusSortedSetKey(identity);
		String infosKey = getItemInfosHashKey(identity);

		try {
			redisTemplate.setEnableTransactionSupport(true);
			redisTemplate.multi();
			redisTemplate.boundZSetOps(skusKey).remove(skuId);
			redisTemplate.boundHashOps(infosKey)
					.delete(getItemGoodsIdKey(skuId), getItemCountKey(skuId), getItemAddTimeKey(skuId));
			redisTemplate.exec();
		} catch (JedisConnectionException e) {
			return false;
		}

		return true;
	}

	public ShoppingCartItem getItem(String identity, String skuId) {
		String infosKey = getItemInfosHashKey(identity);
		List<String> keys = new ArrayList<String>();

		keys.add(getItemGoodsIdKey(skuId));
		keys.add(getItemCountKey(skuId));
		keys.add(getItemAddTimeKey(skuId));

		BoundHashOperations<String, String, String> hops;
		hops = redisTemplate.boundHashOps(infosKey);
		List<String> infos = hops.multiGet(keys);
		String goodsId = infos.get(0);
		int count = (infos.get(1) == null) ? 0 : Integer.parseInt(infos.get(1));
		long addTime = (infos.get(2) == null) ? 0 : Long.parseLong(infos.get(2));

		return new ShoppingCartItem(skuId, goodsId, count, addTime);
	}

	public List<ShoppingCartItem> getItemList(String identity) {
		String skusKey = getSkusSortedSetKey(identity);
		List<ShoppingCartItem> itemList = new ArrayList<ShoppingCartItem>();
		Set<String> skuIds = redisTemplate.boundZSetOps(skusKey).reverseRange(0, -1);
		for (String skuId : skuIds) {
			itemList.add(getItem(identity, skuId));
		}
		return itemList;
	}

	public long getItemTotal(String identity) {
		String skusKey = getSkusSortedSetKey(identity);
		return redisTemplate.boundZSetOps(skusKey).zCard();
	}

	public int getSkuTotal(String identity) {
		int total = 0;
		String skusKey = getSkusSortedSetKey(identity);
		String infosKey = getItemInfosHashKey(identity);
		List<String> countKeys = new ArrayList<String>();
		Set<String> skuIds = redisTemplate.boundZSetOps(skusKey).range(0, -1);
		if (skuIds.isEmpty()) {
			return 0;
		}
		for (String skuId : skuIds) {
			countKeys.add(getItemCountKey(skuId));
		}
		BoundHashOperations<String, String, String> hops;
		hops = redisTemplate.boundHashOps(infosKey);
		List<String> countList = hops.multiGet(countKeys);
		for (String count : countList) {
			total += count == null ? 0 : Integer.parseInt(count);
		}
		return total;
	}


	public long increSkuCount(String identity, String skuId, long count) {
		String infosKey = getItemInfosHashKey(identity);
		try {
			return redisTemplate.boundHashOps(infosKey).increment(getItemCountKey(skuId), count);
		} catch (Exception e) {
			return 0;
		}
	}

	public boolean increEffectiveTime(String identity, long increment) {
		long deadline = getDeadline(identity);
		deadline = deadline > 0 ? deadline : System.currentTimeMillis();
		deadline += increment;
		return addDeadline(identity, deadline);
	}

	public long getEffectiveTime(String identity) {
		long deadline = getDeadline(identity);
		long effectiveTime = deadline - System.currentTimeMillis();
		return effectiveTime > 0 ? effectiveTime : 0;
	}

	public CartStatus getStatus(String identity) {
		long effectiveTime = getEffectiveTime(identity);
		return effectiveTime > 0 ? CartStatus.EFFECTIVE : CartStatus.NOEFFECTIVE;
	}

	public boolean clear(String identity) {
		String skusKey = getSkusSortedSetKey(identity);
		String infosKey = getItemInfosHashKey(identity);
		try {
			redisTemplate.setEnableTransactionSupport(true);
			redisTemplate.multi();
			redisTemplate.delete(skusKey);
			redisTemplate.delete(infosKey);
			redisTemplate.boundZSetOps(CART_DEADLINE_SORTED_SET).remove(identity);
			redisTemplate.exec();
		} catch (JedisConnectionException e) {
			return false;
		}
		return true;
	}

	public boolean toHistory(String identity) {
		String skusKey = getSkusSortedSetKey(identity);
		String infosKey = getItemInfosHashKey(identity);
		String historySkusKey = getHistorySkusSortedSetKey(identity);
		String historyInfosKey = getHistoryItemInfosHashKey(identity);
		try {
			redisTemplate.setEnableTransactionSupport(true);
			redisTemplate.multi();
			redisTemplate.rename(skusKey, historySkusKey);
			redisTemplate.rename(infosKey, historyInfosKey);
			redisTemplate.boundZSetOps(CART_DEADLINE_SORTED_SET).remove(identity);
			redisTemplate.exec();
		} catch (JedisConnectionException e) {
			return false;
		}
		return true;
	}

	public boolean clearHistory(String identity) {
		String skusKey = getHistorySkusSortedSetKey(identity);
		String infosKey = getHistoryItemInfosHashKey(identity);
		try {
			redisTemplate.setEnableTransactionSupport(true);
			redisTemplate.multi();
			redisTemplate.delete(skusKey);
			redisTemplate.delete(infosKey);
			redisTemplate.exec();
		} catch (JedisConnectionException e) {
			return false;
		}
		return true;
	}

	public boolean delHistoryItem(String identity, String skuId) {
		String skusKey = getHistorySkusSortedSetKey(identity);
		String infosKey = getHistoryItemInfosHashKey(identity);
		try {
			redisTemplate.setEnableTransactionSupport(true);
			redisTemplate.multi();
			redisTemplate.boundZSetOps(skusKey).remove(skuId);
			redisTemplate.boundHashOps(infosKey)
					.delete(getItemGoodsIdKey(skuId), getItemCountKey(skuId), getItemAddTimeKey(skuId));
			redisTemplate.exec();
		} catch (JedisConnectionException e) {
			return false;
		}
		return true;
	}

	public ShoppingCartItem getHistoryItem(String identity, String skuId) {
		String infosKey = getHistoryItemInfosHashKey(identity);
		List<String> keys = new ArrayList<String>();

		keys.add(getItemGoodsIdKey(skuId));
		keys.add(getItemCountKey(skuId));
		keys.add(getItemAddTimeKey(skuId));

		BoundHashOperations<String, String, String> hops;
		hops = redisTemplate.boundHashOps(infosKey);
		List<String> infos = hops.multiGet(keys);
		String goodsId = infos.get(0);
		int count = (infos.get(1) == null) ? 0 : Integer.parseInt(infos.get(1));
		long addTime = (infos.get(2) == null) ? 0 : Long.parseLong(infos.get(2));

		return new ShoppingCartItem(skuId, goodsId, count, addTime);
	}

	public List<ShoppingCartItem> getHistoryItemList(String identity) {
		String skusKey = getHistorySkusSortedSetKey(identity);
		List<ShoppingCartItem> itemList = new ArrayList<ShoppingCartItem>();
		Set<String> skuIds = redisTemplate.boundZSetOps(skusKey).reverseRange(0, -1);
		for (String skuId : skuIds) {
			itemList.add(getHistoryItem(identity, skuId));
		}
		return itemList;
	}

	private boolean addDeadline(String identity) {
		long deadline = System.currentTimeMillis() + CART_EFFECTIVE_TIME;
		return addDeadline(identity, deadline);
	}

	private boolean addDeadline(String identity, long deadline) {
		try {
			return redisTemplate.boundZSetOps(CART_DEADLINE_SORTED_SET).add(identity, deadline);
		} catch (Exception e) {
			return false;
		}
	}

	private long getDeadline(String identity) {
		try {
			double score = redisTemplate.boundZSetOps(CART_DEADLINE_SORTED_SET).score(identity);
			return Math.round(score);
		} catch (Exception e) {
			return 0;
		}
	}

	private String getItemAddTimeKey(String skuId) {
		return "addTime_" + skuId;
	}

	private String getItemCountKey(String skuId) {
		return "count_" + skuId;
	}

	private String getItemGoodsIdKey(String skuId) {
		return "goodsId_" + skuId;
	}

	private String getItemInfosHashKey(String identity) {
		return CART_ITEM_INFOS_PREFIX + identity;
	}

	private String getHistoryItemInfosHashKey(String identity) {
		return CART_HISTORY_ITEM_INFOS_PREFIX + identity;
	}

	private String getHistorySkusSortedSetKey(String identity) {
		return CART_HISTORY_SKUS_PREFIX + identity;
	}

	private String getSkusSortedSetKey(String identity) {
		return CART_SKUS_PREFIX + identity;
	}
}

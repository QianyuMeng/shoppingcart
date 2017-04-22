package meng.qianyu.dao;

import meng.qianyu.domain.ShoppingCartItem;

import java.util.List;

/**
 * 购物车DAO
 *
 * @author MengQianyu <mqycn@sina.cn>
 * @version 2017 -03-16
 */
public interface ShoppingCartDao{

	/**
	 * The enum Cart status.
	 */
	enum CartStatus {
		/**
		 * Noeffective cart status.
		 */
		NOEFFECTIVE,
		/**
		 * Effective cart status.
		 */
		EFFECTIVE
	}

	/**
	 * Add item boolean.
	 *
	 * @param identity the identity
	 * @param item     the item
	 * @return the boolean
	 */
	boolean addItem(String identity, ShoppingCartItem item);

	/**
	 * Del item boolean.
	 *
	 * @param identity the identity
	 * @param skuId    the sku id
	 * @return the boolean
	 */
	boolean delItem(String identity, String skuId);

	/**
	 * Gets item.
	 *
	 * @param identity the identity
	 * @param skuId    the sku id
	 * @return the item
	 */
	ShoppingCartItem getItem(String identity, String skuId);


	/**
	 * Gets item list.
	 *
	 * @param identity the identity
	 * @return the item list
	 */
	List<ShoppingCartItem> getItemList(String identity);

	/**
	 * Incre sku count int.
	 *
	 * @param identity the identity
	 * @param skuId    the sku id
	 * @param count    the count
	 * @return the long
	 */
	long increSkuCount(String identity, String skuId, long count);

	/**
	 * Gets effective time.
	 *
	 * @param identity the identity
	 * @return the effective time
	 */
	long getEffectiveTime(String identity);

	/**
	 * Increase effective time boolean.
	 *
	 * @param identity  the identity
	 * @param increment the increment time
	 * @return the boolean
	 */
	boolean increEffectiveTime(String identity, long increment);

	/**
	 * Gets status.
	 *
	 * @param identity the identity
	 * @return the status
	 */
	CartStatus getStatus(String identity);

	/**
	 * Gets item total.
	 *
	 * @param identity the identity
	 * @return the item total
	 */
	long getItemTotal(String identity);

	/**
	 * Gets sku total.
	 *
	 * @param identity the identity
	 * @return the sku total
	 */
	int getSkuTotal(String identity);

	/**
	 * Clear boolean.
	 *
	 * @param identity the identity
	 * @return the boolean
	 */
	boolean clear(String identity);

	/**
	 * To history boolean.
	 *
	 * @param identity the identity
	 * @return the boolean
	 */
	boolean toHistory(String identity);

	/**
	 * Del history item boolean.
	 *
	 * @param identity the identity
	 * @param skuId    the sku id
	 * @return the boolean
	 */
	boolean delHistoryItem(String identity, String skuId);

	/**
	 * Gets history item.
	 *
	 * @param identity the identity
	 * @param skuId    the sku id
	 * @return the history item
	 */
	ShoppingCartItem getHistoryItem(String identity, String skuId);

	/**
	 * Gets history item list.
	 *
	 * @param identity the identity
	 * @return the history items
	 */
	List<ShoppingCartItem> getHistoryItemList(String identity);

	/**
	 * Clear history boolean.
	 *
	 * @param identity the identity
	 * @return the boolean
	 */
	boolean clearHistory(String identity);


}

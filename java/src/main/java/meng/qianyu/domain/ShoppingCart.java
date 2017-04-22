package meng.qianyu.domain;

import java.io.Serializable;

import java.util.List;

/**
 * meng.qianyu.domain
 *
 * @author MengQianyu <mqycn@sina.cn>
 * @version 2017 -03-16
 */
public class ShoppingCart implements Serializable {
    private static final long      serialVersionUID = -7011241820070393952L;
    private double                 amount;
    private long                   itemTotal;
    private long                   skuTotal;
    private long                   effectiveTime;
    private List<ShoppingCartItem> itemList;
    private List<ShoppingCartItem> historyItemList;

    public ShoppingCart(double amount, long itemTotal, long skuTotal, long effectiveTime, List<ShoppingCartItem> itemList,
                        List<ShoppingCartItem> historyItemList) {
        this.amount          = amount;
        this.itemTotal       = itemTotal;
        this.skuTotal        = skuTotal;
        this.effectiveTime   = effectiveTime;
        this.itemList        = itemList;
        this.historyItemList = historyItemList;
    }

    /**
     * Gets amount.
     *
     * @return the amount
     */
    public double getAmount() {
        return amount;
    }

    /**
     * Sets amount.
     *
     * @param amount the amount
     */
    public void setAmount(double amount) {
        this.amount = amount;
    }

    /**
     * Gets effective time.
     *
     * @return the effective time
     */
    public long getEffectiveTime() {
        return effectiveTime;
    }

    /**
     * Sets effective time.
     *
     * @param effectiveTime the effective time
     */
    public void setEffectiveTime(long effectiveTime) {
        this.effectiveTime = effectiveTime;
    }

    /**
     * Gets history item list.
     *
     * @return the history item list
     */
    public List<ShoppingCartItem> getHistoryItemList() {
        return historyItemList;
    }

    /**
     * Sets history item list.
     *
     * @param historyItemList the history item list
     */
    public void setHistoryItemList(List<ShoppingCartItem> historyItemList) {
        this.historyItemList = historyItemList;
    }

    /**
     * Gets item count.
     *
     * @return the item count
     */
    public long getItemCount() {
        return itemTotal;
    }

    /**
     * Sets item count.
     *
     * @param itemCount the item count
     */
    public void setItemCount(long itemCount) {
        this.itemTotal = itemCount;
    }

    /**
     * Gets item list.
     *
     * @return the item list
     */
    public List<ShoppingCartItem> getItemList() {
        return itemList;
    }

    /**
     * Sets item list.
     *
     * @param itemList the item list
     */
    public void setItemList(List<ShoppingCartItem> itemList) {
        this.itemList = itemList;
    }

    /**
     * Gets sku count.
     *
     * @return the sku count
     */
    public long getSkuCount() {
        return skuTotal;
    }

    /**
     * Sets sku count.
     *
     * @param skuCount the sku count
     */
    public void setSkuCount(long skuCount) {
        this.skuTotal = skuCount;
    }
}

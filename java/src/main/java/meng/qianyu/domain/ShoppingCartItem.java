package meng.qianyu.domain;

import java.io.Serializable;

/**
 * 购物车item对象（对应购物车的一行记录）
 * @author MengQianyu <mqycn@sina.cn>
 * @version 2017-03-16
 */
public class ShoppingCartItem implements Serializable{
	private static final long serialVersionUID = -6011241820070393952L;

	private String skuId;
	
	private String goodsId;

	private int count;
	
	private long addTime;

	public ShoppingCartItem(String skuId, String goodsId, int count, long addTime) {
		this.skuId = skuId;
		this.goodsId = goodsId;
		this.count = count;
		this.addTime = addTime;
	}

	public String getSkuId() {
		return skuId;
	}

	public void setSkuId(String skuId) {
		this.skuId = skuId;
	}

	public String getGoodsId() {
		return goodsId;
	}

	public void setGoodsId(String goodsId) {
		this.goodsId = goodsId;
	}

	public int getCount() {
		return count;
	}

	public void setCount(int count) {
		this.count = count;
	}

	public long getAddTime() {
		return addTime;
	}

	public void setAddTime(long addTime) {
		this.addTime = addTime;
	}
}

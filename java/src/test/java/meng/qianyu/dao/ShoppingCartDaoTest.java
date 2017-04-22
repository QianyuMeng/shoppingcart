package meng.qianyu.dao;

import meng.qianyu.domain.ShoppingCartItem;
import org.junit.Assert;
import org.junit.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.test.context.ContextConfiguration;
import org.springframework.test.context.junit4.AbstractJUnit4SpringContextTests;

import static org.junit.Assert.*;

/**
 * ShoppingCartDaoTest
 *
 * @author MengQianyu <mqycn@sina.cn>
 * @version 2017-03-17
 */
@ContextConfiguration(locations = {"classpath:beans/application.xml"})
public class ShoppingCartDaoTest extends AbstractJUnit4SpringContextTests {


	@Autowired
	private ShoppingCartDao shoppingCartDao;

	//@Test
	public void addItem() throws Exception {
		String identity = "10089";
		String skuId = "500001";
		String goodsId = "300001";
		int count = 2;
		long addTime = System.currentTimeMillis();
		ShoppingCartItem item = new ShoppingCartItem(skuId, goodsId, count, addTime);
		boolean result = shoppingCartDao.addItem(identity, item);
		Assert.assertTrue(result);

	}

	//@Test
	public void getEffectiveTime() {
		String identity = "1008";
		long deadline = shoppingCartDao.getEffectiveTime(identity);
		System.out.println(deadline);
	}

	@Test
	public void getSkuTotal() {
		String identity = "1008";
		long total = shoppingCartDao.getSkuTotal(identity);
		System.out.println(total);
	}

}
<?php
/*
 * 商品库存Repository
 * @author mengqianyu <mqycn@sina.cn> 
 * @version 1.0
 * ================================
 */

namespace Services;

abstract class  BaseService
{
    protected $_lastError;

    protected function returnFormat($code, $message = '', $data = [])
    {
        return ['code' => $code, 'message' => $message, 'data' => $data];
    }

	/*
	 * 设置错误信息
	 * @return array
	 */
	public function setLastError($code, $message='')
	{
		$this->_lastError = ['code' => $code, 'message' => $message];	
	}

	/*
	 * 获取错误信息
	 * @return array
	 */
	public function getLastError()
	{

		return $this->_lastError ? $this->_lastError : ['code' => 0, 'message' => ''];	
	}
}

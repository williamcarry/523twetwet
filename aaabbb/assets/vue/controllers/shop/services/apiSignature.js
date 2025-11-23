/**
 * API签名工具
 * 
 * 作用：为支付等敏感接口生成签名，防止参数被篡改
 * 
 * 使用方法：
 * import apiSignature from './apiSignature'
 * const signedData = apiSignature.sign(requestData)
 * 
 * 新增：使用登录时返回的临时密钥
 */

import CryptoJS from 'crypto-js'

class ApiSignature {
  constructor() {
    // 签名密钥存储在localStorage（支持跨标签页共享，用户手动登出或过期后清空）
    this.storageKey = 'api_sign_key'
  }
  
  /**
   * 设置签名密钥（登录后调用）
   * @param {string} key - 从后端获取的签名密钥
   */
  setKey(key) {
    localStorage.setItem(this.storageKey, key)
  }
  
  /**
   * 获取签名密钥
   * @returns {string} 签名密钥
   * @throws {Error} 如果未找到签名密钥
   */
  getKey() {
    // 从 localStorage 获取（登录时保存的临时密钥，支持跨标签页）
    const key = localStorage.getItem(this.storageKey)
    console.log('🔑 [apiSignature] 获取签名密钥:', key ? `存在 (前8位: ${key.substring(0, 8)}...)` : '❌ 不存在')
    if (key) {
      return key
    }
    
    // 没有签名密钥，抛出带errorCode的错误
    console.error('❌ [apiSignature] 签名密钥不存在，localStorage内容:', localStorage)
    const error = new Error('请重新登录')
    error.errorCode = 'UNAUTHORIZED'
    throw error
  }
  
  /**
   * 清除签名密钥（登出时调用）
   */
  clearKey() {
    localStorage.removeItem(this.storageKey)
  }

  /**
   * 生成API签名
   * @param {Object} params - 请求参数
   * @returns {Object} 包含signature、timestamp、nonce的完整参数
   */
  sign(params) {
    console.log('📝 [apiSignature] 开始生成签名，原始参数:', params)
    
    // 1. 添加时间戳和随机数
    const timestamp = Math.floor(Date.now() / 1000)
    const nonce = this.generateNonce(32)

    const paramsWithTime = {
      ...params,
      timestamp: timestamp,
      nonce: nonce
    }
    console.log('⏰ [apiSignature] 添加时间戳和nonce:', { timestamp, nonce })

    // 2. 构建签名字符串
    const signString = this.buildSignString(paramsWithTime)
    console.log('🔗 [apiSignature] 签名字符串:', signString)

    // 3. 使用HMAC-SHA256生成签名（使用用户的临时密钥）
    const key = this.getKey()
    const signature = CryptoJS.HmacSHA256(signString, key).toString()
    console.log('🔐 [apiSignature] 生成的签名:', signature)
    console.log('✅ [apiSignature] 签名生成完成')

    // 4. 返回完整参数
    return {
      ...paramsWithTime,
      signature: signature
    }
  }

  /**
   * 构建待签名字符串
   * @param {Object} params - 参数对象
   * @returns {string} 待签名字符串
   */
  buildSignString(params) {
    // 1. 移除signature字段
    const paramsForSign = { ...params }
    delete paramsForSign.signature

    // 2. 按key升序排序
    const sortedKeys = Object.keys(paramsForSign).sort()

    // 3. 构建签名字符串
    const signParts = []
    for (const key of sortedKeys) {
      let value = paramsForSign[key]

      // 处理不同类型的值
      if (typeof value === 'object' && value !== null) {
        value = JSON.stringify(value)
      } else if (typeof value === 'boolean') {
        value = value ? 'true' : 'false'
      } else if (value === null || value === undefined) {
        value = ''
      } else {
        value = String(value)
      }

      signParts.push(`${key}=${value}`)
    }

    return signParts.join('&')
  }

  /**
   * 生成随机nonce
   * @param {number} length - nonce长度
   * @returns {string} 随机字符串
   */
  generateNonce(length = 32) {
    const chars = '0123456789abcdef'
    let result = ''
    for (let i = 0; i < length; i++) {
      result += chars[Math.floor(Math.random() * chars.length)]
    }
    return result
  }
}

export default new ApiSignature()

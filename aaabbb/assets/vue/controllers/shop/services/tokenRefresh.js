/**
 * Token 自动刷新服务
 * 
 * 功能：
 * 1. 拦截 401 错误，自动使用 refreshToken 获取新的 accessToken
 * 2. 自动重试失败的请求
 * 3. 只有在 refreshToken 也失效时才跳转登录页
 * 4. 定时主动刷新：在 Token 过期前 5 分钟自动刷新
 * 
 * 使用方法：
 * import tokenRefreshService from './tokenRefresh'
 * 
 * // 启动自动刷新（在登录后调用）
 * tokenRefreshService.startAutoRefresh(store, 7200) // 7200秒 = 2小时
 * 
 * // 停止自动刷新（在登出时调用）
 * tokenRefreshService.stopAutoRefresh()
 */

import { ElMessage } from 'element-plus'
import { handleAuthError } from '../utils/authErrorHandler'

class TokenRefreshService {
  constructor() {
    this.isRefreshing = false
    this.failedQueue = []
    this.autoRefreshTimer = null
    this.tokenExpiresAt = null
    this.store = null
    this.originalFetch = null  // 保存原始 fetch，由 globalFetch.js 初始化时设置
  }

  /**
   * 启动定时自动刷新
   * @param {Object} store - Vuex store 实例
   * @param {number} expiresIn - Token 有效期（秒），默认 7200（2小时）
   */
  startAutoRefresh(store, expiresIn = 7200) {
    this.store = store
    
    // 清除旧的定时器
    this.stopAutoRefresh()
    
    // 计算 Token 过期时间
    this.tokenExpiresAt = Date.now() + (expiresIn * 1000)
    
    // 在过期前 5 分钟刷新（300秒 = 5分钟）
    const refreshBeforeExpire = 300 * 1000 // 5分钟
    const refreshDelay = (expiresIn * 1000) - refreshBeforeExpire
    
    this.autoRefreshTimer = setTimeout(async () => {
      const success = await this.refreshToken()
      
      if (success) {
        // 刷新成功后，继续启动下一次定时刷新
        this.startAutoRefresh(store, expiresIn)
      } else {
        console.warn('Token 自动刷新失败')
        handleAuthError(store)
      }
    }, refreshDelay)
  }

  /**
   * 停止定时自动刷新
   */
  stopAutoRefresh() {
    if (this.autoRefreshTimer) {
      clearTimeout(this.autoRefreshTimer)
      this.autoRefreshTimer = null
      this.tokenExpiresAt = null
    }
  }

  /**
   * 获取 Token 剩余有效时间（秒）
   * @returns {number} 剩余秒数
   */
  getTokenRemainingTime() {
    if (!this.tokenExpiresAt) {
      return 0
    }
    
    const remaining = Math.floor((this.tokenExpiresAt - Date.now()) / 1000)
    return remaining > 0 ? remaining : 0
  }

  /**
   * 刷新 Token
   * @returns {Promise<boolean>} 刷新成功返回 true
   */
  async refreshToken() {
    // 使用原始 fetch，避免触发全局拦截器导致循环调用
    const fetchFn = this.originalFetch || window.originalFetch || window.fetch
    
    console.log('🔄 [tokenRefresh] 开始刷新 Token...')
    
    try {
      const response = await fetchFn('/shop/api/auth/refresh', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'include' // 携带 Cookie（refreshToken）
      })

      console.log('📥 [tokenRefresh] 收到刷新响应:', { status: response.status })
      const result = await response.json()
      console.log('📄 [tokenRefresh] 响应数据:', result)

      if (result.success) {
        // 刷新成功，新的 accessToken 和 refreshToken 已经通过 Cookie 设置
        // 不需要手动处理，浏览器会自动更新
        
        // 保存新的签名密钥
        if (result.apiSignKey) {
          console.log('🔑 [tokenRefresh] 保存新的签名密钥:', result.apiSignKey.substring(0, 8) + '...')
          try {
            localStorage.setItem('api_sign_key', result.apiSignKey)
            console.log('✅ [tokenRefresh] 签名密钥已保存到 localStorage')
          } catch (error) {
            console.error('❌ [tokenRefresh] 保存签名密钥失败:', error)
          }
        } else {
          console.warn('⚠️ [tokenRefresh] 响应中没有 apiSignKey 字段')
        }
        
        console.log('✅ [tokenRefresh] Token 刷新成功')
        return true
      } else {
        console.error('❌ [tokenRefresh] Token 刷新失败:', result.message)
        return false
      }
    } catch (error) {
      console.error('❌ [tokenRefresh] Token 刷新异常:', error)
      return false
    }
  }

  /**
   * 处理队列中的失败请求
   * @param {boolean} success - Token 刷新是否成功
   */
  processQueue(success) {
    this.failedQueue.forEach(promise => {
      if (success) {
        promise.resolve()
      } else {
        promise.reject(new Error('Token refresh failed'))
      }
    })
    
    this.failedQueue = []
  }

  /**
   * 包装 fetch 请求，自动处理 Token 刷新
   * @param {Promise<Response>|string} fetchInput - fetch 请求的 Promise 或 URL
   * @param {Object} fetchOptions - fetch 选项（当 fetchInput 为 URL 时必需）
   * @param {Object} store - Vuex store 实例（可选）
   * @returns {Promise<Response>} 处理后的响应
   */
  async wrapFetch(fetchInput, fetchOptions = null, store = null) {
    // 兼容性处理：如果第二个参数是 store，说明使用旧的调用方式
    let url = null
    let options = null
    let isOldApi = false
    
    if (fetchInput instanceof Promise) {
      // 旧的API：wrapFetch(fetchPromise, store)
      isOldApi = true
      // 对于Promise，我们无法重新构建请求，只能直接处理
      const fetchPromise = fetchInput
      store = fetchOptions  // 第二个参数实际是 store
      return this._wrapFetchPromise(fetchPromise, store)
    } else {
      // 新的API：wrapFetch(url, options, store)
      url = fetchInput
      options = fetchOptions
    }
    
    return this._wrapFetchWithRetry(url, options, store)
  }
  
  /**
   * 包装已创建的 fetch Promise（旧API，无法重新构建请求）
   * @private
   */
  async _wrapFetchPromise(fetchPromise, store = null) {
    try {
      const response = await fetchPromise
      
      // 分析响应体，检查是否需要重新登录
      let needsRelogin = false
      let responseData = null
      
      // 检查 HTTP 401 状态码
      if (response.status === 401) {
        needsRelogin = true
      }
      
      // 检查响应体中的 needRelogin 字段（签名验证失败时）
      if (response.headers.get('content-type')?.includes('application/json')) {
        // 克隆响应体，因为我们需要多次读取
        const clonedResponse = response.clone()
        try {
          responseData = await clonedResponse.json()
          if (responseData && responseData.needRelogin === true) {
            needsRelogin = true
            
            // 清除旧的签名密钥
            try {
              localStorage.removeItem('api_sign_key')
            } catch (error) {
              console.error('清除签名密钥失败:', error)
            }
          }
        } catch (error) {
          // JSON 解析失败，忽略
        }
      }
      
      // 如果不需要重新登录，直接返回
      if (!needsRelogin) {
        return response
      }

      // 需要重新登录，尝试刷新 Token

      // 如果正在刷新，加入队列等待
      if (this.isRefreshing) {
        return new Promise((resolve, reject) => {
          this.failedQueue.push({ resolve, reject })
        }).then(() => {
          // Token 刷新成功后，重试原请求
          return fetchPromise
        })
      }

      // 开始刷新 Token
      this.isRefreshing = true

      const refreshSuccess = await this.refreshToken()

      this.isRefreshing = false

      if (refreshSuccess) {
        // Token 刷新成功，处理队列并重试原请求
        this.processQueue(true)
        
        // 重新启动定时刷新（刷新后重置计时器）
        if (this.store) {
          this.startAutoRefresh(this.store, 7200)
        }
        
        // 警告：无法重新构建请求，直接重试原Promise（签名可能仍然是旧的）
        const retryResponse = await fetchPromise
        return retryResponse
      } else {
        // Token 刷新失败，清除队列并跳转登录页
        this.processQueue(false)
        
        // 停止定时刷新
        this.stopAutoRefresh()
        
        // 清除用户状态并跳转登录页
        const storeToUse = store || this.store
        if (storeToUse) {
          handleAuthError(storeToUse)
        } else {
          // 如果没有 store，直接跳转
          window.location.href = '/login'
        }
        
        throw new Error('Token refresh failed, please login again')
      }
    } catch (error) {
      // 如果是网络错误或其他异常，直接抛出
      throw error
    }
  }
  
  /**
   * 包装 fetch 请求，支持重新构建请求（新API，推荐使用）
   * @private
   */
  async _wrapFetchWithRetry(url, options, store = null) {
    // 获取原始 fetch（避免无限递归）
    const fetchFn = this.originalFetch || window.originalFetch || fetch
    
    console.log(`🌐 [tokenRefresh] 拦截请求: ${url}`)
    
    try {
      // 第一次请求
      const response = await fetchFn(url, options)
      
      console.log(`📥 [tokenRefresh] 收到响应: ${url}, status: ${response.status}`)
      
      // 分析响应体，检查是否需要重新登录
      let needsRelogin = false
      let responseData = null
      
      // 检查 HTTP 401 状态码
      if (response.status === 401) {
        console.warn('⚠️ [tokenRefresh] 检测到 401 状态码')
        needsRelogin = true
      }
      
      // 检查响应体中的 needRelogin 字段（签名验证失败时）
      if (response.headers.get('content-type')?.includes('application/json')) {
        const clonedResponse = response.clone()
        try {
          responseData = await clonedResponse.json()
          console.log('📄 [tokenRefresh] 响应数据:', responseData)
          
          if (responseData && responseData.needRelogin === true) {
            console.warn('⚠️ [tokenRefresh] 检测到 needRelogin=true, code:', responseData.code)
            needsRelogin = true
            
            // 清除旧的签名密钥
            try {
              const oldKey = localStorage.getItem('api_sign_key')
              console.log('🗑️ [tokenRefresh] 清除旧的签名密钥:', oldKey ? oldKey.substring(0, 8) + '...' : 'null')
              localStorage.removeItem('api_sign_key')
            } catch (error) {
              console.error('❌ [tokenRefresh] 清除签名密钥失败:', error)
            }
          }
        } catch (error) {
          // JSON 解析失败，忽略
          console.error('❌ [tokenRefresh] 解析 JSON 失败:', error)
        }
      }
      
      // 如果不需要重新登录，直接返回
      if (!needsRelogin) {
        console.log('✅ [tokenRefresh] 无需刷新，返回响应')
        return response
      }

      // 需要重新登录，尝试刷新 Token
      console.log('🔄 [tokenRefresh] 需要刷新 Token...')

      // 如果正在刷新，加入队列等待
      if (this.isRefreshing) {
        return new Promise((resolve, reject) => {
          this.failedQueue.push({ resolve, reject })
        }).then(() => {
          // Token 刷新成功后，重新发起请求（会使用新的签名密钥）
          return this._retryRequest(url, options)
        })
      }

      // 开始刷新 Token
      this.isRefreshing = true

      const refreshSuccess = await this.refreshToken()

      this.isRefreshing = false

      if (refreshSuccess) {
        // Token 刷新成功，处理队列
        this.processQueue(true)
        
        // 重新启动定时刷新（刷新后重置计时器）
        if (this.store) {
          this.startAutoRefresh(this.store, 7200)
        }
        
        // 重试原请求（会重新生成签名）
        return this._retryRequest(url, options)
      } else {
        // Token 刷新失败，清除队列并跳转登录页
        this.processQueue(false)
        
        // 停止定时刷新
        this.stopAutoRefresh()
        
        // 清除用户状态并跳转登录页
        const storeToUse = store || this.store
        if (storeToUse) {
          handleAuthError(storeToUse)
        } else {
          // 如果没有 store，直接跳转
          window.location.href = '/login'
        }
        
        throw new Error('Token refresh failed, please login again')
      }
    } catch (error) {
      // 如果是网络错误或其他异常，直接抛出
      throw error
    }
  }
  
  /**
   * 重试请求（会重新生成签名）
   * @private
   */
  async _retryRequest(url, options) {
    // 获取原始 fetch（避免无限递归）
    const fetchFn = this.originalFetch || window.originalFetch || fetch
    
    // 如果有签名函数，调用它重新生成签名
    if (options && options._signRequest && typeof options._signRequest === 'function') {
      const { newUrl, newOptions } = await options._signRequest(url, options)
      return fetchFn(newUrl, newOptions)
    }
    
    // 如果URL包含签名参数，尝试重新生成
    if (url.includes('signature=') && url.includes('timestamp=') && url.includes('nonce=')) {
      try {
        // 动态导入 apiSignature
        const { default: apiSignature } = await import('./apiSignature.js')
        
        // 移除旧的签名参数，重新生成
        const baseUrl = url.split('?')[0]
        const signedData = apiSignature.sign({})
        const queryParams = new URLSearchParams({
          timestamp: signedData.timestamp.toString(),
          nonce: signedData.nonce,
          signature: signedData.signature
        })
        
        const newUrl = `${baseUrl}?${queryParams.toString()}`
        return fetchFn(newUrl, options)
      } catch (error) {
        console.error('重新生成签名失败:', error)
        // 失败后返回原请求
      }
    }
    
    // 直接重试请求（使用原始 fetch）
    return fetchFn(url, options)
  }
}

// 导出单例
const tokenRefreshService = new TokenRefreshService()

/**
 * 全局 fetch 封装（推荐使用）
 * 自动携带 Cookie，自动处理 Token 刷新
 * 
 * @param {string} url - 请求URL
 * @param {Object} options - fetch 选项
 * @param {Function} options.signRequest - 签名函数，用于重试时重新生成签名（可选）
 * @returns {Promise<Response>} 响应
 */
export async function globalFetch(url, options = {}) {
  // 确保携带 Cookie
  const fetchOptions = {
    ...options,
    credentials: options.credentials || 'include',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      ...options.headers
    }
  }
  
  // 提取签名函数
  const signRequest = options.signRequest
  delete fetchOptions.signRequest  // 从 fetch options 中移除
  
  // 使用新的 API，支持重新构建请求
  return tokenRefreshService.wrapFetch(url, {
    ...fetchOptions,
    _signRequest: signRequest  // 传递签名函数
  }, tokenRefreshService.store)
}

/**
 * 带签名的 GET 请求（自动处理签名刷新）
 * 
 * @param {string} baseUrl - 基础URL（不包含查询参数）
 * @param {Object} params - 需要签名的参数
 * @param {Object} options - fetch 选项
 * @returns {Promise<Response>} 响应
 * 
 * @example
 * import { fetchWithSignature } from './tokenRefresh'
 * import apiSignature from './apiSignature'
 * 
 * const response = await fetchWithSignature(
 *   '/shop/api/customer/address/check',
 *   {},  // 需要签名的参数
 *   { method: 'GET' }
 * )
 */
export async function fetchWithSignature(baseUrl, params = {}, options = {}) {
  // 动态导入 apiSignature 以避免循环依赖
  const { default: apiSignature } = await import('./apiSignature.js')
  
  // 签名函数：生成新的签名和 URL
  const signRequest = async (url, opts) => {
    // 每次都重新生成签名（使用最新的签名密钥）
    const signedData = apiSignature.sign(params)
    const queryParams = new URLSearchParams({
      timestamp: signedData.timestamp.toString(),
      nonce: signedData.nonce,
      signature: signedData.signature
    })
    
    // 构建新的 URL（移除旧的查询参数）
    const cleanUrl = baseUrl.split('?')[0]
    const newUrl = `${cleanUrl}?${queryParams.toString()}`
    
    return {
      newUrl,
      newOptions: {
        ...opts,
        _signRequest: signRequest  // 传递自身，供重试时再次调用
      }
    }
  }
  
  // 第一次请求：生成签名
  const { newUrl, newOptions } = await signRequest(baseUrl, options)
  
  // 直接使用 tokenRefreshService.wrapFetch，避免通过 globalFetch 再次处理
  return tokenRefreshService.wrapFetch(newUrl, newOptions, tokenRefreshService.store)
}

/**
 * 包装 fetch 请求的快捷方法（旧API，不推荐）
 * @deprecated 请使用 fetchWithSignature 或 globalFetch
 */
export async function fetchWithTokenRefresh(url, options = {}, store = null) {
  return globalFetch(url, options)
}

export default tokenRefreshService

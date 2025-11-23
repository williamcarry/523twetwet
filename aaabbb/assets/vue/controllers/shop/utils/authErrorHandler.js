/**
 * 认证错误处理工具
 * 
 * 用于统一处理登录过期、未认证等错误情况
 * 自动清除用户状态并跳转到登录页
 * 
 * 后端错误码定义：
 * - UNAUTHORIZED: 未登录
 * - TOKEN_EXPIRED: Token过期
 * - ACCOUNT_DISABLED: 账号已禁用
 * - AUTH_ERROR: 认证失败
 */

import { ElMessageBox } from 'element-plus'

/**
 * 检查是否是认证相关的错误
 * @param {Error} error - 错误对象
 * @param {Response} response - fetch响应对象（可选）
 * @param {Object} result - 响应JSON数据（可选）
 * @returns {boolean} 是否是认证错误
 */
export function isAuthError(error, response = null, result = null) {
  // 优先检查后端返回的 needRelogin 字段（签名验证失败时）
  if (result && result.needRelogin === true) {
    return true
  }
  
  // 检查后端返回的 errorCode（最可靠）
  if (result && result.errorCode) {
    const authErrorCodes = ['UNAUTHORIZED', 'TOKEN_EXPIRED', 'AUTH_ERROR']
    return authErrorCodes.includes(result.errorCode)
  }
  
  // 检查后端返回的 code 字段（签名验证错误）
  if (result && result.code) {
    const authErrorCodes = ['SIGNATURE_REQUIRED', 'INVALID_SIGNATURE', 'DUPLICATE_REQUEST']
    return authErrorCodes.includes(result.code)
  }
  
  // 检查前端错误对象的 errorCode（如 apiSignature.getKey() 抛出的错误）
  if (error && error.errorCode) {
    const authErrorCodes = ['UNAUTHORIZED', 'TOKEN_EXPIRED', 'AUTH_ERROR']
    return authErrorCodes.includes(error.errorCode)
  }
  
  // 检查HTTP状态码
  if (response && response.status === 401) {
    return true
  }
  
  return false
}

/**
 * 处理认证错误 - 清除状态并跳转登录页
 * @param {Object} store - Vuex store实例
 */
export function handleAuthError(store) {
  // 1. 清除Vuex store中的用户状态
  if (store && store.commit) {
    store.commit('LOGOUT')
  }
  
  // 2. 清除localStorage中的用户信息（双重保险）
  try {
    localStorage.removeItem('user')
  } catch (error) {
    console.error('清除localStorage失败:', error)
  }
  
  // 3. 清除Cookie中的accessToken（关键！）
  try {
    // 设置过期的Cookie来删除它
    document.cookie = 'accessToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;'
    document.cookie = 'refreshToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;'
  } catch (error) {
    console.error('清除Cookie失败:', error)
  }
  
  // 从 store 中获取当前语言，默认中文
  const currentLang = store && store.state && store.state.language ? store.state.language : 'zh-CN'
  
  // 提示用户（使用弹窗）
  const title = currentLang === 'en' ? 'Login Expired' : '登录已过期'
  const message = currentLang === 'en' 
    ? 'Your login has expired, please log in again.' 
    : '您的登录已过期，请重新登录。'
  const confirmButtonText = currentLang === 'en' ? 'OK' : '确定'
  
  // 显示弹窗
  ElMessageBox.alert(message, title, {
    confirmButtonText: confirmButtonText,
    type: 'warning',
    showClose: false,
    closeOnClickModal: false,
    closeOnPressEscape: false,
    callback: () => {
      // 点击确定后跳转到登录页，带上当前页面的 URL 作为 redirect 参数
      const currentPath = window.location.pathname + window.location.search
      window.location.href = `/login?redirect=${encodeURIComponent(currentPath)}`
    }
  }).catch(() => {
    // 如果用户关闭弹窗，也跳转到登录页
    const currentPath = window.location.pathname + window.location.search
    window.location.href = `/login?redirect=${encodeURIComponent(currentPath)}`
  })
}

/**
 * 快捷方法：检查并处理认证错误
 * @param {Error} error - 错误对象
 * @param {Object} store - Vuex store实例
 * @param {Response} response - fetch响应对象（可选）
 * @param {Object} result - 响应JSON数据（可选）
 * @returns {boolean} 是否处理了认证错误
 */
export function checkAndHandleAuthError(error, store, response = null, result = null) {
  if (isAuthError(error, response, result)) {
    handleAuthError(store)
    return true
  }
  return false
}

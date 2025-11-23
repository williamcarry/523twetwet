/**
 * 全局 Fetch 拦截插件
 * 
 * 功能：
 * 1. 重写全局 window.fetch，自动注入 Token 刷新机制
 * 2. 自动携带 Cookie 和必要的请求头
 * 3. 自动处理 401 错误并重试
 * 4. 启动 Token 定时自动刷新
 * 
 * 使用方法：
 * 在 main.js 或 App.vue 中导入并初始化
 * import { initGlobalFetch } from './plugins/globalFetch'
 * initGlobalFetch(store)
 */

import tokenRefreshService from '../services/tokenRefresh'

// 保存原始的 fetch 函数
const originalFetch = window.fetch

/**
 * 初始化全局 Fetch 拦截
 * @param {Object} store - Vuex store 实例
 */
export function initGlobalFetch(store) {
  // 保存 store 到 tokenRefreshService
  tokenRefreshService.store = store
  
  // 保存原始 fetch 到 tokenRefreshService，供重试时使用
  tokenRefreshService.originalFetch = originalFetch
  
  // 重写全局 fetch 函数
  window.fetch = async function(url, options = {}) {
    // 合并默认选项
    const fetchOptions = {
      credentials: 'include', // 始终携带 Cookie
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers
      },
      ...options
    }
    
    // 使用新的 API：传递 URL 和 options，而不是 Promise
    // 这样可以在重试时重新构建请求
    return tokenRefreshService.wrapFetch(url, fetchOptions, store)
  }
  
  // 保留原始 fetch 的引用，以便需要时使用
  window.originalFetch = originalFetch
}

/**
 * 在用户登录后调用，启动 Token 自动刷新
 * @param {Object} store - Vuex store 实例
 * @param {number} expiresIn - Token 有效期（秒），默认 7200（2小时）
 */
export function startTokenAutoRefresh(store, expiresIn = 7200) {
  tokenRefreshService.startAutoRefresh(store, expiresIn)
}

/**
 * 在用户登出时调用，停止 Token 自动刷新
 */
export function stopTokenAutoRefresh() {
  tokenRefreshService.stopAutoRefresh()
}

/**
 * 获取 Token 剩余有效时间
 * @returns {number} 剩余秒数
 */
export function getTokenRemainingTime() {
  return tokenRefreshService.getTokenRemainingTime()
}

export default {
  install(app, options = {}) {
    const { store } = options
    
    if (!store) {
      console.warn('未提供 Vuex store，全局 Fetch 拦截a器可能无法正常工作')
    }
    
    // 初始化全局拦截
    initGlobalFetch(store)
    
    // 将方法挂载到 app.config.globalProperties
    app.config.globalProperties.$startTokenAutoRefresh = startTokenAutoRefresh
    app.config.globalProperties.$stopTokenAutoRefresh = stopTokenAutoRefresh
    app.config.globalProperties.$getTokenRemainingTime = getTokenRemainingTime
  }
}

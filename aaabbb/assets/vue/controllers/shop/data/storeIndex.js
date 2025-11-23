// 商城前端Vuex状态管理入口文件
import store from './store.js';

// 导出store实例
export default store;

// 初始化store的函数
export const initStore = () => {
  console.log('[Shop Store] Vuex store initialized');
  return store;
};

// 重置store状态的函数
export const resetStore = () => {
  // 重置搜索相关状态
  store.commit('SET_SEARCH_QUERY', '');
  store.commit('SET_SEARCH_RESULTS', []);
  store.commit('SET_SEARCHING_STATUS', false);
  
  // 重置用户相关状态
  store.commit('SET_USER', null);
  
  // 重置购物车相关状态
  store.commit('CLEAR_CART');
  
  // 重置产品相关状态
  store.commit('SET_SELECTED_CATEGORY', null);
  store.commit('SET_SELECTED_SUB_CATEGORY', null);
  store.commit('SET_PRODUCTS', []);
  
  // 重置UI状态
  store.commit('SET_LOADING', false);
  store.commit('SET_SIDEBAR', false);
  store.commit('CLOSE_MODAL');
  
  console.log('[Shop Store] Vuex store reset completed');
};

// 获取store状态的函数
export const getStoreState = () => {
  return store.state;
};

// 订阅store变化的函数
export const subscribeToStore = (callback) => {
  return store.subscribe((mutation, state) => {
    callback(mutation, state);
  });
};
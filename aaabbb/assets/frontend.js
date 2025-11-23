import './bootstrap.js';
/*
 * Frontend (Shop) JavaScript entry file
 * 商城前台入口文件
 */

// 样式导入
import './styles/frontend.css';

// jQuery
import $ from 'jquery';
window.$ = window.JQuery = $;

// 添加Vue控制器组件注册
import { registerVueControllerComponents } from '@symfony/ux-vue';

// 引入 Element Plus 样式
import 'element-plus/dist/index.css';

// 引入并初始化Vuex store
import store from './vue/controllers/shop/data/storeIndex.js';

// 将store添加到window对象，以便在Vue组件中访问
window.vueStore = store;

// 初始化全局 Fetch 拦截器（自动处理 Token 刷新和 401 错误）
import { initGlobalFetch, startTokenAutoRefresh } from './vue/controllers/shop/plugins/globalFetch.js';
initGlobalFetch(store);

// 如果用户已登录，启动 Token 定时自动刷新
if (store.state.isLoggedIn) {
  startTokenAutoRefresh(store, 7200);
}

// 注册Vue控制器组件 - 必须从./vue/controllers/开始，这样组件名才能是shop/ComponentName
registerVueControllerComponents(require.context('./vue/controllers/', true, /\.vue$/));

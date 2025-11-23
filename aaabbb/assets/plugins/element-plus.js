/**
 * Element Plus 插件配置
 * 用于在 Symfony UX Vue 中注册 Element Plus 的全局指令和组件
 */
import { Loading } from 'element-plus'

export default {
  install(app) {
    // 注册 v-loading 指令
    app.directive('loading', Loading.directive)
  }
}

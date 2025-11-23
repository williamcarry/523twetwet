import { reactive, computed } from 'vue'

const stored = (typeof localStorage !== 'undefined' && localStorage.getItem('app.lang')) || 'zh-CN'

const state = reactive({ lang: (stored === 'en' ? 'en' : 'zh-CN') })

// 添加全局语言变化事件
let languageChangeCallbacks = []

const messages = {
  'zh-CN': {

  },
  en: {
  },
}

export const currentLang = computed(() => state.lang)

export function setLang(lang) {
  state.lang = lang
  try {
    localStorage.setItem('app.lang', lang)
  } catch {}
  if (typeof document !== 'undefined') document.documentElement.lang = lang
  
  // 触发全局语言变化事件
  triggerLanguageChange()
}

// 添加语言变化回调注册函数
export function onLanguageChange(callback) {
  languageChangeCallbacks.push(callback)
}

// 触发语言变化事件
function triggerLanguageChange() {
  languageChangeCallbacks.forEach(callback => {
    try {
      callback(state.lang)
    } catch (error) {
      console.error('Error in language change callback:', error)
    }
  })
  
  // 触发全局事件
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('languagechange', { detail: { lang: state.lang } }))
  }
}

export function t(key) {
  const lang = state.lang
  return messages[lang][key] ?? messages['zh-CN'][key] ?? key
}
<template>
  <div v-if="visible" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[9999] p-4" @click.self="close">
    <div class="bg-white rounded-lg max-w-2xl w-full max-h-[80vh] overflow-hidden shadow-2xl">
      <!-- 弹窗头部 -->
      <div class="sticky top-0 bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
          <h3 class="text-lg font-semibold text-slate-900">{{ t('modalTitle') }}</h3>
        </div>
        <button @click="close" class="text-slate-400 hover:text-slate-600 transition">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- 弹窗内容 -->
      <div class="p-6 overflow-y-auto max-h-[calc(80vh-80px)]">
        <!-- 加载状态 -->
        <div v-if="loading" class="text-center py-12">
          <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
          <p class="text-slate-500 text-sm mt-4">{{ t('loading') }}</p>
        </div>

        <!-- 错误状态 -->
        <div v-else-if="error" class="text-center py-12">
          <svg class="w-16 h-16 mx-auto text-red-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p class="text-red-600 font-medium mb-2">{{ t('queryFailed') }}</p>
          <p class="text-slate-500 text-sm">{{ error }}</p>
          <button @click="fetchTracking" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition text-sm">
            {{ t('retry') }}
          </button>
        </div>

        <!-- 物流信息 -->
        <div v-else-if="trackingData" class="space-y-4">
          <!-- 基本信息 -->
          <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
            <div class="grid grid-cols-2 gap-4 text-sm">
              <div>
                <span class="text-slate-600">{{ t('expressNo') }}:</span>
                <span class="ml-2 font-semibold text-slate-900">{{ expressNo }}</span>
              </div>
              <div>
                <span class="text-slate-600">{{ t('expressCompany') }}:</span>
                <span class="ml-2 font-semibold text-slate-900">{{ trackingData.companyName || '-' }}</span>
              </div>
              <div v-if="trackingData.statusText" class="col-span-2">
                <span class="text-slate-600">{{ t('currentStatus') }}:</span>
                <span class="ml-2 px-3 py-1 rounded-full text-sm font-semibold" :class="getStatusClass(trackingData.status)">
                  {{ getStatusText(trackingData.status) }}
                </span>
              </div>
            </div>
          </div>

          <!-- 最新动态 -->
          <div v-if="trackingData.latestMessage" class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex items-start gap-3">
              <svg class="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <div class="flex-1">
                <div class="text-xs text-green-700 font-medium mb-1">{{ t('latestUpdate') }}</div>
                <div class="text-sm text-green-900 font-semibold">{{ trackingData.latestMessage }}</div>
                <div v-if="trackingData.latestTime" class="text-xs text-green-600 mt-1">{{ trackingData.latestTime }}</div>
              </div>
            </div>
          </div>

          <!-- 物流轨迹 -->
          <div v-if="trackingData.traces && trackingData.traces.length > 0">
            <h4 class="text-sm font-semibold text-slate-900 mb-3 flex items-center gap-2">
              <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              {{ t('trackingTimeline') }}
            </h4>
            <div class="relative">
              <!-- 时间线 -->
              <div class="absolute left-4 top-2 bottom-2 w-0.5 bg-slate-200"></div>
              
              <div class="space-y-4">
                <div 
                  v-for="(trace, index) in trackingData.traces" 
                  :key="index" 
                  class="relative pl-10"
                >
                  <!-- 时间线节点 -->
                  <div 
                    class="absolute left-2.5 w-3 h-3 rounded-full border-2 bg-white"
                    :class="index === 0 ? 'border-blue-600' : 'border-slate-300'"
                  ></div>
                  
                  <!-- 内容 -->
                  <div 
                    class="p-3 rounded-lg border transition-all hover:shadow-sm"
                    :class="index === 0 ? 'bg-blue-50 border-blue-200' : 'bg-white border-slate-200'"
                  >
                    <div 
                      class="text-sm mb-1"
                      :class="index === 0 ? 'text-blue-900 font-semibold' : 'text-slate-900'"
                    >
                      {{ trace.description }}
                    </div>
                    <div class="flex items-center gap-4 text-xs" :class="index === 0 ? 'text-blue-600' : 'text-slate-500'">
                      <span class="flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ trace.time }}
                      </span>
                      <span v-if="trace.areaName" class="flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        {{ trace.areaName }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 无物流信息 -->
          <div v-else class="text-center py-8">
            <svg class="w-16 h-16 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
            </svg>
            <p class="text-slate-500 text-sm">{{ t('noTracking') }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  expressNo: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['update:visible', 'close'])

const loading = ref(false)
const error = ref('')
const trackingData = ref(null)

// 页面翻译数据
const translations = ref({})

// 当前语言 - 使用ref以便能响应事件更新
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/ExpressTrackingModal.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
  }
}

// 翻译函数 - 直接从页面特定的JSON文件读取
const t = (key, params = {}) => {
  // 获取当前语言，优先仌localStorage获取，否则使用默认值
  const lang = localStorage.getItem('app.lang') || currentLang.value
  
  // 从页面特定的翻译文件中获取翻译
  let text = key
  if (translations.value[lang] && translations.value[lang][key]) {
    text = translations.value[lang][key]
  }
  
  // 替换参数
  Object.keys(params).forEach(paramKey => {
    text = text.replace(`{${paramKey}}`, params[paramKey])
  })
  
  return text
}

// 监听语言变化事件
const handleLangChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  }
  // 重新加载翻译以确保语言切换时更新
  loadTranslations()
}

// 组件挂载时加载翻译
loadTranslations()

// 监听语言变化事件
if (typeof window !== 'undefined') {
  window.addEventListener('languagechange', handleLangChange)
}

// 组件卸载时移除事件监听器
if (typeof window !== 'undefined') {
  const cleanup = () => {
    window.removeEventListener('languagechange', handleLangChange)
  }
  // Vue 3 的 onBeforeUnmount 需要导入，这里使用简单的方式
  if (window.__VUE_DEVTOOLS_GLOBAL_HOOK__) {
    window.__VUE_DEVTOOLS_GLOBAL_HOOK__.on?.('unmount', cleanup)
  }
}

// 获取状态样式类
const getStatusClass = (status) => {
  const classMap = {
    'WAIT_ACCEPT': 'bg-yellow-100 text-yellow-800',
    'ACCEPT': 'bg-blue-100 text-blue-800',
    'TRANSPORT': 'bg-indigo-100 text-indigo-800',
    'DELIVERING': 'bg-purple-100 text-purple-800',
    'AGENT_SIGN': 'bg-green-100 text-green-800',
    'SIGN': 'bg-green-100 text-green-800',
    'FAILED': 'bg-red-100 text-red-800',
    'UNKNOWN': 'bg-slate-100 text-slate-800'
  }
  return classMap[status] || 'bg-slate-100 text-slate-800'
}

// 获取状态文本
const getStatusText = (status) => {
  const statusMap = {
    'WAIT_ACCEPT': t('statusWaitAccept'),
    'ACCEPT': t('statusAccept'),
    'TRANSPORT': t('statusTransport'),
    'DELIVERING': t('statusDelivering'),
    'AGENT_SIGN': t('statusAgentSign'),
    'SIGN': t('statusSign'),
    'FAILED': t('statusFailed'),
    'UNKNOWN': t('statusUnknown')
  }
  return statusMap[status] || status
}

// 查询物流信息
const fetchTracking = async () => {
  if (!props.expressNo) {
    error.value = t('expressNoEmpty')
    return
  }

  loading.value = true
  error.value = ''
  trackingData.value = null

  try {
    const response = await fetch(`/api/common/express/track?expressNo=${encodeURIComponent(props.expressNo)}`, {
      method: 'GET',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })

    const result = await response.json()

    if (result.success && result.data) {
      trackingData.value = result.data
    } else {
      error.value = result.message || t('queryFailedRetry')
    }
  } catch (err) {
    console.error('查询物流失败:', err)
    error.value = t('networkError')
  } finally {
    loading.value = false
  }
}

// 关闭弹窗
const close = () => {
  emit('update:visible', false)
  emit('close')
}

// 监听弹窗显示状态
watch(() => props.visible, (newVal) => {
  if (newVal && props.expressNo) {
    fetchTracking()
  }
})

// 监听快递单号变化
watch(() => props.expressNo, (newVal) => {
  if (props.visible && newVal) {
    fetchTracking()
  }
})
</script>

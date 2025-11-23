<!--
CSS 引用说明：
1. 全局样式：在 src/main.ts 中自动加载
   - src/assets/main.css (导入 src/assets/base.css)
     - @tailwind base, components, utilities (Tailwind CSS)
     - 全局 CSS 变量 (--color-*, --section-gap, --category-width 等)
   - Element Plus 样式 (element-plus/dist/index.css)
2. 页面局部样式：该文件底部的 <style scoped> 块
3. 导入的子组件样式：由各子组件的 <style scoped> 块提供
-->
<template>
  <div class="min-h-screen flex flex-col bg-slate-50">
    <SiteHeader />
    
    <div class="flex-1 py-8">
      <div class="mx-auto w-full max-w-[1500px] md:min-w-[1150px] px-4 md:px-0">
        <!-- 详情页面 -->
        <PublicMessageDetailPage
          v-if="selectedMessageId !== null"
          :message-id="selectedMessageId"
          @back="selectedMessageId = null"
        />

        <!-- 列表页面 -->
        <div v-else>
          <div class="bg-white rounded-lg border border-slate-200 shadow-sm">
            <!-- 页面标题 -->
            <div class="border-b border-slate-200 px-6 py-4">
              <h1 class="text-2xl font-semibold text-slate-900">{{ t('pageTitle') }}</h1>
              <p class="text-sm text-slate-500 mt-1">{{ t('pageSubtitle') }}</p>
            </div>

            <!-- 加载状态 -->
            <div v-if="loading" class="p-10 text-center text-slate-500">
              <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
              <p class="text-sm mt-4">{{ t('loading') }}</p>
            </div>

            <!-- 空状态 -->
            <div v-else-if="messages.length === 0" class="p-10 text-center text-slate-500">
              <div class="text-slate-400 mb-2">
                <svg class="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                </svg>
              </div>
              <p class="text-base">{{ t('noMessages') }}</p>
            </div>

            <!-- 列表内容 -->
            <template v-else>
              <ul class="divide-y divide-slate-200">
                <li 
                  v-for="message in messages" 
                  :key="message.id" 
                  class="p-6 hover:bg-slate-50 transition cursor-pointer"
                  @click="viewMessage(message.id)"
                >
                  <div class="flex gap-4">
                    <!-- 消息类型标签 -->
                    <div class="flex-shrink-0">
                      <span 
                        class="inline-block px-3 py-1 rounded-full text-xs font-medium"
                        :class="getTypeClass(message.messageType)"
                      >
                        {{ translateMessageType(message.messageTypeText) }}
                      </span>
                    </div>

                    <div class="flex-1 min-w-0">
                      <div class="flex items-start justify-between gap-4 mb-2">
                        <h3 class="text-base font-semibold text-slate-900 hover:text-primary transition">
                          {{ currentLang.startsWith('en') ? (message.titleEn || message.title) : message.title }}
                        </h3>
                        <span class="text-xs text-slate-500 whitespace-nowrap">
                          {{ formatDate(message.sendTime || message.createdAt) }}
                        </span>
                      </div>
                      <p class="text-sm text-slate-600 line-clamp-2 mb-3">
                        {{ currentLang.startsWith('en') ? (message.contentEn || message.content) : message.content }}
                      </p>
                      <div class="flex items-center justify-between">
                        <div class="text-xs text-slate-400">
                          {{ t('publishTime') }} {{ formatDateTime(message.sendTime || message.createdAt) }}
                        </div>
                        <button class="text-xs text-primary hover:text-primary-dark font-medium">
                          {{ t('viewDetail') }}
                        </button>
                      </div>
                    </div>
                  </div>
                </li>
              </ul>

              <!-- 分页 -->
              <div class="px-6 py-4 bg-slate-50 border-t border-slate-200">
                <div class="flex items-center justify-between mb-3">
                  <div class="text-sm text-slate-600">
                    {{ t('totalMessages') }} {{ pagination.totalItems }} {{ t('totalMessagesUnit') }} | {{ t('currentPage') }} {{ pagination.currentPage }} {{ t('currentPageSeparator') }} {{ pagination.totalPages }} {{ t('currentPageUnit') }}
                  </div>
                </div>
                <Pagination 
                  :current-page="pagination.currentPage"
                  :total-pages="pagination.totalPages"
                  :change-page="changePage"
                />
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>

    <SiteFooter />
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import SiteHeader from '@/components/SiteHeader.vue'
import SiteFooter from '@/components/SiteFooter.vue'
import PublicMessageDetailPage from '@/pages/PublicMessageDetailPage.vue'
import Pagination from '@/components/Pagination.vue'

// 页面翻译数据
const translations = ref({})

// 当前语言 - 使用ref以便能响应事件更新
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/PublicMessagePage.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
  }
}

// 翻译函数 - 直接从页面特定的JSON文件读取
const t = (key, params = {}) => {
  // 获取当前语言，优先从localStorage获取，否则使用默认值
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

// 翻译消息类型文本 - 映射后端返回的中文文本到对应语言
const translateMessageType = (messageTypeText) => {
  if (!messageTypeText) return ''
  
  const lang = localStorage.getItem('app.lang') || currentLang.value
  
  // 从翻译文件的 messageTypes 映射中获取翻译
  if (translations.value[lang] && translations.value[lang]['messageTypes'] && translations.value[lang]['messageTypes'][messageTypeText]) {
    return translations.value[lang]['messageTypes'][messageTypeText]
  }
  
  // 如果没有找到映射，返回原文本
  return messageTypeText
}

// 更新页面标题
const updatePageTitle = () => {
  const title = t('pageTitle')
  if (title && title !== 'pageTitle') {
    document.title = title
  }
}

// 监听语言变化事件
const handleLangChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  }
  // 重新加载翻译以确保语言切换时更新
  loadTranslations().then(() => {
    updatePageTitle()
  })
}

const selectedMessageId = ref(null)
const loading = ref(false)
const messages = ref([])
const pagination = ref({
  currentPage: 1,
  totalPages: 1,
  totalItems: 0,
  itemsPerPage: 20
})

// 加载消息列表
const loadMessages = async (page = 1) => {
  loading.value = true
  try {
    const response = await fetch(`/shop/api/public/messages/list?page=${page}`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json'
      }
    })

    const result = await response.json()

    if (result.success) {
      messages.value = result.data
      pagination.value = result.pagination
    } else {
      console.error('加载消息失败:', result.error)
    }
  } catch (error) {
    console.error('请求失败:', error)
  } finally {
    loading.value = false
  }
}

// 查看消息详情
const viewMessage = (id) => {
  selectedMessageId.value = id
}

// 改变页码
const changePage = (page) => {
  loadMessages(page)
  // 滚动到顶部
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// 获取消息类型样式
const getTypeClass = (messageType) => {
  const classes = {
    'mall_announcement': 'bg-blue-100 text-blue-700',
    'platform_message': 'bg-green-100 text-green-700',
  }
  return classes[messageType] || 'bg-slate-100 text-slate-700'
}

// 格式化日期（年/月/日）
const formatDate = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}/${month}/${day}`
}

// 格式化日期时间（年-月-日 时:分）
const formatDateTime = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day} ${hours}:${minutes}`
}

// 组件挂载时加载数据
onMounted(() => {
  // 先设置当前语言，再加载翻译
  const savedLang = localStorage.getItem('app.lang')
  if (savedLang) {
    currentLang.value = savedLang
  }
  
  // 初始加载翻译
  loadTranslations().then(() => {
    updatePageTitle()
  })
  
  loadMessages()
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

// 组件卸载时移除事件监听器
onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped></style>

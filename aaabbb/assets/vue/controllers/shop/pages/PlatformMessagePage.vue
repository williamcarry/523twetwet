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
  <div>
    <!-- 详情页面 -->
    <PlatformMessageDetailPage
      v-if="selectedMessageId !== null"
      :message-id="selectedMessageId"
      @back="selectedMessageId = null"
    />

    <!-- 列表页面 -->
    <div v-else class="bg-white rounded-b-lg border-b border-l border-r border-slate-200 overflow-hidden">
      <!-- 加载状态 -->
      <div v-if="loading" class="p-10 text-center text-slate-500">
        {{ t('loading') }}
      </div>

      <!-- 空状态 -->
      <div v-else-if="messages.length === 0" class="p-10 text-center text-slate-500">
        {{ t('noMessage') }}
      </div>

      <!-- 列表内容 -->
      <template v-else>
        <ul class="divide-y divide-slate-200">
          <li v-for="message in messages" :key="message.id" class="p-5 hover:bg-slate-50 transition">
            <div class="flex message-item-wrapper">
              <div class="flex-1">
                <div class="flex items-start message-header mb-2">
                  <!-- 未读小圆点 -->
                  <div v-if="!message.isRead" class="w-2 h-2 bg-primary rounded-full mt-2 flex-shrink-0"></div>
                  <!-- 已读时的占位空间，保持布局一致 -->
                  <div v-else class="w-2 h-2 mt-2 flex-shrink-0"></div>
                  <div class="flex-1">
                    <h3
                      @click="viewMessage(message.id)"
                      class="text-base font-semibold text-slate-900 hover:text-primary transition cursor-pointer"
                    >
                      {{ message.title }}
                    </h3>
                  </div>
                </div>
                <p class="text-sm text-slate-600 line-clamp-2 mb-3 ml-5">
                  {{ message.content }}
                </p>
                <div class="flex items-center justify-between ml-5">
                  <span class="text-xs text-slate-500">{{ formatDate(message.sendTime || message.createdAt) }}</span>
                  <button
                    @click="viewMessage(message.id)"
                    class="text-xs text-primary hover:text-primary font-medium"
                  >
                    {{ t('viewDetail') }}
                  </button>
                </div>
              </div>
            </div>
          </li>
        </ul>

        <!-- 分页 -->
        <div class="px-6 py-4 bg-slate-50">
          <div class="flex items-center justify-between mb-3">
            <div class="text-sm text-slate-600">
              {{ t('totalItems') }} {{ pagination.totalItems }} {{ t('totalItemsUnit') }} | {{ t('currentPage') }} {{ pagination.currentPage }} {{ t('currentPageUnit') }}
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
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import PlatformMessageDetailPage from '@/pages/PlatformMessageDetailPage.vue'
import Pagination from '@/components/Pagination.vue'

// 获取store实例
const store = window.vueStore

// 页面翻译数据
const translations = ref({})

// 当前语言 - 使用ref以便能响应事件更新
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/PlatformMessagePage.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
  }
}

// 翻译函数 - 直接从页面特定的JSON文件读取
const t = (key) => {
  // 获取当前语言，优先从localStorage获取，否则使用默认值
  const lang = localStorage.getItem('app.lang') || currentLang.value
  
  // 从页面特定的翻译文件中获取翻译
  if (translations.value[lang] && translations.value[lang][key]) {
    return translations.value[lang][key]
  }
  
  // 如果没有找到翻译，返回键名
  return key
}

// 监听语言变化事件
const handleLangChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  }
  // 重新加载翻译以确保语言切换时更新
  loadTranslations()
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

// 加载平台消息列表
const loadMessages = async (page = 1) => {
  loading.value = true
  try {
    const response = await fetch(`/shop/api/message-center/platform-messages?page=${page}`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include'
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
const viewMessage = async (id) => {
  // 先标记为已读
  await markAsRead(id)
  // 然后打开详情页
  selectedMessageId.value = id
}

// 标记为已读
const markAsRead = async (id) => {
  try {
    const response = await fetch(`/shop/api/message-center/mark-read/${id}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include'
    })

    const result = await response.json()
    
    if (result.success) {
      // 更新列表中的已读状态
      const msg = messages.value.find(m => m.id === id)
      if (msg) {
        msg.isRead = true
      }
    }
  } catch (error) {
    console.error('标记已读失败:', error)
  }
}

// 改变页码
const changePage = (page) => {
  loadMessages(page)
}

// 格式化日期
const formatDate = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}/${month}/${day}`
}

// 组件挂载时加载数据
onMounted(() => {
  // 初始加载翻译
  loadTranslations()
  
  // 加载消息列表
  loadMessages()
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

// 组件卸载时移除事件监听器
onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */

/* 消息列表项内容区 - gap-4 替代 */
.message-item-wrapper > *:not(:last-child) {
  margin-right: 1rem; /* 16px */
}

/* 消息标题区 - gap-3 替代 */
.message-header > *:not(:last-child) {
  margin-right: 0.75rem; /* 12px */
}
</style>

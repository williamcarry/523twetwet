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
  <div class="mx-auto w-full max-w-[1500px] md:min-w-[1150px] px-4 md:px-0">
    <!-- 整体包裹容器 -->
    <div class="bg-white rounded-b-lg border-x border-b border-slate-200">
      <!-- 页面标题和统计信息 -->
      <div class="p-6">
        <div class="mb-6">
          <h2 class="text-2xl font-semibold text-slate-900 mb-2">{{ t('pageTitle') }}</h2>
          <p class="text-slate-600 text-sm">{{ t('pageDescription') }}</p>
        </div>

        <!-- VIP下载额度统计卡片 -->
        <div class="bg-gradient-to-r from-orange-50 to-amber-50 border border-orange-200 rounded-lg p-4 mb-6">
          <div class="flex items-center justify-between">
            <div class="flex items-center stats-info">
              <div class="bg-white rounded-full p-2">
                <svg class="w-6 h-6 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
              </div>
              <div>
                <div class="text-sm font-medium text-slate-700">{{ t('downloadQuota') }}</div>
                <div class="text-lg font-bold text-slate-900 mt-1">
                  <span :class="downloadStats.downloadRemaining > 0 ? 'text-green-600' : 'text-red-600'">{{ downloadStats.downloadUsed }}</span>
                  <span class="text-slate-400 mx-1">/</span>
                  <span>{{ downloadStats.downloadQuota }}</span>
                </div>
              </div>
            </div>
            <div class="text-right">
              <div class="text-xs text-slate-600 mb-1">{{ t('vipLevel') }}</div>
              <div class="inline-flex items-center vip-badge bg-white px-3 py-1.5 rounded-full border border-orange-200">
                <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                </svg>
                <span class="text-sm font-medium text-slate-900">{{ currentLang === 'en' ? downloadStats.vipLevelNameEn : downloadStats.vipLevelName }}</span>
              </div>
            </div>
          </div>
          <div class="mt-3 flex items-center progress-row">
            <div class="flex-1 bg-white rounded-full h-2 overflow-hidden">
              <div 
                class="h-full transition-all duration-300"
                :class="downloadStats.downloadRemaining > 0 ? 'bg-green-500' : 'bg-red-500'"
                :style="{ width: `${(downloadStats.downloadUsed / downloadStats.downloadQuota * 100).toFixed(1)}%` }"
              ></div>
            </div>
            <span class="text-xs font-medium" :class="downloadStats.downloadRemaining > 0 ? 'text-green-600' : 'text-red-600'">
              {{ downloadStats.downloadRemaining > 0 ? t('remaining', { count: downloadStats.downloadRemaining }) : t('quotaExhausted') }}
            </span>
          </div>
        </div>

        <!-- 提示信息 -->
        <div class="bg-amber-50 border border-amber-200 rounded p-3 mb-6">
          <div class="flex tip-content">
            <div class="flex-shrink-0">
              <svg class="w-4 h-4 text-amber-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="flex-1">
              <p class="text-xs text-amber-800 leading-relaxed">
                <span class="font-medium">{{ t('warmTip') }}</span><br/>
                {{ t('warmTipContent1') }}<br/>
                {{ t('warmTipContent2') }}<br/>
                {{ t('warmTipContent3') }}
              </p>
            </div>
          </div>
        </div>

        <!-- 下载记录列表 -->
        <div class="mb-4">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-900">{{ t('downloadRecords') }}</h3>
            <button
              @click="refreshRecords"
              :disabled="loading"
              class="inline-flex items-center refresh-button px-4 py-2 bg-white border border-slate-300 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50 hover:border-primary hover:text-primary transition disabled:opacity-50 disabled:cursor-not-allowed"
              :title="t('refresh')"
            >
              <svg 
                class="w-4 h-4" 
                :class="{ 'animate-spin': loading }" 
                fill="none" 
                stroke="currentColor" 
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              {{ t('refresh') }}
            </button>
          </div>
          
          <!-- 加载状态 -->
          <div v-if="loading" class="text-center py-16">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
            <p class="text-slate-500 text-sm mt-4">{{ t('loading') }}</p>
          </div>

          <!-- 下载记录表格 -->
          <div v-else class="overflow-x-auto">
            <table class="w-full border-collapse bg-white">
              <thead>
                <tr class="bg-slate-100">
                  <th class="border border-slate-200 text-center px-4 py-3 text-sm font-medium text-slate-700">{{ t('tableHeaderId') }}</th>
                  <th class="border border-slate-200 text-left px-4 py-3 text-sm font-medium text-slate-700">{{ t('tableHeaderProduct') }}</th>
                  <th class="border border-slate-200 text-center px-4 py-3 text-sm font-medium text-slate-700">{{ t('tableHeaderTime') }}</th>
                  <th class="border border-slate-200 text-center px-4 py-3 text-sm font-medium text-slate-700">{{ t('tableHeaderVipLevel') }}</th>
                  <th class="border border-slate-200 text-center px-4 py-3 text-sm font-medium text-slate-700">{{ t('tableHeaderStatus') }}</th>
                  <th class="border border-slate-200 text-center px-4 py-3 text-sm font-medium text-slate-700">{{ t('tableHeaderAction') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="record in records" :key="record.id" class="hover:bg-slate-50 transition">
                  <td class="border border-slate-200 text-center px-4 py-3 text-sm text-slate-600">{{ record.id }}</td>
                  <td class="border border-slate-200 px-4 py-3 text-sm">
                    <a 
                      :href="`/shop/item/${record.productId}`" 
                      target="_blank"
                      class="font-medium text-primary hover:text-primary-dark hover:underline transition cursor-pointer"
                    >
                      {{ record.productName || t('noProductName') }}
                    </a>
                    <div class="text-xs text-slate-500 mt-1">ID: {{ record.productId }}</div>
                  </td>
                  <td class="border border-slate-200 text-center px-4 py-3 text-sm text-slate-600">{{ record.downloadTime }}</td>
                  <td class="border border-slate-200 text-center px-4 py-3 text-sm">
                    <span class="inline-flex items-center vip-level-badge px-2 py-1 bg-amber-50 text-amber-700 rounded-full text-xs font-medium">
                      <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                      </svg>
                      {{ record.vipLevel === 0 ? t('normalMember') : 'VIP' + record.vipLevel }}
                    </span>
                  </td>
                  <td class="border border-slate-200 text-center px-4 py-3 text-sm">
                    <span v-if="record.hasFile" class="inline-flex items-center status-ready px-2 py-1 bg-green-50 text-green-700 rounded-full text-xs font-medium">
                      <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                      </svg>
                      {{ t('statusReady') }}
                    </span>
                    <span v-else class="inline-flex items-center status-generating px-2 py-1 bg-yellow-50 text-yellow-700 rounded-full text-xs font-medium">
                      <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      {{ t('statusGenerating') }}
                    </span>
                  </td>
                  <td class="border border-slate-200 text-center px-4 py-3">
                    <button
                      v-if="record.hasFile"
                      @click="downloadFile(record)"
                      :disabled="downloading === record.id"
                      class="px-3 py-1.5 bg-primary text-white rounded text-sm hover:bg-primary-dark transition disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center download-button"
                    >
                      <svg v-if="downloading === record.id" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                      </svg>
                      {{ t('download') }}
                    </button>
                    <span v-else class="text-xs text-slate-400">{{ t('pleaseWait') }}</span>
                  </td>
                </tr>
                <tr v-if="records.length === 0">
                  <td colspan="6" class="border border-slate-200 text-center text-slate-500 py-8">
                    <svg class="w-12 h-12 mx-auto mb-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    {{ t('noRecords') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- 分页组件 -->
          <Pagination
            v-if="pagination.totalPages > 1"
            :currentPage="pagination.page"
            :totalPages="pagination.totalPages"
            :changePage="changePage"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { useStore } from 'vuex'
import Pagination from '../components/Pagination.vue'
import encryptionService from '../data/encryption-service.js'
import apiSignature from '../services/apiSignature.js'

const store = useStore()

// 页面翻译数据
const translations = ref({})

// 当前语言（从 localStorage 读取初始值）
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/DownloadCenterPage.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
  }
}

// 翻译函数
const t = (key, params = {}) => {
  const lang = currentLang.value
  let text = translations.value[lang]?.[key] || key
  
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
  loadTranslations()
}

// 数据状态
const loading = ref(false)
const records = ref([])
const downloading = ref(null)

// 下载统计
const downloadStats = ref({
  vipLevel: 0,
  vipLevelName: '普通会员',
  vipLevelNameEn: 'Normal Member',
  downloadQuota: 0,
  downloadUsed: 0,
  downloadRemaining: 0,
  year: new Date().getFullYear(),
  month: new Date().getMonth() + 1
})

// 分页数据
const pagination = ref({
  page: 1,
  pageSize: 20,
  total: 0,
  totalPages: 0
})

// 刷新下载记录
const refreshRecords = async () => {
  await fetchDownloadRecords()
  const lang = currentLang.value
  ElMessage.success(lang === 'en' ? 'Records refreshed' : '已刷新数据')
}

// 获取下载记录列表
const fetchDownloadRecords = async () => {
  loading.value = true
  
  try {
    // 准备请求数据
    const requestData = {
      page: pagination.value.page,
      pageSize: pagination.value.pageSize
    }
    
    // 加密数据
    const encryptedData = encryptionService.prepareData(requestData, true)
    
    // 生成签名
    const signedData = apiSignature.sign(encryptedData)
    
    // 发送请求
    const response = await fetch('/shop/api/download-center/list', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const result = await response.json()
    
    if (result.success) {
      records.value = result.data.list
      pagination.value = result.data.pagination
      downloadStats.value = result.data.downloadStats
    } else {
      const lang = currentLang.value
      ElMessage.error(lang === 'en' ? result.messageEn || result.message : result.message)
    }
  } catch (error) {
    console.error('获取下载记录失败:', error)
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'Failed to load download records' : '加载下载记录失败')
  } finally {
    loading.value = false
  }
}

// 下载文件
const downloadFile = async (record) => {
  downloading.value = record.id
  
  try {
    // 准备请求数据（使用 recordId 而不是 id）
    const requestData = {
      recordId: record.recordId, // 使用数据库ID而不是序列号
      lang: currentLang.value // 传递当前语言
    }
    
    // 加密数据
    const encryptedData = encryptionService.prepareData(requestData, true)
    
    // 生成签名
    const signedData = apiSignature.sign(encryptedData)
    
    // 发送请求
    const response = await fetch('/shop/api/download-center/generate-url', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const result = await response.json()
    
    if (result.success) {
      // 直接打开下载链接
      window.location.href = result.data.downloadUrl
      
      const lang = currentLang.value
      ElMessage.success(lang === 'en' ? 'Download started' : '开始下载')
    } else {
      const lang = currentLang.value
      ElMessage.error(lang === 'en' ? result.messageEn || result.message : result.message)
    }
  } catch (error) {
    console.error('生成下载链接失败:', error)
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'Failed to generate download link' : '生成下载链接失败')
  } finally {
    downloading.value = null
  }
}

// 切换页码
const changePage = (page) => {
  pagination.value.page = page
  fetchDownloadRecords()
}

// 页面挂载时加载数据
onMounted(() => {
  loadTranslations()
  fetchDownloadRecords()
  window.addEventListener('languagechange', handleLangChange)
})

// 组件卸载时移除监听器
import { onBeforeUnmount } from 'vue'
onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */

/* 统计信息区 - gap-3 替代 */
.stats-info > *:not(:last-child) {
  margin-right: 0.75rem; /* 12px */
}

/* VIP徽章 - gap-2 替代 */
.vip-badge > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 进度条行 - gap-2 替代 */
.progress-row > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 提示内容 - gap-2 替代 */
.tip-content > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 刷新按钮 - gap-2 替代 */
.refresh-button > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* VIP等级徽章 - gap-1 替代 */
.vip-level-badge > *:not(:last-child) {
  margin-right: 0.25rem; /* 4px */
}

/* 状态徽章（就绪） - gap-1 替代 */
.status-ready > *:not(:last-child) {
  margin-right: 0.25rem; /* 4px */
}

/* 状态徽章（生成中） - gap-1 替代 */
.status-generating > *:not(:last-child) {
  margin-right: 0.25rem; /* 4px */
}

/* 下载按钮 - gap-1 替代 */
.download-button > *:not(:last-child) {
  margin-right: 0.25rem; /* 4px */
}
</style>

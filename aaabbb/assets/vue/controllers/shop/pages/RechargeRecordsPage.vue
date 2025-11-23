<template>
  <div class="bg-white">
    <main class="p-6">
      <!-- 页面标题和筛选区域 -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-3xl font-bold text-gray-900">{{ t('pageTitle') }}</h1>
          <p class="mt-2 text-sm text-gray-600">{{ t('pageDescription') }}</p>
        </div>
        
        <!-- 状态筛选和刷新按钮 -->
        <div class="flex items-center recharge-filter-controls">
          <label class="text-sm font-medium text-gray-700">{{ t('statusLabel') }}:</label>
          <select 
            v-model="filters.status" 
            @change="handleFilterChange"
            class="px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent outline-none"
            style="margin-left: 1rem;"
          >
            <option value="all">{{ t('statusAll') }}</option>
            <option value="pending">{{ t('statusPending') }}</option>
            <option value="processing">{{ t('statusProcessing') }}</option>
            <option value="completed">{{ t('statusCompleted') }}</option>
            <option value="failed">{{ t('statusFailed') }}</option>
          </select>
          
          <!-- 刷新按钮 -->
          <button 
            @click="handleRefresh"
            :disabled="loading"
            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center"
            style="margin-left: 1rem;"
          >
            <svg 
              :class="{ 'animate-spin': loading }" 
              class="w-4 h-4" 
              fill="none" 
              stroke="currentColor" 
              viewBox="0 0 24 24"
              style="margin-right: 0.5rem;"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ t('refreshButton') }}
          </button>
        </div>
      </div>

      <!-- 加载中状态 -->
      <div v-if="loading" class="flex justify-center items-center py-12">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-red-600"></div>
      </div>

      <!-- 充值记录列表 -->
      <div v-else-if="rechargeList.length > 0" class="space-y-3">
        <div 
          v-for="record in rechargeList" 
          :key="record.id"
          class="bg-white rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 border border-red-600"
        >
          <div class="p-4">
            <!-- 顶部信息：订单号和状态 -->
            <div class="flex items-center justify-between mb-3 pb-3 border-b border-gray-200">
              <div class="flex items-center recharge-order-info">
                <span class="text-sm text-gray-500">{{ t('orderNo') }}:</span>
                <span class="font-mono text-sm font-medium text-gray-900" style="margin-left: 0.5rem;">{{ record.orderNo }}</span>
              </div>
              <span 
                :class="getStatusClass(record.status)"
                class="px-2 py-1 rounded-full text-sm font-medium"
              >
                {{ record.statusText }}
              </span>
            </div>

            <!-- 主要信息网格 -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 mb-3 recharge-info-grid">
              <!-- 充值金额 -->
              <div>
                <div class="text-sm text-gray-500 mb-0.5">{{ t('amount') }}</div>
                <div class="text-lg font-bold text-red-600">{{ currencySymbol }}{{ record.amount }}</div>
              </div>

              <!-- 支付方式 -->
              <div>
                <div class="text-sm text-gray-500 mb-0.5">{{ t('paymentMethod') }}</div>
                <div class="text-sm text-gray-900">{{ getPaymentMethodText(record.paymentMethod) }}</div>
              </div>

              <!-- 创建时间 -->
              <div>
                <div class="text-sm text-gray-500 mb-0.5">{{ t('createdAt') }}</div>
                <div class="text-sm text-gray-900">{{ record.createdAt }}</div>
              </div>

              <!-- 支付时间 -->
              <div>
                <div class="text-sm text-gray-500 mb-0.5">{{ t('paidAt') }}</div>
                <div class="text-sm text-gray-900">
                  {{ record.paidAt || t('notPaid') }}
                </div>
              </div>
            </div>

            <!-- 底部附加信息 -->
            <div class="flex flex-wrap text-sm text-gray-500 recharge-extra-info">
              <div v-if="record.paymentTransactionId">
                <span>{{ t('paymentTransactionId') }}: </span>
                <span class="font-mono">{{ record.paymentTransactionId }}</span>
              </div>
              <div v-if="record.updatedAt">
                <span>{{ t('updatedAt') }}: </span>
                <span>{{ record.updatedAt }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 空状态 -->
      <div v-else class="bg-white rounded-lg shadow-sm p-12 text-center">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">{{ t('noRecords') }}</h3>
        <p class="mt-1 text-sm text-gray-500">{{ t('noRecordsDescription') }}</p>
      </div>

      <!-- 分页组件 -->
      <Pagination 
        v-if="pagination.totalPages > 1"
        :current-page="pagination.page"
        :total-pages="pagination.totalPages"
        :change-page="changePage"
      />
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import Pagination from '../components/Pagination.vue'
import encryptionService from '../data/encryption-service.js'
import apiSignature from '../services/apiSignature.js'

// 页面翻译数据
const translations = ref({})

// 当前语言
const currentLang = ref('zh-CN')

// 货币符号，默认$
const currencySymbol = ref('$')

// 充值记录列表
const rechargeList = ref([])

// 加载状态
const loading = ref(false)

// 筛选条件
const filters = ref({
  status: 'all'
})

// 分页信息
const pagination = ref({
  page: 1,
  pageSize: 20,
  total: 0,
  totalPages: 0
})

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/RechargeRecordsPage.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
  }
}

// 翻译函数
const t = (key) => {
  const lang = localStorage.getItem('app.lang') || currentLang.value
  
  if (translations.value[lang] && translations.value[lang][key]) {
    return translations.value[lang][key]
  }
  
  return key
}

// 监听语言变化事件
const handleLangChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  }
  loadTranslations()
}

// 获取支付方式翻译文本
const getPaymentMethodText = (method) => {
  const methodKey = `paymentMethod${method.charAt(0).toUpperCase() + method.slice(1)}`
  return t(methodKey) || method
}

// 获取状态样式类
const getStatusClass = (status) => {
  const statusClasses = {
    pending: 'bg-yellow-100 text-yellow-800',
    processing: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800'
  }
  return statusClasses[status] || 'bg-gray-100 text-gray-800'
}

// 获取充值记录列表
const fetchRechargeList = async () => {
  loading.value = true
  
  try {
    const requestData = {
      page: pagination.value.page,
      pageSize: pagination.value.pageSize
    }
    
    if (filters.value.status && filters.value.status !== 'all') {
      requestData.status = filters.value.status
    }
    
    // 使用加密服务准备数据
    const encryptedData = encryptionService.prepareData(requestData, true)
    // 使用签名服务签名
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/recharge/list', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })

    const result = await response.json()

    if (result.success) {
      rechargeList.value = result.data.list
      pagination.value = result.data.pagination
      // 获取货币符号
      if (result.data.currencySymbol) {
        currencySymbol.value = result.data.currencySymbol
      }
    } else {
      ElMessage.error(result.message || '加载充值记录失败')
    }
  } catch (error) {
    console.error('Error fetching recharge records:', error)
    ElMessage.error('网络错误，请重试')
  } finally {
    loading.value = false
  }
}

// 刷新数据
const handleRefresh = () => {
  fetchRechargeList()
}

// 筛选变化处理
const handleFilterChange = () => {
  pagination.value.page = 1
  fetchRechargeList()
}

// 分页切换
const changePage = (page) => {
  pagination.value.page = page
  fetchRechargeList()
  
  // 滚动到页面顶部
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(() => {
  loadTranslations()
  fetchRechargeList()
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
/* Tailwind CSS 已包含所有必要样式 */

/* 确保筛选控件间距 */
.recharge-filter-controls > label {
  margin-right: 1rem;
}

.recharge-filter-controls > select {
  margin-left: 0;
}

/* 确保按钮内图标和文字间距 */
.recharge-filter-controls button svg {
  margin-right: 0.5rem;
}
</style>

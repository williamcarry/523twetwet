<template>
  <div class="bg-white">
    <main class="p-6">
      <!-- 页面标题和筛选区域 -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-3xl font-bold text-gray-900">{{ t('pageTitle') }}</h1>
          <p class="mt-2 text-sm text-gray-600">{{ t('pageDescription') }}</p>
        </div>
        
        <!-- 筛选和刷新按钮 -->
        <div class="flex items-center filter-and-refresh-wrapper">
          <label class="text-sm font-medium text-gray-700">{{ t('transactionType') }}:</label>
          <select 
            v-model="filters.type" 
            @change="handleFilterChange"
            class="px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent outline-none"
            style="margin-left: 1rem;"
          >
            <option value="">{{ t('typeAll') }}</option>
            <option value="recharge">{{ t('typeRecharge') }}</option>
            <option value="withdraw">{{ t('typeWithdraw') }}</option>
            <option value="order_payment">{{ t('typeOrderPayment') }}</option>
            <option value="order_refund">{{ t('typeOrderRefund') }}</option>
            <option value="admin_add">{{ t('typeAdminAdd') }}</option>
            <option value="admin_deduct">{{ t('typeAdminDeduct') }}</option>
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

      <!-- 余额显示区域 -->
      <div class="bg-white rounded-lg shadow-sm border border-red-600 p-4 mb-6">
        <div class="flex items-center justify-between">
          <div class="flex items-center balance-info-row">
            <div>
              <span class="text-sm text-gray-500">{{ t('availableBalance') }}:</span>
              <span class="text-2xl font-bold text-gray-900 ml-2">{{ currencySymbol }}{{ currentBalance }}</span>
            </div>
            <div class="border-l border-gray-300 pl-8">
              <span class="text-sm text-gray-500">{{ t('frozenBalance') }}:</span>
              <span class="text-2xl font-bold text-orange-600 ml-2">{{ currencySymbol }}{{ frozenBalance }}</span>
            </div>
          </div>
          
          <!-- 充值和提现按钮 -->
          <div class="flex items-center balance-action-btns">
            <button 
              @click="showRechargeDialog"
              :disabled="isLoadingRecharge"
              class="px-4 py-2 border border-red-600 text-red-600 rounded-md hover:bg-red-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center"
              style="background-color: ButtonFace !important;"
            >
              <svg v-if="isLoadingRecharge" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" style="margin-right: 0.5rem;">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 714 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span>{{ t('rechargeButton') }}</span>
            </button>
            <button 
              @click="showWithdrawDialog"
              :disabled="isLoadingWithdraw"
              class="px-4 py-2 border border-orange-500 text-orange-500 rounded-md hover:bg-orange-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center"
              style="margin-left: 1rem; background-color: ButtonFace !important;"
            >
              <svg v-if="isLoadingWithdraw" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" style="margin-right: 0.5rem;">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 714 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span>{{ t('withdrawButton') }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- 加载中状态 -->
      <div v-if="loading" class="flex justify-center items-center py-12">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-red-600"></div>
      </div>

      <!-- 余额记录列表 -->
      <div v-else-if="pagedRows.length > 0" class="space-y-3">
        <div 
          v-for="record in pagedRows" 
          :key="record.id"
          class="bg-white rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 border border-red-600"
        >
          <div class="p-4">
            <!-- 顶部信息：时间和类型 -->
            <div class="flex items-center justify-between mb-3 pb-3 border-b border-gray-200">
              <div class="flex items-center record-time-info">
                <span class="text-sm text-gray-500">{{ t('tableHeaderTime') }}:</span>
                <span class="text-sm font-medium text-gray-900" style="margin-left: 0.5rem;">{{ record.createdAt }}</span>
              </div>
              <span class="px-2 py-1 rounded-full text-sm font-medium" :class="getTypeStatusClass(record.type)">
                {{ record.typeLabel }}
              </span>
            </div>

            <!-- 主要信息网格 -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 mb-3 record-info-grid">
              <!-- 交易金额 -->
              <div>
                <div class="text-sm text-gray-500 mb-0.5">{{ t('tableHeaderAmount') }}</div>
                <div class="text-lg font-bold" :class="getAmountColorClass(record.amount)">{{ formatAmount(record.amount) }}</div>
              </div>

              <!-- 变动前余额 -->
              <div>
                <div class="text-sm text-gray-500 mb-0.5">{{ t('tableHeaderBalanceBefore') }}</div>
                <div class="text-sm text-gray-900">{{ currencySymbol }}{{ record.balanceBefore }}</div>
              </div>

              <!-- 变动后余额 -->
              <div>
                <div class="text-sm text-gray-500 mb-0.5">{{ t('tableHeaderBalanceAfter') }}</div>
                <div class="text-sm text-gray-900">{{ currencySymbol }}{{ record.balanceAfter }}</div>
              </div>

              <!-- 冻结金额 -->
              <div>
                <div class="text-sm text-gray-500 mb-0.5">{{ t('tableHeaderFrozenAmount') }}</div>
                <div class="text-sm text-gray-900">{{ currencySymbol }}{{ record.frozenAmount || '0.00' }}</div>
              </div>
            </div>

            <!-- 描述信息 -->
            <div v-if="record.description || record.reason" class="mb-3">
              <span class="text-sm text-gray-500">{{ t('tableHeaderDescription') }}:</span>
              <span class="text-sm text-gray-700 ml-2">{{ record.description }}</span>
              <span v-if="record.reason" class="text-sm text-gray-700">
                <span v-if="record.description"> - </span>
                <span class="font-medium">{{ record.reason }}</span>
              </span>
            </div>

            <!-- 关联订单 -->
            <div v-if="record.referenceId" class="flex flex-wrap text-sm text-gray-500 record-reference-info">
              <div>
                <span>{{ t('tableHeaderReference') }}: </span>
                <span class="font-mono">{{ record.referenceId }}</span>
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
        <h3 class="mt-2 text-sm font-medium text-gray-900">{{ t('noData') }}</h3>
        <p class="mt-1 text-sm text-gray-500">{{ t('noDataDescription') }}</p>
      </div>

      <!-- 分页组件 -->
      <Pagination 
        v-if="totalPages > 1"
        :current-page="currentPage" 
        :total-pages="totalPages" 
        :change-page="changePage" 
      />
    </main>

    <!-- 充值对话框 -->
    <ElDialog
      v-model="rechargeDialogVisible"
      :title="t('rechargeDialogTitle')"
      width="600px"
      :close-on-click-modal="false"
      :close-on-press-escape="false"
    >
      <div class="recharge-dialog-content">
        <!-- 充值金额输入 -->
        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-2">
            {{ t('rechargeAmountLabel') }}
          </label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500 text-lg">{{ currencySymbol }}</span>
            <input
              v-model.number="rechargeAmount"
              type="number"
              min="1"
              step="0.01"
              class="w-full pl-8 pr-4 py-3 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent outline-none text-lg"
              :placeholder="t('rechargeAmountPlaceholder')"
            />
          </div>
          <p class="mt-2 text-sm text-gray-500">{{ t('rechargeAmountHint', { currency: currencySymbol }) }}</p>
        </div>

        <!-- 快捷金额选择 -->
        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-2">
            {{ t('quickAmountLabel') }}
          </label>
          <div class="grid grid-cols-4 quick-amount-grid-recharge">
            <button
              v-for="amount in quickAmounts"
              :key="amount"
              @click="rechargeAmount = amount"
              class="px-4 py-2 border border-gray-300 rounded-md hover:border-red-600 hover:text-red-600 transition-colors"
              :class="{ 'border-red-600 text-red-600 bg-red-50': rechargeAmount === amount }"
              style="background-color: ButtonFace !important;"
            >
              {{ currencySymbol }}{{ amount }}
            </button>
          </div>
        </div>

        <!-- Payoneer 支付组件 -->
        <div v-if="rechargeAmount > 0">
          <PayoneerPayment
            payment-type="recharge"
            :amount="rechargeAmount"
            :customer-email="userEmail"
            :customer-first-name="userFirstName"
            :customer-last-name="userLastName"
            :currency="currencyCode"
            :currency-symbol="currencySymbol"
            @payment-success="handleRechargeSuccess"
            @payment-error="handleRechargeError"
          />
        </div>
        <div v-else class="text-center py-8 text-gray-500">
          {{ t('pleaseEnterAmount') }}
        </div>
      </div>
    </ElDialog>

    <!-- 提现对话框 -->
    <ElDialog
      v-model="withdrawDialogVisible"
      :title="t('withdrawDialogTitle')"
      width="600px"
      :close-on-click-modal="false"
      :close-on-press-escape="false"
    >
      <div class="withdraw-dialog-content">
        <!-- 提现金额输入 -->
        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-2">
            {{ t('withdrawAmountLabel') }}
          </label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500 text-lg">{{ currencySymbol }}</span>
            <input
              v-model.number="withdrawAmount"
              type="number"
              min="1"
              step="0.01"
              :max="parseFloat(currentBalance)"
              class="w-full pl-8 pr-4 py-3 border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none text-lg"
              :placeholder="t('withdrawAmountPlaceholder')"
            />
          </div>
          <p class="mt-2 text-sm text-gray-500">
            {{ t('withdrawAmountHint', { currency: currencySymbol, balance: currentBalance }) }}
          </p>
        </div>

        <!-- 快捷金额选择 -->
        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-2">
            {{ t('quickAmountLabel') }}
          </label>
          <div class="grid grid-cols-4 quick-amount-grid-withdraw">
            <button
              v-for="amount in getAvailableWithdrawAmounts()"
              :key="amount"
              @click="withdrawAmount = amount"
              class="px-4 py-2 border border-gray-300 rounded-md hover:border-orange-500 hover:text-orange-500 transition-colors"
              :class="{ 'border-orange-500 text-orange-500 bg-orange-50': withdrawAmount === amount }"
              style="background-color: ButtonFace !important;"
            >
              {{ currencySymbol }}{{ amount }}
            </button>
            <button
              @click="withdrawAmount = parseFloat(currentBalance)"
              class="px-4 py-2 border border-gray-300 rounded-md hover:border-orange-500 hover:text-orange-500 transition-colors"
              :class="{ 'border-orange-500 text-orange-500 bg-orange-50': withdrawAmount === parseFloat(currentBalance) }"
              style="background-color: ButtonFace !important;"
            >
              {{ t('withdrawAll') }}
            </button>
          </div>
        </div>

        <!-- 提现账户信息 -->
        <div v-if="withdrawAmount > 0" class="mb-6 bg-gray-50 p-4 rounded-lg">
          <!-- Payoneer账号 -->
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              {{ t('payoneerAccount') }} *
            </label>
            <input
              v-model="withdrawInfo.payoneerAccount"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none"
              :placeholder="t('payoneerAccountPlaceholder')"
            />
          </div>

          <!-- 提交按钮 -->
          <button
            @click="handleWithdrawSubmit"
            :disabled="!isWithdrawFormValid || isWithdrawProcessing"
            class="w-full px-4 py-3 bg-orange-500 text-white rounded-md hover:bg-orange-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
          >
            <svg v-if="isWithdrawProcessing" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>{{ isWithdrawProcessing ? t('withdrawProcessing') : t('withdrawSubmitButton') }}</span>
          </button>
        </div>
        <div v-else class="text-center py-8 text-gray-500">
          {{ t('pleaseEnterWithdrawAmount') }}
        </div>
      </div>
    </ElDialog>

    <!-- 离线提现对话框 -->
    <ElDialog
      v-model="offlineWithdrawDialogVisible"
      :title="t('offlineWithdrawDialogTitle')"
      width="600px"
      :close-on-click-modal="false"
      :close-on-press-escape="false"
    >
      <div class="withdraw-dialog-content">
        <!-- 提现金额输入 -->
        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-2">
            {{ t('offlineWithdrawAmountLabel') }}
          </label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500 text-lg">{{ currencySymbol }}</span>
            <input
              v-model.number="offlineWithdrawAmount"
              type="number"
              min="1"
              step="0.01"
              :max="parseFloat(currentBalance)"
              class="w-full pl-8 pr-4 py-3 border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none text-lg"
              :placeholder="t('offlineWithdrawAmountPlaceholder')"
            />
          </div>
          <p class="mt-2 text-sm text-orange-600">
            {{ t('offlineWithdrawAmountHint', { currency: currencySymbol, balance: currentBalance }) }}
          </p>
        </div>

        <!-- 快捷金额选择 -->
        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-2">
            {{ t('quickAmountLabel') }}
          </label>
          <div class="grid grid-cols-4 quick-amount-grid-offline">
            <button
              v-for="amount in getAvailableWithdrawAmounts()"
              :key="amount"
              @click="offlineWithdrawAmount = amount"
              class="px-4 py-2 border border-gray-300 rounded-md hover:border-orange-500 hover:text-orange-500 transition-colors"
              :class="{ 'border-orange-500 text-orange-500 bg-orange-50': offlineWithdrawAmount === amount }"
              style="background-color: ButtonFace !important;"
            >
              {{ currencySymbol }}{{ amount }}
            </button>
            <button
              @click="offlineWithdrawAmount = parseFloat(currentBalance)"
              class="px-4 py-2 border border-gray-300 rounded-md hover:border-orange-500 hover:text-orange-500 transition-colors"
              :class="{ 'border-orange-500 text-orange-500 bg-orange-50': offlineWithdrawAmount === parseFloat(currentBalance) }"
              style="background-color: ButtonFace !important;"
            >
              {{ t('withdrawAll') }}
            </button>
          </div>
        </div>

        <!-- 收款信息 -->
        <div v-if="offlineWithdrawAmount > 0" class="mb-6 bg-orange-50 p-4 rounded-lg">
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              {{ t('offlinePaymentInfo') }} *
            </label>
            <textarea
              v-model="offlineWithdrawInfo.paymentInfo"
              rows="4"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none resize-none"
              :placeholder="t('offlinePaymentInfoPlaceholder')"
            ></textarea>
            <p class="mt-2 text-sm text-gray-500">
              {{ t('offlinePaymentInfoHint') }}
            </p>
          </div>

          <!-- 提交按钮 -->
          <button
            @click="handleOfflineWithdrawSubmit"
            :disabled="!isOfflineWithdrawFormValid || isOfflineWithdrawProcessing"
            class="w-full px-4 py-3 bg-orange-500 text-white rounded-md hover:bg-orange-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center offline-withdraw-submit-btn"
          >
            <svg v-if="isOfflineWithdrawProcessing" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 714 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>{{ isOfflineWithdrawProcessing ? t('offlineWithdrawProcessing') : t('offlineWithdrawSubmitButton') }}</span>
          </button>
        </div>
        <div v-else class="text-center py-8 text-gray-500">
          {{ t('pleaseEnterWithdrawAmount') }}
        </div>
      </div>
    </ElDialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref, onMounted, onBeforeUnmount, inject } from 'vue'
import { ElMessage, ElDialog } from 'element-plus'
import Pagination from '../components/Pagination.vue'
import PayoneerPayment from '../components/PayoneerPayment.vue'
import encryptionService from '../data/encryption-service.js'
import apiSignature from '../services/apiSignature.js'

// 注入store
const store = inject('store', null)

// 页面翻译数据
const translations = ref({})

// 当前语言 - 使用ref以便能响应事件更新
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/BalanceHistoryPage.json')
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

// 监听语言变化事件
const handleLangChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  }
  // 重新加载翻译以确保语言切换时更新
  loadTranslations()
}

// 原始数据
const allRows = ref([])
const loading = ref(false)
const currentBalance = ref('0.00')
const frozenBalance = ref('0.00')
const totalPages = ref(0)
const currencySymbol = ref('$') // 货币符号，默认$
const currencyCode = ref('USD') // 货币代码，默认USD

// 筛选条件
const filters = reactive({
  type: '',
  startDate: '',
  endDate: '',
})

const currentPage = ref(1)
const pageSize = ref(20)

// 充值对话框
const rechargeDialogVisible = ref(false)
const rechargeAmount = ref(0)
const quickAmounts = [10, 20, 50, 100, 200, 500, 1000, 2000]

// 提现对话框
const withdrawDialogVisible = ref(false)
const withdrawAmount = ref(0)
const isWithdrawProcessing = ref(false)
const withdrawInfo = reactive({
  payoneerAccount: ''
})

// 离线提现对话框
const offlineWithdrawDialogVisible = ref(false)
const offlineWithdrawAmount = ref(0)
const isOfflineWithdrawProcessing = ref(false)
const offlineWithdrawInfo = reactive({
  paymentInfo: ''
})

// 提现配置
const withdrawalConfig = ref({
  withdrawalMode: 'offline',
  isOnlinePayment: false,
  isOfflinePayment: true
})

// 用户信息
const userEmail = ref('')
const userFirstName = ref('')
const userLastName = ref('')
const userBalance = ref('0.00')

// 加载状态 - 分别为充值和提现按钮设置独立的加载状态
const isLoadingRecharge = ref(false)
const isLoadingWithdraw = ref(false)

// 直接使用后端分页数据
const pagedRows = computed(() => allRows.value)

// 刷新数据
const handleRefresh = () => {
  loadBalance()
  loadRecords()
}

// 筛选变化处理
const handleFilterChange = () => {
  currentPage.value = 1
  loadRecords()
}

// 搜索
function onSearch() {
  currentPage.value = 1
  loadRecords()
}

// 分页切换
const changePage = (page) => {
  currentPage.value = page
  loadRecords()
}

// 加载余额信息
const loadBalance = async () => {
  try {
    const requestData = {}
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/balance-history/balance', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      currentBalance.value = parseFloat(data.data.balance || 0).toFixed(2)
      frozenBalance.value = parseFloat(data.data.frozenBalance || 0).toFixed(2)
      // 获取货币符号
      if (data.data.currencySymbol) {
        currencySymbol.value = data.data.currencySymbol
      }
      // 获取货币代码
      if (data.data.currencyCode) {
        currencyCode.value = data.data.currencyCode
      }
    }
  } catch (error) {
    console.error('加载余额信息失败:', error)
  }
}

// 加载余额记录
const loadRecords = async () => {
  loading.value = true
  try {
    const requestData = {
      page: currentPage.value,
      pageSize: pageSize.value
    }
    
    if (filters.type) {
      requestData.type = filters.type
    }
    
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/balance-history/list', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      allRows.value = data.data.list
      totalPages.value = data.data.pagination.totalPages
    } else {
      ElMessage.error(data.message || '加载余额记录失败')
    }
  } catch (error) {
    console.error('加载余额记录失败:', error)
    ElMessage.error('网络错误，请重试')
  } finally {
    loading.value = false
  }
}

// 格式化金额显示
const formatAmount = (amount) => {
  const num = parseFloat(amount || 0)
  if (num > 0) {
    return '+' + currencySymbol.value + num.toFixed(2)
  } else if (num < 0) {
    return '-' + currencySymbol.value + Math.abs(num).toFixed(2)
  }
  return currencySymbol.value + num.toFixed(2)
}

// 获取金额颜色样式
const getAmountColorClass = (amount) => {
  const num = parseFloat(amount || 0)
  if (num > 0) return 'text-green-600'
  if (num < 0) return 'text-red-600'
  return ''
}

// 获取业务类型状态样式类
const getTypeStatusClass = (type) => {
  const typeClasses = {
    'recharge': 'bg-green-100 text-green-800',           // 充值成功 - 绿色
    'withdraw': 'bg-orange-100 text-orange-800',         // 提现 - 橙色
    'order_payment': 'bg-blue-100 text-blue-800',        // 订单支付 - 蓝色
    'order_refund': 'bg-purple-100 text-purple-800',     // 订单退款成功 - 紫色
    'admin_add': 'bg-green-100 text-green-800',          // 后台增加余额 - 绿色
    'admin_deduct': 'bg-red-100 text-red-800',           // 后台减少余额 - 红色
    'commission': 'bg-cyan-100 text-cyan-800',           // 佣金 - 青色
    'vip_purchase': 'bg-yellow-100 text-yellow-800',     // VIP购买 - 黄色
    'system': 'bg-indigo-100 text-indigo-800'            // 系统调整 - 靛蓝色
  }
  return typeClasses[type] || 'bg-blue-100 text-blue-800'
}

// 页面加载
onMounted(() => {
  // 初始加载翻译
  loadTranslations()
  
  loadBalance()
  loadRecords()
  loadWithdrawalConfig() // 加载提现配置
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

// 显示充值对话框
const showRechargeDialog = async () => {
  // 显示加载状态
  isLoadingRecharge.value = true
  
  try {
    // 获取最新的用户信息
    const userInfo = await fetchUserProfile()
    
    if (!userInfo) {
      ElMessage.error(t('loadUserInfoFailed'))
      return
    }
    
    // 检查是否有邮箱
    if (!userInfo.email) {
      ElMessage.warning(t('emailRequiredForRecharge'))
      return
    }
    
    // 更新用户信息
    userEmail.value = userInfo.email
    userFirstName.value = userInfo.nickname || userInfo.username
    userLastName.value = ''
    userBalance.value = parseFloat(userInfo.balance || 0).toFixed(2)
    currentBalance.value = userBalance.value
    
    // 打开充值对话框
    rechargeDialogVisible.value = true
  } catch (error) {
    console.error('获取用户信息失败:', error)
    ElMessage.error(t('loadUserInfoFailed'))
  } finally {
    isLoadingRecharge.value = false
  }
}

// 显示提现对话框
const showWithdrawDialog = async () => {
  // 显示加载状态
  isLoadingWithdraw.value = true
  
  try {
    // 获取最新的用户信息
    const userInfo = await fetchUserProfile()
    
    if (!userInfo) {
      ElMessage.error(t('loadUserInfoFailed'))
      return
    }
    
    // 检查是否有邮箱
    if (!userInfo.email) {
      ElMessage.warning(t('emailRequiredForWithdraw'))
      return
    }
    
    // 检查余额
    const balance = parseFloat(userInfo.balance || 0)
    if (balance <= 0) {
      ElMessage.warning(t('insufficientBalance'))
      return
    }
    
    // 更新用户信息
    userEmail.value = userInfo.email
    userFirstName.value = userInfo.nickname || userInfo.username
    userLastName.value = ''
    userBalance.value = balance.toFixed(2)
    currentBalance.value = userBalance.value
    
    // 根据配置显示不同的提现对话框
    if (withdrawalConfig.value.isOfflinePayment) {
      // 离线提现（手动打款）
      offlineWithdrawDialogVisible.value = true
    } else {
      // 在线提现（Payoneer）
      // 设置提现账户名称默认值
      if (!withdrawInfo.payoneerAccount && userFirstName.value) {
        withdrawInfo.payoneerAccount = userEmail.value
      }
      withdrawDialogVisible.value = true
    }
  } catch (error) {
    console.error('获取用户信息失败:', error)
    ElMessage.error(t('loadUserInfoFailed'))
  } finally {
    isLoadingWithdraw.value = false
  }
}

// 充值成功处理
const handleRechargeSuccess = () => {
  rechargeDialogVisible.value = false
  rechargeAmount.value = 0
  ElMessage.success(t('rechargeSuccess'))
  // 刷新余额和记录
  loadBalance()
  loadRecords()
}

// 充值错误处理
const handleRechargeError = (error) => {
  ElMessage.error(t('rechargeFailed'))
  console.error('Recharge error:', error)
}

// 获取可用的提现快捷金额
const getAvailableWithdrawAmounts = () => {
  const balance = parseFloat(currentBalance.value)
  return quickAmounts.filter(amount => amount <= balance)
}

// 提现表单验证
const isWithdrawFormValid = computed(() => {
  if (withdrawAmount.value <= 0 || withdrawAmount.value > parseFloat(currentBalance.value)) {
    return false
  }
  if (!withdrawInfo.payoneerAccount) {
    return false
  }
  return true
})

// 提现提交处理
const handleWithdrawSubmit = async () => {
  if (!isWithdrawFormValid.value || isWithdrawProcessing.value) {
    return
  }

  isWithdrawProcessing.value = true

  try {
    const requestData = {
      amount: withdrawAmount.value,
      accountType: 'payoneer',
      accountNumber: withdrawInfo.payoneerAccount,
      accountName: userFirstName.value || userEmail.value
    }

    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)

    const response = await fetch('/api/common/payoneer/withdrawal/create', {
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
      ElMessage.success(t('withdrawSuccess'))
      // 关闭对话框并重置表单
      withdrawDialogVisible.value = false
      withdrawAmount.value = 0
      withdrawInfo.payoneerAccount = ''
      // 刷新余额和记录
      loadBalance()
      loadRecords()
    } else {
      ElMessage.error(result.message || t('withdrawFailed'))
    }
  } catch (error) {
    console.error('Withdraw error:', error)
    ElMessage.error(t('withdrawFailed'))
  } finally {
    isWithdrawProcessing.value = false
  }
}

// 获取用户信息（通过API）
const fetchUserProfile = async () => {
  try {
    // 生成签名
    const signedData = apiSignature.sign({})
    
    // 将签名参数添加到URL查询字符串
    const queryParams = new URLSearchParams({
      timestamp: signedData.timestamp,
      nonce: signedData.nonce,
      signature: signedData.signature
    })
    
    const response = await fetch(`/shop/api/customer/profile?${queryParams.toString()}`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include'
    })
    
    const result = await response.json()
    
    if (result.success && result.data) {
      return result.data
    } else {
      console.error('获取用户信息失败:', result.message)
      return null
    }
  } catch (error) {
    console.error('获取用户信息异常:', error)
    return null
  }
}

// 加载提现配置
const loadWithdrawalConfig = async () => {
  try {
    const response = await fetch('/shop/api/withdrawal/config', {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include'
    })
    
    const result = await response.json()
    
    if (result.success && result.data) {
      withdrawalConfig.value = result.data
    }
  } catch (error) {
    console.error('加载提现配置失败:', error)
    // 默认使用离线模式
    withdrawalConfig.value = {
      withdrawalMode: 'offline',
      isOnlinePayment: false,
      isOfflinePayment: true
    }
  }
}

// 离线提现表单验证
const isOfflineWithdrawFormValid = computed(() => {
  if (offlineWithdrawAmount.value <= 0 || offlineWithdrawAmount.value > parseFloat(currentBalance.value)) {
    return false
  }
  if (!offlineWithdrawInfo.paymentInfo || offlineWithdrawInfo.paymentInfo.trim() === '') {
    return false
  }
  return true
})

// 离线提现提交处理
const handleOfflineWithdrawSubmit = async () => {
  if (!isOfflineWithdrawFormValid.value || isOfflineWithdrawProcessing.value) {
    return
  }

  isOfflineWithdrawProcessing.value = true

  try {
    const requestData = {
      amount: offlineWithdrawAmount.value,
      paymentInfo: offlineWithdrawInfo.paymentInfo
    }

    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)

    const response = await fetch('/shop/api/withdrawal/create-offline', {
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
      ElMessage.success(t('offlineWithdrawSuccess'))
      // 关闭对话框并重置表单
      offlineWithdrawDialogVisible.value = false
      offlineWithdrawAmount.value = 0
      offlineWithdrawInfo.paymentInfo = ''
      // 刷新余额和记录
      loadBalance()
      loadRecords()
    } else {
      ElMessage.error(result.message || t('offlineWithdrawFailed'))
    }
  } catch (error) {
    console.error('Offline withdraw error:', error)
    ElMessage.error(t('offlineWithdrawFailed'))
  } finally {
    isOfflineWithdrawProcessing.value = false
  }
}

// 组件卸载时移除事件监听器
onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
/* Tailwind CSS 已包含所有必要样式 */

/* 确保筛选控件间距 */
.filter-and-refresh-wrapper > label {
  margin-right: 1rem;
}

.filter-and-refresh-wrapper > select {
  margin-left: 0;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap-8 -> margin */
/* 确保余额显示区域间距 */
.balance-info-row > *:not(:last-child) {
  margin-right: 2rem; /* 32px */
}

/* 确保充值提现按钮间距 */
.balance-action-btns > *:not(:last-child) {
  margin-right: 1rem;
}

/* 确保按钮内图标和文字间距 */
.balance-action-btns button svg {
  margin-right: 0.5rem;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap-3 -> margin */
/* 主要信息网格间距 */
.record-info-grid > * {
  margin-right: 0.75rem; /* 12px */
  margin-bottom: 0.75rem; /* 12px */
}

@supports (grid-gap: 0.75rem) {
  .record-info-grid {
    gap: 0.75rem;
  }
  .record-info-grid > * {
    margin-right: 0;
    margin-bottom: 0;
  }
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap-2 -> margin */
/* 离线提现提交按钮内部间距 */
.offline-withdraw-submit-btn > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 快捷金额按钮网格间距 - 充值对话框 */
.quick-amount-grid-recharge {
  gap: 0.75rem; /* 12px，增加间距 */
}

/* 快捷金额按钮网格间距 - 提现对话框 */
.quick-amount-grid-withdraw {
  gap: 0.75rem; /* 12px，增加间距 */
}

/* 快捷金额按钮网格间距 - 离线提现对话框 */
.quick-amount-grid-offline {
  gap: 0.75rem; /* 12px，增加间距 */
}

/* 老浏览器兼容性处理 */
@supports not (gap: 0.75rem) {
  .quick-amount-grid-recharge > *,
  .quick-amount-grid-withdraw > *,
  .quick-amount-grid-offline > * {
    margin-right: 0.75rem;
    margin-bottom: 0.75rem;
  }
  
  .quick-amount-grid-recharge > *:nth-child(4n),
  .quick-amount-grid-withdraw > *:nth-child(4n),
  .quick-amount-grid-offline > *:nth-child(4n) {
    margin-right: 0;
  }
}
</style>

<template>
  <div class="payoneer-payment">
    <!-- 邮箱验证提示 -->
    <div v-if="!customerEmail" class="email-warning">
      <div class="warning-content">
        <AlertCircle :size="20" class="warning-icon" />
        <div class="warning-text">
          <h4>{{ t('emailRequired') }}</h4>
          <p>{{ t('emailRequiredDesc') }}</p>
        </div>
      </div>
      <button @click="goToProfile" class="btn-fill-email">
        {{ t('goToFillEmail') }}
      </button>
    </div>

    <!-- 支付表单 -->
    <div v-else class="payment-form">
      <!-- 支付金额显示 -->
      <div class="amount-display">
        <label>{{ t('paymentAmount') }}</label>
        <div class="amount-value">
          {{ formatPrice(amount, currency) }}
        </div>
      </div>

      <!-- 提现账户信息（仅提现显示） -->
      <div v-if="paymentType === 'withdrawal'" class="withdrawal-account">
        <div class="form-group">
          <label>{{ t('accountType') }} *</label>
          <select v-model="withdrawalInfo.accountType" class="form-control">
            <option value="bank">{{ t('bankAccount') }}</option>
            <option value="alipay">{{ t('alipay') }}</option>
            <option value="wechat">{{ t('wechatPay') }}</option>
          </select>
        </div>

        <div class="form-group">
          <label>{{ t('accountNumber') }} *</label>
          <input
            v-model="withdrawalInfo.accountNumber"
            type="text"
            class="form-control"
            :placeholder="t('enterAccountNumber')"
            required
          />
        </div>

        <div class="form-group">
          <label>{{ t('accountName') }} *</label>
          <input
            v-model="withdrawalInfo.accountName"
            type="text"
            class="form-control"
            :placeholder="t('enterAccountName')"
            required
          />
        </div>

        <div v-if="withdrawalInfo.accountType === 'bank'" class="form-group">
          <label>{{ t('bankName') }}</label>
          <input
            v-model="withdrawalInfo.bankName"
            type="text"
            class="form-control"
            :placeholder="t('enterBankName')"
          />
        </div>
      </div>

      <!-- 支付按钮 -->
      <button
        @click="handlePayment"
        :disabled="isProcessing || !isFormValid"
        class="btn-payment"
      >
        <Loader v-if="isProcessing" :size="20" class="btn-spinner" />
        <CreditCard v-else :size="20" class="btn-icon" />
        <span>{{ getButtonText() }}</span>
      </button>

      <!-- 支付说明 -->
      <div class="payment-notice">
        <p>{{ t('paymentNotice') }}</p>
        <ul>
          <li>{{ t('notice1') }}</li>
          <li>{{ t('notice2') }}</li>
          <li>{{ t('notice3') }}</li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { AlertCircle, CreditCard, Loader } from 'lucide-vue-next'
import encryptionService from '../data/encryption-service'
import apiSignature from '../services/apiSignature'

/**
 * Payoneer 支付组件
 * 
 * 支持三种支付场景：
 * 1. 订单支付 (order_payment)
 * 2. 用户充值 (recharge)
 * 3. 用户提现 (withdrawal)
 */

const props = defineProps({
  // 支付类型: 'order_payment' | 'recharge' | 'withdrawal'
  paymentType: {
    type: String,
    required: true,
    validator: (value) => ['order_payment', 'recharge', 'withdrawal'].includes(value)
  },
  // 支付金额
  amount: {
    type: Number,
    required: true
  },
  // 订单号（订单支付时必传）
  orderNo: {
    type: String,
    default: ''
  },
  // 用户邮箱
  customerEmail: {
    type: String,
    default: ''
  },
  // 用户名
  customerFirstName: {
    type: String,
    default: ''
  },
  customerLastName: {
    type: String,
    default: ''
  },
  // 默认币种
  currency: {
    type: String,
    default: 'USD'
  },
  // 网站货币符号（从SiteConfig读取，如 $, €, ¥）
  currencySymbol: {
    type: String,
    default: '$'
  }
})

const emit = defineEmits(['payment-success', 'payment-error'])

// 状态管理
const isProcessing = ref(false)

// 提现账户信息
const withdrawalInfo = ref({
  accountType: 'bank',
  accountNumber: '',
  accountName: props.customerFirstName || '',
  bankName: ''
})

// 多语言翻译
const translations = ref({})
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载多语言
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/PayoneerPayment.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
  }
}

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

// 监听语言切换事件
const handleLanguageChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  } else {
    // 兼容旧版事件格式
    const savedLang = localStorage.getItem('app.lang')
    if (savedLang) {
      currentLang.value = savedLang
    }
  }
}

// 表单验证
const isFormValid = computed(() => {
  if (props.paymentType === 'order_payment') {
    return props.orderNo && props.customerEmail
  }
  
  if (props.paymentType === 'withdrawal') {
    return (
      withdrawalInfo.value.accountNumber &&
      withdrawalInfo.value.accountName &&
      (withdrawalInfo.value.accountType !== 'bank' || withdrawalInfo.value.bankName)
    )
  }
  
  return true
})

// 获取按钮文字
const getButtonText = () => {
  if (isProcessing.value) {
    return t('processing')
  }
  
  switch (props.paymentType) {
    case 'order_payment':
      return t('payNow')
    case 'recharge':
      return t('rechargeNow')
    case 'withdrawal':
      return t('withdrawNow')
    default:
      return t('confirm')
  }
}

// 格式化价格
const formatPrice = (amount, currency = 'USD') => {
  // 【原有显示逻辑 - 已注释】
  // 原逻辑：使用传入的 currency 参数显示货币符号
  // const symbols = {
  //   'USD': '$',
  //   'EUR': '€',
  //   'GBP': '£',
  //   'CNY': '¥',
  //   'JPY': '¥'
  // }
  // return `${symbols[currency] || ''}${amount.toFixed(2)}`
  
  // 【新逻辑】仅显示货币代码和金额，不显示货币符号
  return `${currency} ${amount.toFixed(2)}`
}

// 跳转到个人资料页面
const goToProfile = () => {
  window.location.href = '/user-center'
}

/**
 * 处理支付
 */
const handlePayment = async () => {
  if (isProcessing.value || !isFormValid.value) {
    return
  }

  isProcessing.value = true

  try {
    let result

    switch (props.paymentType) {
      case 'order_payment':
        result = await createOrderPayment()
        break
      case 'recharge':
        result = await createRecharge()
        break
      case 'withdrawal':
        result = await createWithdrawal()
        break
    }

    if (result.success) {
      // 跳转到 Payoneer 支付页面
      window.location.href = result.paymentUrl
    } else {
      throw new Error(result.error || 'Payment failed')
    }
  } catch (error) {
    console.error('Payment error:', error)
    alert(t('paymentFailed') + ': ' + error.message)
    emit('payment-error', error)
  } finally {
    isProcessing.value = false
  }
}

/**
 * 创建订单支付会话
 * 
 * 需要参数：
 * - orderNo: 订单号
 * - customerEmail: 客户邮箱
 * - customerFirstName: 客户名（可选）
 * - customerLastName: 客户姓（可选）
 */
const createOrderPayment = async () => {
  const requestData = {
    orderNo: props.orderNo,
    customerEmail: props.customerEmail,
    customerFirstName: props.customerFirstName,
    customerLastName: props.customerLastName
  }
  
  console.log('======================================================')
  console.log('=== Payoneer 订单支付请求参数 ===')
  console.log('======================================================')
  console.log('📄 支付类型:', props.paymentType)
  console.log('📝 订单号:', requestData.orderNo)
  console.log('📧 客户邮箱:', requestData.customerEmail)
  console.log('👤 客户名:', requestData.customerFirstName)
  console.log('👤 客户姓:', requestData.customerLastName)
  console.log('💵 支付金额:', props.amount, '(❗ 仅显示，后端从订单读取)')
  console.log('💱 币种:', props.currency, '(❗ 仅显示，后端从 site_config.configValue 读取)')
  console.log('')
  console.log('🔵 实际发送到后端的参数：')
  console.log(JSON.stringify(requestData, null, 2))
  console.log('')
  console.log('💡 说明：后端会自动补充以下参数：')
  console.log('   - amount: 从 Order 表读取')
  console.log('   - currency: 从 site_config 表 (configKey=site_currency, configValue) 读取')
  console.log('   - country: 从 site_config 表 (configKey=site_currency, configValue2) 读取')
  console.log('   - operationType: 固定为 CHARGE')
  console.log('   - customer.customerNumber: 从 Customer 表的 customer_id 读取')
  console.log('   - notificationURL: 自动生成回调地址')
  console.log('   - returnURL: 自动生成跳转地址')
  console.log('======================================================')
  
  // 加密数据
  const encryptedData = encryptionService.prepareData(requestData, true)
  
  // 生成签名
  const signedData = apiSignature.sign(encryptedData)
  
  const response = await fetch('/api/common/payoneer/create-session', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    credentials: 'include',
    body: JSON.stringify(signedData)
  })

  const result = await response.json()
  
  console.log('')
  console.log('======================================================')
  console.log('=== Payoneer 订单支付响应结果 ===')
  console.log('======================================================')
  console.log('响应数据:', JSON.stringify(result, null, 2))
  console.log('======================================================')
  console.log('')
  
  return result
}

/**
 * 创建充值会话
 * 
 * 需要参数：
 * - amount: 充值金额
 */
const createRecharge = async () => {
  const requestData = {
    amount: props.amount
  }
  
  console.log('=== Payoneer 充值请求参数 ===')
  console.log('支付类型:', props.paymentType)
  console.log('充值金额:', requestData.amount)
  console.log('币种:', props.currency)
  console.log('完整请求参数:', JSON.stringify(requestData, null, 2))
  console.log('================================')
  
  // 加密数据
  const encryptedData = encryptionService.prepareData(requestData, true)
  
  // 生成签名
  const signedData = apiSignature.sign(encryptedData)
  
  const response = await fetch('/api/common/payoneer/recharge/create', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    credentials: 'include',
    body: JSON.stringify(signedData)
  })

  const result = await response.json()
  
  console.log('=== Payoneer 充值响应结果 ===')
  console.log('响应数据:', JSON.stringify(result, null, 2))
  console.log('================================')
  
  return result
}

/**
 * 创建提现请求
 * 
 * 需要参数：
 * - amount: 提现金额
 * - accountType: 账户类型 (bank/alipay/wechat)
 * - accountNumber: 账号
 * - accountName: 账户名
 * - bankName: 银行名称（accountType 为 bank 时必填）
 */
const createWithdrawal = async () => {
  const requestData = {
    amount: props.amount,
    ...withdrawalInfo.value
  }
  
  console.log('=== Payoneer 提现请求参数 ===')
  console.log('支付类型:', props.paymentType)
  console.log('提现金额:', requestData.amount)
  console.log('账户类型:', requestData.accountType)
  console.log('账号:', requestData.accountNumber)
  console.log('账户名:', requestData.accountName)
  console.log('银行名称:', requestData.bankName)
  console.log('币种:', props.currency)
  console.log('完整请求参数:', JSON.stringify(requestData, null, 2))
  console.log('================================')
  
  // 加密数据
  const encryptedData = encryptionService.prepareData(requestData, true)
  
  // 生成签名
  const signedData = apiSignature.sign(encryptedData)
  
  const response = await fetch('/api/common/payoneer/withdrawal/create', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    credentials: 'include',
    body: JSON.stringify(signedData)
  })

  const result = await response.json()
  
  console.log('=== Payoneer 提现响应结果 ===')
  console.log('响应数据:', JSON.stringify(result, null, 2))
  console.log('================================')
  
  return result
}

// 监听语言切换事件
const handleLangChange = (event) => {
  handleLanguageChange(event)
  loadTranslations()
}

onMounted(() => {
  loadTranslations()
  
  // 监听全局语言切换事件
  window.addEventListener('languagechange', handleLangChange)
})

onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
.payoneer-payment {
  max-width: 600px;
  margin: 0 auto;
}

/* 邮箱警告 */
.email-warning {
  background: #fef3c7;
  border: 2px solid #f59e0b;
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 20px;
}

.warning-content {
  display: flex;
  gap: 12px;
  margin-right: -12px;
  margin-bottom: 16px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.warning-content {
  margin-right: -12px;
}

.warning-content > * {
  margin-right: 12px;
}


.warning-icon {
  color: #f59e0b;
  flex-shrink: 0;
  margin-top: 2px;
}

.warning-text h4 {
  margin: 0 0 8px 0;
  color: #92400e;
  font-size: 16px;
  font-weight: 600;
}

.warning-text p {
  margin: 0;
  color: #78350f;
  font-size: 14px;
  line-height: 1.5;
}

.btn-fill-email {
  width: 100%;
  padding: 12px;
  background: #f59e0b;
  color: white;
  border: none;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-fill-email:hover {
  background: #d97706;
}

/* 支付表单 */
.payment-form {
  background: white;
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.amount-display {
  margin-bottom: 24px;
  padding: 20px;
  background: #f3f4f6;
  border-radius: 8px;
  text-align: center;
}

.amount-display label {
  display: block;
  font-size: 14px;
  color: #6b7280;
  margin-bottom: 8px;
}

.amount-value {
  font-size: 32px;
  font-weight: 700;
  color: #1f2937;
}

.form-group {
  margin-bottom: 20px;
}

.form-group label {
  display: block;
  font-size: 14px;
  font-weight: 600;
  color: #374151;
  margin-bottom: 8px;
}

.form-control {
  width: 100%;
  padding: 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  transition: border-color 0.2s;
}

.form-control:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.withdrawal-account {
  background: #f9fafb;
  padding: 20px;
  border-radius: 8px;
  margin-bottom: 20px;
}

/* 支付按钮 */
.btn-payment {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-right: -8px;
  padding: 16px;
  background: #dc2626;
  color: white;
  border: none;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s;
  margin-bottom: 20px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.btn-payment {
  margin-right: -8px;
}

.btn-payment > * {
  margin-right: 8px;
}


.btn-payment:hover:not(:disabled) {
  background: #b91c1c;
}

.btn-payment:disabled {
  background: #9ca3af;
  cursor: not-allowed;
}

.btn-spinner {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

.btn-icon {
  flex-shrink: 0;
}

/* 支付说明 */
.payment-notice {
  background: #eff6ff;
  border-left: 4px solid #3b82f6;
  padding: 16px;
  border-radius: 4px;
  font-size: 13px;
  color: #1e40af;
}

.payment-notice p {
  margin: 0 0 12px 0;
  font-weight: 600;
}

.payment-notice ul {
  margin: 0;
  padding-left: 20px;
}

.payment-notice li {
  margin-bottom: 6px;
  line-height: 1.5;
}

.payment-notice li:last-child {
  margin-bottom: 0;
}

/* 响应式 */
@media (max-width: 640px) {
  .payment-form {
    padding: 16px;
  }

  .amount-value {
    font-size: 28px;
  }
}
</style>

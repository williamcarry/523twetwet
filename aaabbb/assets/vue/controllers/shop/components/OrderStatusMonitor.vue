<template>
  <Teleport to="body" v-if="isVisible">
    <!-- 背景遮罩 - 禁止点击关闭 -->
    <div 
      class="fixed inset-0 bg-black/35 z-40"
    ></div>

    <!-- 模态对话框 -->
    <div class="fixed bg-white rounded-lg shadow-xl z-50" :style="{ width: '550px', maxHeight: '75vh', top: '50%', left: '50%', transform: 'translate(-50%, -50%)' }">
      <!-- 标题栏 -->
      <div class="flex items-center justify-between h-12 px-4 border-b border-gray-200 bg-white rounded-t-lg">
        <h2 class="text-base font-medium text-gray-800">{{ currentStatus === 'processing' ? t('processingTitle') : currentStatus === 'success' ? t('successTitle') : t('failedTitle') }}</h2>
        <!-- 只有在成功或失败时才显示关闭按钮 -->
        <button 
          v-if="currentStatus !== 'processing'"
          type="button" 
          class="text-gray-400 hover:text-gray-600 transition-colors" 
          @click="close"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- 内容区域 -->
      <div class="overflow-y-auto" :style="{ maxHeight: 'calc(75vh - 100px)' }">
        <div class="p-4">
          <!-- 处理中状态 -->
          <div v-if="currentStatus === 'processing'" class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-4 border-blue-600 mx-auto mb-4"></div>
            <h3 class="text-base font-semibold text-gray-800 mb-2">{{ t('processingTitle') }}</h3>
            <p class="text-sm text-gray-600 mb-2">{{ t('orderNoLabel') }}{{ orderNo }}</p>
            <p class="text-xs text-gray-500">{{ statusMessage }}</p>
          </div>

          <!-- 成功状态 -->
          <div v-else-if="currentStatus === 'success'">
            <!-- 如果显示 Payoneer 支付组件 -->
            <div v-if="showPayoneerPayment && customerInfo">
              <PayoneerPayment
                payment-type="order_payment"
                :amount="orderAmount"
                :order-no="orderNo"
                :customer-email="customerInfo.email"
                :customer-first-name="customerInfo.firstName"
                :customer-last-name="customerInfo.lastName"
                :currency="siteCurrency"
                :currency-symbol="currencySymbol"
                @payment-success="handlePayoneerSuccess"
                @payment-error="handlePayoneerError"
              />
            </div>
            
            <!-- 支付方式选择 -->
            <div v-else>
              <!-- 订单生成成功提示 -->
              <div class="text-center mb-6">
                <div class="text-5xl mb-4">🎉</div>
                <h3 class="text-base font-semibold text-gray-800 mb-2">{{ t('successTitle') }}</h3>
                <p class="text-sm text-gray-600 mb-2">{{ t('orderNoLabel') }}{{ orderNo }}</p>
                <p class="text-xs text-gray-500 mb-4">{{ t('successMessage') }}</p>
              </div>
              
              <!-- 支付方式选择 -->
              <div>
                <p class="text-base font-medium text-gray-900 mb-4">{{ t('selectPaymentMethod') }}</p>
                
                <div class="grid grid-cols-3 gap-4 mb-6">
                  <!-- 余额支付 -->
                  <div
                    class="flex flex-col items-center justify-center w-full p-4 border-2 rounded-lg cursor-pointer transition-all hover:shadow-md"
                    :class="selectedPaymentMethod === 'balance' ? 'border-purple-500 bg-purple-50' : 'border-gray-300 bg-white hover:border-gray-400'"
                    @click="selectedPaymentMethod = 'balance'"
                  >
                    <svg class="w-12 h-12 mb-3 text-purple-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-sm font-medium text-gray-800 text-center">{{ t('balancePay') }}</span>
                  </div>

                  <!-- Payoneer -->
                  <div
                    class="flex flex-col items-center justify-center w-full p-4 border-2 rounded-lg cursor-pointer transition-all hover:shadow-md"
                    :class="selectedPaymentMethod === 'payoneer' ? 'border-cyan-500 bg-cyan-50' : 'border-gray-300 bg-white hover:border-gray-400'"
                    @click="selectedPaymentMethod = 'payoneer'"
                  >
                    <img
                      :alt="t('payoneer')"
                      loading="lazy"
                      src="/frondend/images/ItemDetailPage/payoneer.png"
                      class="w-12 h-12 mb-3 flex-shrink-0"
                      onerror="this.src='https://via.placeholder.com/48?text=Payoneer'"
                    />
                    <span class="text-sm font-medium text-gray-800 text-center">{{ t('payoneer') }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 失败状态 -->
          <div v-else-if="currentStatus === 'failed'" class="text-center">
            <div class="text-5xl mb-4">⚠️</div>
            <h3 class="text-base font-semibold text-gray-800 mb-2">{{ t('failedTitle') }}</h3>
            <p class="text-sm text-red-600 mb-2">{{ errorMessage }}</p>
            <p class="text-xs text-gray-500">{{ t('failedMessage') }}</p>
          </div>
        </div>
      </div>

      <!-- 底部操作按钮 -->
      <div class="flex items-center justify-end gap-2 h-12 px-4 border-t border-gray-200 bg-white rounded-b-lg">
        <!-- 订单生成成功后显示确认支付按钮 -->
        <button
          v-if="currentStatus === 'success'"
          type="button"
          class="px-4 py-2 text-xs font-medium text-white rounded transition-colors inline-flex items-center justify-center min-h-8"
          :class="{ 'opacity-50 cursor-not-allowed': !selectedPaymentMethod || isProcessingPayment }"
          :disabled="!selectedPaymentMethod || isProcessingPayment"
          style="background-color: #FF6600;"
          @click="handleConfirmPayment"
          @mouseenter="$event.target.style.backgroundColor = (!selectedPaymentMethod || isProcessingPayment) ? '#FF6600' : '#FF7722'"
          @mouseleave="$event.target.style.backgroundColor = '#FF6600'"
        >
          {{ isProcessingPayment ? t('processingPayment') : t('confirmPayment') }}
        </button>
        <!-- 失败时显示关闭按钮 -->
        <button
          v-if="currentStatus === 'failed'"
          type="button"
          class="px-4 py-2 text-xs font-medium text-white rounded transition-colors inline-flex items-center justify-center min-h-8"
          style="background-color: #FF6600;"
          @click="close"
          @mouseenter="$event.target.style.backgroundColor = '#FF7722'"
          @mouseleave="$event.target.style.backgroundColor = '#FF6600'"
        >
          {{ t('btnClose') }}
        </button>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, watch, onBeforeUnmount, onMounted, computed } from 'vue'
import { ElMessage } from 'element-plus'
import apiSignature from '../services/apiSignature.js'
import encryptionService from '../data/encryption-service.js'
import PayoneerPayment from './PayoneerPayment.vue'

// 页面翻译数据
const translations = ref({})

// 当前语言（从 localStorage 读取初始值）
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/OrderStatusMonitor.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
  }
}

// 翻译函数
const t = (key) => {
  const lang = currentLang.value
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

const props = defineProps({
  isVisible: {
    type: Boolean,
    default: false
  },
  orderNo: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['close', 'ready', 'paymentSuccess'])

const currentStatus = ref('processing')
const statusMessage = ref('')
const errorMessage = ref('')
const eventSource = ref(null)
const isConnecting = ref(false) // 防止重复连接
const hasEmittedReady = ref(false) // 防止重复触发 ready 事件
const selectedPaymentMethod = ref(null) // 选中的支付方式
const isProcessingPayment = ref(false) // 是否正在处理支付
const showPayoneerPayment = ref(false) // 是否显示 Payoneer 支付组件
const customerInfo = ref(null) // 客户信息
const orderAmount = ref(0) // 订单金额
const siteCurrency = ref('USD') // 网站币种
const currencySymbol = ref('$') // 货币符号
const isComponentReady = ref(false) // 组件是否完全就绪
const pendingOrderNo = ref('') // 等待建立连接的订单号

const resetStatus = () => {
  currentStatus.value = 'processing'
  statusMessage.value = t('msgInitializing')
  errorMessage.value = ''
  hasEmittedReady.value = false // 重置 ready 标志
  selectedPaymentMethod.value = null // 重置支付方式选择
  isProcessingPayment.value = false // 重置支付处理状态
  showPayoneerPayment.value = false // 重置 Payoneer 支付组件显示
}

// 统一的 watch：避免重复触发
watch(
  () => ({ isVisible: props.isVisible, orderNo: props.orderNo, isReady: isComponentReady.value }),
  (newVal, oldVal) => {
    console.log('[WATCH unified]', { 
      newVisible: newVal.isVisible, 
      oldVisible: oldVal?.isVisible,
      newOrderNo: newVal.orderNo, 
      oldOrderNo: oldVal?.orderNo,
      isReady: newVal.isReady,
      isConnecting: isConnecting.value,
      hasEventSource: !!eventSource.value
    })
    
    // 1. 弹窗关闭：清理所有连接和状态
    if (!newVal.isVisible) {
      closeMercureConnection()
      isConnecting.value = false
      pendingOrderNo.value = ''
      setTimeout(() => {
        resetStatus()
      }, 300)
      return
    }
    
    // 2. 弹窗打开 + 有订单号 + 组件已就绪 + 没有连接
    if (newVal.isVisible && newVal.orderNo && newVal.isReady && !isConnecting.value && !eventSource.value) {
      // 必须是新订单号（从无到有，或者订单号变化）
      const isNewOrder = !oldVal?.orderNo || (newVal.orderNo !== oldVal.orderNo)
      if (isNewOrder) {
        console.log('✅ [WATCH] 条件满足，建立 Mercure 连接')
        resetStatus()
        subscribeMercure(newVal.orderNo)
      }
    }
    // 3. 弹窗打开 + 有订单号 + 组件未就绪：保存待处理订单号
    else if (newVal.isVisible && newVal.orderNo && !newVal.isReady) {
      pendingOrderNo.value = newVal.orderNo
      console.log('⏳ [WATCH] 组件未就绪，保存订单号:', newVal.orderNo)
    }
  },
  { deep: true }
)

const subscribeMercure = async (orderNo) => {
  console.log('🚀 [DEBUG] subscribeMercure 开始', { orderNo, isConnecting: isConnecting.value, hasEventSource: !!eventSource.value })
  
  // 防止重复连接 - 增强检查
  if (isConnecting.value || eventSource.value) {
    console.warn('⚠️ [DEBUG] Mercure 连接已存在或正在建立中，跳过', {
      isConnecting: isConnecting.value,
      hasEventSource: !!eventSource.value,
      readyState: eventSource.value?.readyState
    })
    return
  }
  
  // 立即设置标志位，防止并发调用
  isConnecting.value = true
  console.log('✅ [DEBUG] 设置 isConnecting = true')
  
  // 彻底关闭旧连接
  closeMercureConnection()
  
  try {
    console.log('📡 [DEBUG] 开始获取 Mercure Token')
    const tokenResponse = await fetch(`/api/mercure/token?orderNo=${encodeURIComponent(orderNo)}`, {
      credentials: 'include'
    })
    
    console.log('📡 [DEBUG] Token 响应状态:', tokenResponse.status)
    const tokenData = await tokenResponse.json()
    console.log('📡 [DEBUG] Token 数据:', tokenData)
    
    if (!tokenData.success || !tokenData.token) {
      console.error('❌ [DEBUG] Token 获取失败')
      currentStatus.value = 'failed'
      errorMessage.value = t('msgConnectionFailed')
      isConnecting.value = false
      return
    }
    
    const mercureUrl = new URL('http://127.0.0.1:3000/.well-known/mercure')
    mercureUrl.searchParams.append('topic', tokenData.topic)
    const finalUrl = `${mercureUrl.toString()}&authorization=${tokenData.token}`
    
    console.log('🔗 [DEBUG] Mercure URL:', finalUrl)
    console.log('📋 [DEBUG] Topic:', tokenData.topic)
    
    // 创建新的 EventSource
    const newEventSource = new EventSource(finalUrl)
    console.log('✅ [DEBUG] EventSource 对象已创建')
    
    // 设置所有事件监听器
    newEventSource.onopen = async () => {
      console.log('🎉 [DEBUG] EventSource.onopen 触发')
      console.log('🔍 [DEBUG] EventSource readyState:', newEventSource.readyState)

      statusMessage.value = t('msgConnected')
      console.log('✅ [DEBUG] EventSource 连接已建立，状态已更新')

      // ✅ 【关键修复】获取待处理的消息（解决Linux高速执行导致的消息丢失）
      // 前端连接建立后，立即查询后端Redis中存储的所有待处理消息
      // 这样即使在Linux上消息被发送得很快，前端也能捕获所有消息
      console.log('🔄 [DEBUG] 查询待处理消息...')
      await fetchAndProcessPendingMessages(orderNo)
      console.log('✅ [DEBUG] 待处理消息已处理')

      // 通知后端
      console.log('📤 [DEBUG] 准备通知后端')
      notifyBackendReady(orderNo)

      // onopen 后才设置 isConnecting = false，确保其他调用看到连接在进行中
      isConnecting.value = false
    }
    
    newEventSource.onmessage = (event) => {
      console.log('📨 [DEBUG] 收到原始消息:', event.data)
      console.log('📨 [DEBUG] 消息类型:', event.type)
      console.log('📨 [DEBUG] 当前时间:', new Date().toISOString())
      try {
        const data = JSON.parse(event.data)
        console.log('📩 [DEBUG] 解析后的消息:', data)
        handleMercureMessage(data)
      } catch (e) {
        console.error('❌ [DEBUG] 消息解析失败:', e, 'Raw data:', event.data)
      }
    }
    
    newEventSource.onerror = (error) => {
      console.error('❌ [DEBUG] EventSource.onerror 触发:', error)
      console.log('🔍 [DEBUG] EventSource readyState:', newEventSource?.readyState)
      
      isConnecting.value = false
      
      // 如果连接关闭,说明Mercure服务有问题或网络断了
      if (newEventSource?.readyState === EventSource.CLOSED) {
        console.error('❌ [DEBUG] EventSource 已关闭,启用降级方案')
        
        // ✅ 降级方案: 即使EventSource失败,也要触发ready让订单继续处理
        if (!hasEmittedReady.value) {
          console.log('🔔 [DEBUG] 降级: 触发 ready 事件')
          hasEmittedReady.value = true
          emit('ready')
        }
        
        // 不直接设置失败状态,而是启动轮询检查订单状态
        startPollingOrderStatus(orderNo)
      }
    }
    
    // 最后才赋值给 eventSource.value，确保监听器都设置好了
    eventSource.value = newEventSource
    console.log('✅ [DEBUG] EventSource 已赋值给 eventSource.value')
    
  } catch (error) {
    console.error('❌ [DEBUG] 创建 Mercure 连接失败:', error)
    console.error('❌ [DEBUG] 错误堆栈:', error.stack)
    currentStatus.value = 'failed'
    errorMessage.value = t('msgConnectionFailed')
    isConnecting.value = false
  }
}

// 【新增】获取并处理待处理的消息（解决Linux高速执行导致的消息丢失问题）
// 支持轮询：如果第一次没有消息，会等待后重试
const fetchAndProcessPendingMessages = async (orderNo) => {
  try {
    console.log('📡 [PendingMessages] 开始查询待处理消息', { orderNo })

    // 【修复】轮询机制：如果第一次查询返回0条消息，说明后端还在处理中
    // 等待 100ms 后重试，最多试 5 次（总耗时最多 500ms）
    let retryCount = 0
    const maxRetries = 5
    let messages = []

    while (retryCount < maxRetries) {
      // 调用后端API获取Redis中存储的待处理消息
      const response = await fetch(`/api/mercure/pending-messages?orderNo=${encodeURIComponent(orderNo)}`, {
        credentials: 'include'
      })

      console.log(`📡 [PendingMessages] 第 ${retryCount + 1} 次查询，响应状态:`, response.status)
      const result = await response.json()
      console.log(`📡 [PendingMessages] 第 ${retryCount + 1} 次查询结果:`, result)

      if (result.success && result.messages && result.messages.length > 0) {
        console.log('✅ [PendingMessages] 找到', result.messages.length, '条待处理消息')
        messages = result.messages
        break
      }

      // 如果没有消息，说明后端还在处理中
      // 等待 100ms 后重试
      retryCount++
      if (retryCount < maxRetries) {
        console.log(`⏳ [PendingMessages] 第 ${retryCount} 次重试，等待 100ms...`)
        await new Promise(resolve => setTimeout(resolve, 100))
      }
    }

    if (messages.length > 0) {
      // 处理每一条待处理消息
      for (const messageObj of messages) {
        const messageData = messageObj.data
        console.log('📨 [PendingMessages] 处理消���:', messageData)

        // 使用同样的处理逻辑处理这些消息
        handleMercureMessage(messageData)

        // 小延迟，避免UI更新过快
        await new Promise(resolve => setTimeout(resolve, 50))
      }

      console.log('✅ [PendingMessages] 所有待处理消息已处理')

      // 处理完后，清空Redis中的消息队列
      await clearProcessedMessages(orderNo)
    } else {
      console.log('ℹ️ [PendingMessages] 查询 5 次后仍然没有消息，可能后端处理出现问题')
    }
  } catch (error) {
    console.warn('⚠️ [PendingMessages] 获取待处理消息失败:', error)
    // 不抛出异常，继续后续流程
  }
}

// 【新增】清空已处理的消息
const clearProcessedMessages = async (orderNo) => {
  try {
    console.log('🗑️ [ClearMessages] 准备清空消息队列', { orderNo })

    const response = await fetch('/api/mercure/clear-messages', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify({ orderNo })
    })

    const result = await response.json()
    console.log('🗑️ [ClearMessages] 清空结果:', result)
  } catch (error) {
    console.warn('⚠️ [ClearMessages] 清空消息队列失败:', error)
    // 不抛出异常，这不影响主流程
  }
}

// 异步通知后端(事件驱动方案)
const notifyBackendReady = (orderNo) => {
  // 防止重复通知
  if (hasEmittedReady.value) {
    console.log('⚠️ [DEBUG] 已经通知过后端，跳过')
    return
  }
  
  console.log('📤 [DEBUG] notifyBackendReady 开始', { orderNo, hasEmittedReady: hasEmittedReady.value })
  
  const requestData = {
    orderNo: orderNo,
    clientTime: performance.now(),
    ready: true
  }
  console.log('📋 [DEBUG] 请求数据:', requestData)
  
  // 使用 fire-and-forget 模式，不等待响应
  fetch('/api/mercure/ready', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    credentials: 'include',
    body: JSON.stringify(requestData)
  }).then(async response => {
    console.log('✅ [DEBUG] /ready 响应状态:', response.status)
    const result = await response.json()
    console.log('✅ [DEBUG] /ready 响应数据:', result)
    console.log('✅ [EventDriven] 后端收到通知')
    
    // 立即触发 ready 事件，不等待后端处理
    if (!hasEmittedReady.value) {
      console.log('🔔 [DEBUG] 触发 ready 事件')
      hasEmittedReady.value = true
      emit('ready')
    } else {
      console.log('⚠️ [DEBUG] ready 事件已触发，跳过')
    }
  }).catch(error => {
    // 故意忽略错误，后端会自动处理
    console.warn('⚠️ [DEBUG] 通知失败:', error)
    console.warn('⚠️  [EventDriven] 通知失败（预期），后端会自动处理')
    
    // 即使失败也触发 ready，确保订单流程继续
    if (!hasEmittedReady.value) {
      console.log('🔔 [DEBUG] 失败后仍触发 ready 事件')
      hasEmittedReady.value = true
      emit('ready')
    }
  })
}

// 【已废弃】旧的轮询确认方法（保留用于兼容性）
// 【已废弃】旧的轮询确认方法（保留用于兼容性）
// 新方案使用事件驱动，无需轮询确认
const confirmSubscription = async (orderNo) => {
  console.warn('⚠️ confirmSubscription 方法已废弃，使用事件驱动方案')
  // 为了兼容性，仍然触发 ready 事件
  if (!hasEmittedReady.value) {
    hasEmittedReady.value = true
    emit('ready')
  }
}

const handleMercureMessage = (data) => {
  console.log('🎯 [DEBUG] handleMercureMessage 开始', data)
  console.log('🔍 [DEBUG] 消息字段:', {
    status: data.status,
    step: data.step,
    message: data.message,
    messageEn: data.messageEn
  })
  
  // 根据当前语言选择显示的消息
  const getMessage = (data) => {
    if (currentLang.value === 'en' && data.messageEn) {
      return data.messageEn
    }
    return data.message || ''
  }
  
  // 优化：简化步骤处理，只关注关键状态
  console.log('🔍 [DEBUG] 处理步骤:', data.step)
  switch (data.step) {
    case 'completed':
      console.log('✅ [DEBUG] 订单完成')
      currentStatus.value = 'success'
      // 成功后立即彻底清理连接
      cleanupConnection()
      break
      
    case 'failed':
    case 'error':
      console.log('❌ [DEBUG] 订单失败')
      currentStatus.value = 'failed'
      errorMessage.value = getMessage(data) || t('msgFailed')
      // 失败后也要彻底清理连接
      cleanupConnection()
      break
      
    default:
      console.log('🔄 [DEBUG] 更新状态消息')
      // 任何其他步骤都只更新状态消息
      if (data.message || data.messageEn) {
        statusMessage.value = getMessage(data)
        console.log('💬 [DEBUG] 状态消息已更新:', statusMessage.value)
      }
  }
}

const closeMercureConnection = () => {
  if (eventSource.value) {
    eventSource.value.close()
    eventSource.value = null
  }
  isConnecting.value = false
}

// 彻底清理所有连接和状���
const cleanupConnection = () => {
  console.log('🧹 开始彻底清理 Mercure 连接和状态...')
  
  // 1. 关闭 EventSource 连接
  if (eventSource.value) {
    try {
      eventSource.value.close()
      console.log('✅ EventSource 已关闭')
    } catch (e) {
      console.error('❌ 关闭 EventSource 失败:', e)
    }
    eventSource.value = null
  }
  
  // 2. 重置所有标志位
  isConnecting.value = false
  hasEmittedReady.value = false
  
  // 3. 清除状态消息（但保留 currentStatus，用于显示结果）
  // statusMessage 保留最后一条消息，供用户查看
  
  console.log('✅ Mercure 连接和状态已彻底清理')
}

const close = () => {
  // 关闭弹窗前先彻底清理
  cleanupConnection()
  emit('close')
}

// 确认支付
const handleConfirmPayment = async () => {
  if (!selectedPaymentMethod.value || isProcessingPayment.value) {
    return
  }
  
  // 如果选择的是 Payoneer，显示 Payoneer 支付组件
  if (selectedPaymentMethod.value === 'payoneer') {
    // 获取客户信息和订单金额
    await fetchOrderInfo()
    showPayoneerPayment.value = true
    return
  }
  
  // 余额支付
  isProcessingPayment.value = true
  
  try {
    // 准备请求数据
    const requestData = {
      orderNo: props.orderNo,
      paymentMethod: selectedPaymentMethod.value
    }
    
    // 加密整个JSON对象
    const encryptedData = encryptionService.prepareData(requestData, true)
    
    // 生成API签名
    const signedData = apiSignature.sign(encryptedData)
    
    // 调用后端 API 更新支付方式
    const response = await fetch('/shop/api/order/update-payment-method', {
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
      const lang = currentLang.value
      ElMessage.success(lang === 'en' ? result.messageEn || 'Payment successful!' : result.message || '支付成功！')
      
      // 触发支付成功事件
      emit('paymentSuccess', { orderNo: props.orderNo })
      
      // 关闭弹窗
      setTimeout(() => {
        close()
      }, 1000)
    } else {
      const lang = currentLang.value
      ElMessage.error(lang === 'en' ? result.messageEn || 'Payment failed' : result.message || '支付失败')
      isProcessingPayment.value = false
    }
  } catch (error) {
    console.error('支付处理失败:', error)
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'Payment processing failed' : '支付处理失败')
    isProcessingPayment.value = false
  }
}

// 获取订单信息（用于 Payoneer 支付）
const fetchOrderInfo = async () => {
  try {
    // 准备请求数据
    const requestData = {
      orderNo: props.orderNo
    }
    
    // 加密整个JSON对象
    const encryptedData = encryptionService.prepareData(requestData, true)
    
    // 生成API签名
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/detail', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const result = await response.json()
    
    if (result.success && result.data) {
      const order = result.data
      customerInfo.value = {
        email: order.customer?.email || '',
        firstName: order.customer?.realName || order.customer?.username || '',
        lastName: ''
      }
      orderAmount.value = parseFloat(order.totalAmount || 0)
      siteCurrency.value = order.currency || 'USD'
      currencySymbol.value = order.currencySymbol || '$'
    } else {
      throw new Error(result.message || 'Failed to fetch order info')
    }
  } catch (error) {
    console.error('获取订单信息失败:', error)
    ElMessage.error(t('msgFailed'))
    showPayoneerPayment.value = false
    isProcessingPayment.value = false
  }
}

// Payoneer 支付成功回调
const handlePayoneerSuccess = () => {
  // Payoneer 会跳转到支付页面，不需要在这里处理
}

// Payoneer 支付错误回调
const handlePayoneerError = (error) => {
  console.error('Payoneer 支付错误:', error)
  showPayoneerPayment.value = false
  isProcessingPayment.value = false
  ElMessage.error(t('msgFailed'))
}

onMounted(async () => {
  // 加载翻译
  await loadTranslations()
  // 监听语言变化
  window.addEventListener('languagechange', handleLangChange)
  
  // 等待下一帧，确保 DOM 完全渲染
  await new Promise(resolve => requestAnimationFrame(resolve))
  
  // 标记组件已就绪
  isComponentReady.value = true
  console.log('✅ OrderStatusMonitor 组件已完全就绪')
  
  // 如果有等待的订单号且弹窗可见，现在建立连接
  if (pendingOrderNo.value && props.isVisible && !eventSource.value) {
    console.log(`✅ 组件就绪后建立 Mercure 连接: ${pendingOrderNo.value}`)
    subscribeMercure(pendingOrderNo.value)
    pendingOrderNo.value = '' // 清除待处理的订单号
  }
})

onBeforeUnmount(() => {
  // 组件销毁时使用彻底清理
  cleanupConnection()
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
/* 动画 */
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.animate-spin {
  animation: spin 1s linear infinite;
}

/* Teleport 样式 */
:deep(.fixed.inset-0) {
  background-color: rgba(0, 0, 0, 0.35) !important;
}

:deep(.fixed.bg-white) {
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12) !important;
  border-radius: 6px !important;
}

:deep(.flex.items-center.justify-between.h-12) {
  height: 50px !important;
  padding: 0 16px !important;
}

:deep(.text-base.font-medium.text-gray-800) {
  color: #333333 !important;
  font-size: 16px !important;
  font-weight: 600 !important;
}

:deep(.text-gray-400.hover\:text-gray-600) {
  color: #bfbfbf !important;
  transition: color 0.2s ease !important;
}

:deep(.text-gray-400.hover\:text-gray-600:hover) {
  color: #FF6600 !important;
}

:deep(.overflow-y-auto) {
  background-color: #ffffff !important;
}

:deep(.text-center) {
  text-align: center;
}

:deep(.text-5xl) {
  font-size: 48px;
  line-height: 1;
}

:deep(.text-base.font-semibold.text-gray-800) {
  color: #333333 !important;
  font-size: 16px !important;
  font-weight: 600 !important;
}

:deep(.text-sm.text-gray-600) {
  color: #666666 !important;
  font-size: 14px !important;
}

:deep(.text-xs.text-gray-500) {
  color: #999999 !important;
  font-size: 12px !important;
}

:deep(.text-sm.text-red-600) {
  color: #ff4d4f !important;
  font-size: 14px !important;
}

:deep(.flex.items-center.justify-end.gap-2.h-12) {
  height: 50px !important;
  padding: 0 16px !important;
  gap: 8px !important;
}

:deep(.text-white.rounded) {
  color: #ffffff !important;
  background-color: #FF6600 !important;
  border: none !important;
  border-radius: 4px !important;
  padding: 8px 16px !important;
  font-size: 12px !important;
  font-weight: 500 !important;
  cursor: pointer !important;
  transition: all 0.2s ease !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  min-height: 32px !important;
}

:deep(.text-white.rounded:hover) {
  background-color: #FF7722 !important;
  box-shadow: 0 2px 8px rgba(255, 102, 0, 0.15) !important;
}

@media (max-width: 900px) {
  :deep(.fixed.bg-white) {
    width: 85% !important;
    max-width: 520px !important;
  }
}

@media (max-width: 640px) {
  :deep(.fixed.bg-white) {
    width: 90% !important;
    max-width: 450px !important;
  }
}

/* 支付方式选择样式 */
.grid.grid-cols-3.gap-4 {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-right: -10px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.gap-4 {
  margin-right: -10px;
}

.gap-4 > * {
  margin-right: 10px;
}


.border-purple-500 {
  border-color: #a855f7 !important;
}

.bg-purple-50 {
  background-color: #faf5ff;
}

.border-cyan-500 {
  border-color: #06b6d4 !important;
}

.bg-cyan-50 {
  background-color: #ecf8ff;
}
</style>

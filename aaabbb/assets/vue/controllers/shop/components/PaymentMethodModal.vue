<template>
  <Teleport to="body" v-if="isOpen">
    <!-- 背景遮罩 - 禁止点击关闭 -->
    <div class="fixed inset-0 bg-black/30 z-40"></div>

    <!-- 模态对话框 -->
    <div class="fixed bg-white rounded-lg shadow-2xl z-50" :style="{ width: '700px', maxHeight: '90vh', top: '50%', left: '50%', transform: 'translate(-50%, -50%)' }">
      <!-- 标题栏 -->
      <div class="flex items-center justify-between h-14 px-5 border-b border-gray-200 bg-gray-50 rounded-t-lg">
        <h2 class="text-base font-medium text-gray-800">{{ t('title') }}</h2>
        <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" @click="closeModal">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- 内容区域 -->
      <div class="overflow-y-auto" :style="{ maxHeight: 'calc(90vh - 128px)' }">
        <div class="p-6">
          <!-- 订单信息摘要 -->
          <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-6">
            <!-- 单个商品时：直接显示 -->
            <div v-if="!productList || productList.length <= 1" class="flex gap-4">
              <!-- 商品缩略图 -->
              <div class="flex-shrink-0">
                <img
                  v-if="productImage"
                  :src="productImage"
                  :alt="displayProductTitle"
                  class="w-20 h-20 object-cover rounded border border-gray-300"
                />
                <div v-else class="w-20 h-20 bg-gray-200 rounded border border-gray-300 flex items-center justify-center text-gray-500 text-xs">
                  {{ t('noImage') }}
                </div>
              </div>
              <!-- 商品信息 -->
              <div class="flex-1">
                <p class="text-sm text-gray-600 mb-2">
                  {{ t('productLabel') }}<span class="text-gray-900 font-medium">{{ displayProductTitle }}</span>
                </p>
                
                <!-- 总价 -->
                <p class="text-sm text-gray-600 mb-2">
                  {{ t('totalPriceLabel') }}<span class="text-lg font-bold text-orange-600">{{ totalPrice }}</span>
                </p>
                
                <p class="text-sm text-gray-600 mb-2">
                  {{ t('quantityLabel') }}<span class="text-gray-900 font-medium">{{ quantity }}</span>
                </p>
                
                <!-- 价格明细 -->
                <div v-if="priceBreakdown && priceBreakdown.length > 0" class="mt-3 pt-3 border-t border-gray-300">
                  <p class="text-xs text-gray-500 mb-2 font-medium">{{ t('totalReduction') }}</p>
                  <div v-for="(item, index) in priceBreakdown" :key="index" class="flex justify-between text-xs text-gray-600 mb-1">
                    <span>{{ item.label }}</span>
                    <span :class="item.amount < 0 ? 'text-green-600' : 'text-gray-900'">
                      <!-- 【原有显示逻辑 - 已注释】原逻辑：使用明细项中的 currency 字段显示货币符号 -->
                      <!-- {{ item.amount < 0 ? '' : '+' }}{{ item.currency }} {{ Math.abs(item.amount).toFixed(2) }} -->
                      <!-- 【新逻辑】使用从SiteConfig读取的网站货币符号 -->
                      {{ item.amount < 0 ? '' : '+' }}{{ siteCurrency }} {{ Math.abs(item.amount).toFixed(2) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- 多个商品时：折叠展示 -->
            <div v-else>
              <!-- 第一个商品（始终显示） -->
              <div class="flex gap-4 mb-3">
                <div class="flex-shrink-0">
                  <img
                    v-if="productList[0].image"
                    :src="productList[0].image"
                    :alt="productList[0].title"
                    class="w-16 h-16 object-cover rounded border border-gray-300"
                  />
                  <div v-else class="w-16 h-16 bg-gray-200 rounded border border-gray-300 flex items-center justify-center text-gray-500 text-xs">
                    {{ t('noImage') }}
                  </div>
                </div>
                <div class="flex-1">
                  <p class="text-sm text-gray-900 font-medium mb-1">{{ productList[0].title }}</p>
                  <p class="text-xs text-gray-600">{{ t('quantityLabel') }}{{ productList[0].quantity }}</p>
                  <p class="text-sm text-orange-600 font-bold mt-1">{{ productList[0].price }}</p>
                </div>
              </div>
              
              <!-- 折叠的其他商品 -->
              <div v-show="isProductListExpanded" class="border-t border-gray-300 pt-3">
                <div v-for="(product, index) in productList.slice(1)" :key="index" class="flex gap-4 mb-3">
                  <div class="flex-shrink-0">
                    <img
                      v-if="product.image"
                      :src="product.image"
                      :alt="product.title"
                      class="w-16 h-16 object-cover rounded border border-gray-300"
                    />
                    <div v-else class="w-16 h-16 bg-gray-200 rounded border border-gray-300 flex items-center justify-center text-gray-500 text-xs">
                      {{ t('noImage') }}
                    </div>
                  </div>
                  <div class="flex-1">
                    <p class="text-sm text-gray-900 font-medium mb-1">{{ product.title }}</p>
                    <p class="text-xs text-gray-600">{{ t('quantityLabel') }}{{ product.quantity }}</p>
                    <p class="text-sm text-orange-600 font-bold mt-1">{{ product.price }}</p>
                  </div>
                </div>
              </div>
              
              <!-- 更多商品按钮 -->
              <button 
                type="button"
                class="w-full mt-3 flex items-center justify-center gap-2 text-sm text-gray-600 hover:text-orange-600 transition-colors"
                @click="isProductListExpanded = !isProductListExpanded"
              >
                <span>{{ isProductListExpanded ? (currentLang === 'en' ? 'Collapse' : '收起') : (currentLang === 'en' ? 'More Products' : '更多商品') }}</span>
                <svg 
                  class="w-4 h-4 transition-transform"
                  :class="{ 'rotate-180': isProductListExpanded }"
                  fill="none" 
                  stroke="currentColor" 
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
              </button>
              
              <!-- 价格明细（多商品时） -->
              <div v-if="priceBreakdown && priceBreakdown.length > 0" class="mt-3 pt-3 border-t border-gray-300">
                <p class="text-xs text-gray-500 mb-2 font-medium">{{ t('totalReduction') }}</p>
                <div v-for="(item, index) in priceBreakdown" :key="index" class="flex justify-between text-xs text-gray-600 mb-1">
                  <span>{{ item.label }}</span>
                  <span :class="item.amount < 0 ? 'text-green-600' : 'text-gray-900'">
                    <!-- 【原有显示逻辑 - 已注释】原逻辑：使用明细项中的 currency 字段显示货币符号 -->
                    <!-- {{ item.amount < 0 ? '' : '+' }}{{ item.currency }} {{ Math.abs(item.amount).toFixed(2) }} -->
                    <!-- 【新逻辑】使用从SiteConfig读取的网站货币符号 -->
                    {{ item.amount < 0 ? '' : '+' }}{{ siteCurrency }} {{ Math.abs(item.amount).toFixed(2) }}
                  </span>
                </div>
              </div>
              
              <!-- 总价 -->
              <div class="mt-3 pt-3 border-t border-gray-300">
                <p class="text-sm text-gray-600">
                  {{ t('totalPriceLabel') }}<span class="text-lg font-bold text-orange-600">{{ totalPrice }}</span>
                </p>
              </div>
            </div>
          </div>

          <!-- 收货地址选择 -->
          <div class="mb-6">
            <p class="text-base font-medium text-gray-900 mb-3">
              {{ currentLang === 'en' ? 'Shipping Address' : '收货地址' }}
            </p>
            <div v-if="isLoadingAddresses" class="text-sm text-gray-500">
              {{ currentLang === 'en' ? 'Loading addresses...' : '加载地址中...' }}
            </div>
            <div v-else-if="addresses.length === 0" class="text-sm text-orange-600">
              {{ currentLang === 'en' ? 'No shipping address found' : '未找到收货地址' }}
            </div>
            <select 
              v-else
              v-model="selectedAddressId" 
              class="address-select w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
            >
              <option 
                v-for="address in addresses" 
                :key="address.id" 
                :value="address.id"
              >
                {{ formatAddress(address) }}
              </option>
            </select>
          </div>
        </div>
      </div>

      <!-- 底部操作按钮 -->
      <div class="flex items-center justify-end gap-3 h-16 px-6 border-t border-gray-200 bg-gray-50 rounded-b-lg">
        <button
          type="button"
          class="px-6 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
          @click="closeModal"
        >
          {{ t('cancelButton') }}
        </button>
        <button
          type="button"
          class="px-6 py-2 text-sm font-medium text-white rounded-lg transition-colors"
          style="background-color: #FF6600;"
          @click="handlePayment"
          @mouseenter="$event.target.style.backgroundColor = '#FF8833'"
          @mouseleave="$event.target.style.backgroundColor = '#FF6600'"
        >
          {{ t('confirmButton') }}
        </button>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, defineProps, defineEmits } from 'vue'
import { ElMessage } from 'element-plus'
import apiSignature from '../services/apiSignature.js'

// 页面翻译数据
const translations = ref({})

// 当前语言（从 localStorage 读取初始值）
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/PaymentMethodModal.json')
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

// 商品标题显示：根据语言动态选择
const displayProductTitle = computed(() => {
  // 使用 currentLang.value 确保响应式更新
  const lang = currentLang.value
  // 中文环境显示中文标题
  if (lang === 'zh-CN') {
    return props.productTitle || ''
  }
  // 英文环境优先显示英文标题，没有英文标题则显示中文标题
  return props.productTitleEn || props.productTitle || ''
})

const props = defineProps({
  isOpen: {
    type: Boolean,
    required: true
  },
  productTitle: {
    type: String,
    default: ''
  },
  productTitleEn: {
    type: String,
    default: ''
  },
  productImage: {
    type: String,
    default: ''
  },
  quantity: {
    type: Number,
    default: 1
  },
  totalPrice: {
    type: String,
    required: true
  },
  priceBreakdown: {
    type: Array,
    default: () => []
  },
  // 新增：支持多商品列表
  productList: {
    type: Array,
    default: null  // null 表示使用单商品模式
    // 格式：[{ title: '', image: '', quantity: 1, price: '' }, ...]
  },
  // 物流方式（仅用于显示，不在弹窗内选择）
  shippingMethod: {
    type: String,
    default: 'STANDARD_SHIPPING'
  },
  // 网站货币符号（从SiteConfig读取）
  siteCurrency: {
    type: String,
    default: 'USD'
  }
})

const emit = defineEmits(['close', 'confirm'])

const selectedMethod = ref(null)
const addresses = ref([]) // 地址列表
const selectedAddressId = ref(null) // 选中的地址ID
const isLoadingAddresses = ref(false) // 加载地址状态
const isProductListExpanded = ref(false) // 商品列表展开状态

// 从 window 对象获取 store 实例
const store = window.vueStore

// 加载地址列表
const loadAddresses = async () => {
  isLoadingAddresses.value = true
  
  try {
    // 准备签名数据
    const signedData = apiSignature.sign({})
    const queryParams = new URLSearchParams({
      timestamp: signedData.timestamp.toString(),
      nonce: signedData.nonce,
      signature: signedData.signature
    })
    
    // 调用后端API获取地址列表
    const response = await fetch(`/shop/api/customer/address/list?${queryParams.toString()}`, {
      method: 'GET',
      credentials: 'include'
    })
    
    const result = await response.json()
    
    if (result.success) {
      addresses.value = result.data || []
      
      // 默认选中默认地址
      const defaultAddress = addresses.value.find(addr => addr.isDefault)
      if (defaultAddress) {
        selectedAddressId.value = defaultAddress.id
      } else if (addresses.value.length > 0) {
        // 如果没有默认地址，选中第一个
        selectedAddressId.value = addresses.value[0].id
      }
    } else {
      const lang = currentLang.value
      const errorMsg = (lang === 'en' ? result.messageEn : result.message) || (lang === 'en' ? 'Failed to load addresses' : '加载地址失败')
      ElMessage.error(errorMsg)
    }
  } catch (error) {
    console.error('加载地址失败:', error)
    
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'Failed to load addresses' : '加载地址失败')
  } finally {
    isLoadingAddresses.value = false
  }
}

// 监听弹窗打开状态，打开时加载地址
watch(() => props.isOpen, (newVal) => {
  if (newVal) {
    loadAddresses()
    isProductListExpanded.value = false // 重置折叠状态
    
    // 调试：打印价格明细数据
    console.log('👁️ 弹窗打开，接收到的 priceBreakdown:', props.priceBreakdown)
    console.log('📊 priceBreakdown 长度:', props.priceBreakdown?.length)
  } else {
    // 关闭时清空选择
    selectedMethod.value = null
    selectedAddressId.value = null
  }
})

// 监听 priceBreakdown 变化
watch(() => props.priceBreakdown, (newVal) => {
  console.log('🔄 priceBreakdown 更新:', newVal)
}, { deep: true })

// 格式化地址显示文本
const formatAddress = (address) => {
  if (!address) return ''
  const parts = []
  if (address.receiverName) parts.push(address.receiverName)
  if (address.receiverPhone) parts.push(address.receiverPhone)
  if (address.receiverAddress) parts.push(address.receiverAddress)
  return parts.join(' - ')
}

function closeModal() {
  selectedMethod.value = null
  selectedAddressId.value = null
  emit('close')
}

function handlePayment() {
  if (!selectedAddressId.value) {
    const lang = currentLang.value
    ElMessage.warning(lang === 'en' ? 'Please select a shipping address' : '请选择收货地址')
    return
  }
  
  // 仅传递地址ID，不传递支付方式
  emit('confirm', {
    addressId: selectedAddressId.value
  })
  closeModal()
}

onMounted(() => {
  loadTranslations()
  window.addEventListener('languagechange', handleLangChange)
})

onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
/* ============================================
   支付方式模态框 - 轻盈小巧风格设计
   ============================================ */

/* 基础样式 */
div {
  box-sizing: border-box;
}

/* 背景遮罩 */
:deep(.fixed.inset-0) {
  background-color: rgba(0, 0, 0, 0.35);
}

/* 模态框主容器 - 更紧凑 */
:deep(.fixed.bg-white) {
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  border-radius: 6px;
  background: #ffffff;
  width: 550px !important;
  max-height: 75vh !important;
}

/* 标题栏 - 更紧凑 */
:deep(.border-b.border-gray-200) {
  background: #ffffff;
  border-bottom: 1px solid #f0f0f0;
  height: 50px !important;
  padding: 0 16px !important;
}

:deep(.text-base.font-medium.text-gray-800) {
  color: #333333;
  font-size: 16px;
  font-weight: 600;
}

/* 关闭按钮 */
:deep(.text-gray-400.hover\:text-gray-600) {
  color: #bfbfbf;
  transition: color 0.2s ease;
}

:deep(.text-gray-400.hover\:text-gray-600:hover) {
  color: #FF6600;
}

/* 内容区域背景 */
:deep(.overflow-y-auto) {
  background-color: #ffffff;
}

/* 内容容器 - 减少padding */
:deep(.p-6) {
  padding: 16px !important;
}

/* 订单信息摘要卡片 - 更紧凑 */
:deep(.bg-gray-50.p-4.rounded-lg) {
  background-color: #fafafa;
  border: 1px solid #f0f0f0;
  border-radius: 6px;
  margin-bottom: 12px !important;
  padding: 12px !important;
}

/* 商品缩略图 */
:deep(.w-20.h-20) {
  width: 64px !important;
  height: 64px !important;
  flex-shrink: 0;
}

/* 商品信息文本 */
:deep(.text-sm.text-gray-600) {
  color: #666666;
  font-size: 13px;
  line-height: 20px;
  margin-bottom: 6px !important;
}

:deep(.text-sm.text-gray-600):not(.notice-text) {
  color: #666666;
}

/* 总价显示 */
:deep(.text-lg.font-bold.text-orange-600) {
  color: #FF6600;
  font-size: 16px;
  font-weight: 700;
}

/* 支付方式选择标题 */
:deep(.text-base.font-medium.text-gray-900) {
  color: #333333;
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 10px !important;
}

/* 支付方式卡片 - 更紧凑的网格 */
:deep(.grid.grid-cols-3.gap-4) {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-bottom: 12px !important;
}

:deep(.flex.flex-col.items-center.justify-center.w-full.p-4) {
  border: 1px solid #e8e8e8;
  border-radius: 6px;
  padding: 10px !important;
  cursor: pointer;
  transition: all 0.2s ease;
  background-color: #ffffff;
}

:deep(.flex.flex-col.items-center.justify-center.w-full.p-4:hover) {
  border-color: #FF6600;
  box-shadow: 0 2px 8px rgba(255, 102, 0, 0.08);
  transform: none;
}

/* 选中的支付方式 */
:deep(.border-orange-500) {
  border-color: #FF6600 !important;
  background-color: #fffaf5;
  box-shadow: 0 2px 8px rgba(255, 102, 0, 0.1);
}

:deep(.bg-orange-50) {
  background-color: #fffaf5;
}

/* 支付方式图标 - 更小 */
:deep(.w-12.h-12.mb-3) {
  width: 36px !important;
  height: 36px !important;
  margin-bottom: 6px !important;
  flex-shrink: 0;
}

/* 支付方式标签 */
:deep(.text-sm.font-medium.text-gray-800.text-center) {
  color: #333333;
  font-size: 13px;
  font-weight: 500;
  text-align: center;
}

/* 支付方式描述 */
:deep(.text-xs.text-gray-500.mt-1) {
  color: #999999;
  font-size: 11px;
  margin-top: 2px !important;
  text-align: center;
  display: none;
}

/* 其他支付方式颜色 */
:deep(.border-blue-500) {
  border-color: #3b82f6 !important;
}

:deep(.bg-blue-50) {
  background-color: #f0f9ff;
}

:deep(.border-green-500) {
  border-color: #10b981 !important;
}

:deep(.bg-green-50) {
  background-color: #f0fdf4;
}

:deep(.border-yellow-500) {
  border-color: #eab308 !important;
}

:deep(.bg-yellow-50) {
  background-color: #fefce8;
}

:deep(.border-purple-500) {
  border-color: #a855f7 !important;
}

:deep(.bg-purple-50) {
  background-color: #faf5ff;
}

:deep(.text-purple-600) {
  color: #a855f7;
}

:deep(.border-cyan-500) {
  border-color: #06b6d4 !important;
}

:deep(.bg-cyan-50) {
  background-color: #ecf8ff;
}

/* 提示框 - 精致温馨的黄色设计 */
.payment-notice {
  background: linear-gradient(135deg, #fffbf5 0%, #fff8ef 100%);
  border: none;
  border-radius: 8px;
  padding: 14px !important;
  margin-bottom: 12px !important;
  box-shadow: 0 2px 8px rgba(255, 153, 51, 0.08);
  transition: all 0.3s ease;
}

.payment-notice:hover {
  box-shadow: 0 3px 12px rgba(255, 153, 51, 0.12);
}

.payment-notice .flex.items-start {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-right: -12px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.items-start {
  margin-right: -12px;
}

.items-start > * {
  margin-right: 12px;
}


.notice-icon {
  width: 20px !important;
  height: 20px !important;
  flex-shrink: 0;
  margin-top: 1px;
  color: #ff8800;
  filter: drop-shadow(0 1px 2px rgba(255, 136, 0, 0.2));
}

.notice-text {
  font-size: 13px;
  line-height: 22px;
  color: #8b5a00;
  flex: 1;
}

.notice-title {
  font-weight: 600;
  color: #ff6600;
}

/* 底部操作栏 - 更紧凑 */
:deep(.flex.items-center.justify-end.gap-3) {
  background: #ffffff;
  border-top: 1px solid #f0f0f0;
  padding: 10px 16px !important;
  border-radius: 0 0 6px 6px;
  gap: 8px !important;
  height: 50px !important;
}

/* 取消按钮 */
:deep(.text-gray-700.bg-white.border.border-gray-300) {
  color: #666666 !important;
  background-color: #ffffff !important;
  border: 1px solid #d9d9d9 !important;
  border-radius: 4px !important;
  padding: 8px 16px !important;
  font-size: 13px !important;
  font-weight: 500 !important;
  cursor: pointer !important;
  transition: all 0.2s ease !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  min-height: 32px !important;
  line-height: 1 !important;
}

:deep(.text-gray-700.bg-white.border.border-gray-300:hover) {
  background-color: #fafafa !important;
  border-color: #bfbfbf !important;
  color: #333333 !important;
}

/* 确认支付按钮 */
:deep(.text-white.rounded-lg) {
  color: #ffffff !important;
  background-color: #FF6600 !important;
  border: none !important;
  border-radius: 4px !important;
  padding: 8px 20px !important;
  font-size: 13px !important;
  font-weight: 500 !important;
  cursor: pointer !important;
  transition: all 0.2s ease !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  min-height: 32px !important;
  line-height: 1 !important;
}

:deep(.text-white.rounded-lg:hover:not(:disabled)) {
  background-color: #FF7722 !important;
  box-shadow: 0 2px 8px rgba(255, 102, 0, 0.15) !important;
}

:deep(.text-white.rounded-lg:disabled) {
  background-color: #d9d9d9 !important;
  cursor: not-allowed !important;
  opacity: 1 !important;
}

/* 响应式调整 */
@media (max-width: 900px) {
  :deep(.fixed.bg-white) {
    width: 85% !important;
    max-width: 520px !important;
  }

  :deep(.grid.grid-cols-3.gap-4) {
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
  }
}

@media (max-width: 640px) {
  :deep(.fixed.bg-white) {
    width: 90% !important;
    max-width: 450px !important;
  }

  :deep(.grid.grid-cols-3.gap-4) {
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
  }

  :deep(.flex.gap-4) {
    gap: 8px;
  }

  :deep(.w-20.h-20) {
    width: 56px !important;
    height: 56px !important;
  }
}

/* ============================================
   地址选择下拉框样式
   ============================================ */
.address-select {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #d9d9d9;
  border-radius: 6px;
  font-size: 13px;
  color: #333333;
  background-color: #ffffff;
  cursor: pointer;
  transition: all 0.2s ease;
  outline: none;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666666' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  padding-right: 32px;
}

.address-select:hover {
  border-color: #d9d9d9;
}

.address-select:focus {
  border-color: #d9d9d9;
  box-shadow: none;
  outline: none;
}

.address-select option {
  padding: 8px;
  color: #333333;
  background-color: #ffffff;
}
</style>

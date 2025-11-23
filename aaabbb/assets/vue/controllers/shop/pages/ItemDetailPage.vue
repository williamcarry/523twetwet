<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { Check, X, ChevronRight } from 'lucide-vue-next'
import { ElMessage, ElMessageBox } from 'element-plus'
import SiteHeader from '@/components/SiteHeader.vue'
import SiteFooter from '@/components/SiteFooter.vue'
import OneClickPublishButton from '@/components/OneClickPublishButton.vue'
import OneClickPublishModal from '@/components/OneClickPublishModal.vue'
import PaymentMethodModal from '@/components/PaymentMethodModal.vue'
import OrderStatusMonitor from '@/components/OrderStatusMonitor.vue'
import RelatedProducts from '@/components/RelatedProducts.vue'
import ProductDetailTabs from '@/components/ProductDetailTabs.vue'
import InquiryFileUpload from '@/components/InquiryFileUpload.vue'
import encryptionService from '../data/encryption-service.js'
import apiSignature from '../services/apiSignature.js'
import { fetchWithSignature } from '../services/tokenRefresh.js'  // 新增：导入带签名的 fetch 封装

// 页面翻译数据
const translations = ref({})

// 当前语言 - 从 localStorage 读取初始值
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/ItemDetailPage.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
  }
}

// 翻译函数 - 直接从页面特定的JSON文件读取
const t = (key) => {
  // 使用 currentLang.value 确保响应式
  const lang = currentLang.value
  
  // 从页面特定的翻译文件中获取翻译
  if (translations.value[lang] && translations.value[lang][key]) {
    return translations.value[lang][key]
  }
  
  // 如果没有找到翻译，返回键名
  return key
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
    // 翻译加载完成后设置标题
    updatePageTitle()
  })
}

// 从 Symfony 接收 props
const props = defineProps({
  id: {
    type: [String, Number],
    default: ''
  }
})

const productId = computed(() => {
  // 优先使用从 Symfony 传递的 id
  if (props.id) return String(props.id).replace(/\.html$/i, '')
  
  // 备用方案：从 URL 路径中提取
  const pathMatch = window.location.pathname.match(/\/item\/([^/?]+)/)
  return pathMatch ? pathMatch[1].replace(/\.html$/i, '') : ''
})

const product = ref(undefined)
const plinfo = ref(undefined)
const selectedImage = ref('')
const selectedIndex = ref(0)
const thumbnailOffset = ref(0)
const quantity = ref(1)

// 网站货币符号（从SiteConfig读取）
const siteCurrency = ref('USD')

const activeTab = ref('dropship')
const isPublishModalOpen = ref(false)
const isPaymentModalOpen = ref(false)
const showOrderMonitor = ref(false)
const processingOrderNo = ref('')
const selectedRegion = ref('') // 当前选中的发货区域
const selectedShippingMethod = ref('STANDARD_SHIPPING') // 当前选中的物流方式，默认标准物流
const quantityError = ref('') // 数量输入错误提示
const isAddingToCart = ref(false) // 加入购物车加载状态
const isDownloading = ref(false) // 下载商品数据加载状态

// 工厂直采询价表单数据
const inquiryForm = ref({
  contactName: '',
  contactPhone: '',
  inquiryQuantity: 1,
  requirementDescription: '',
  attachments: []
})

const inquiryFormRef = ref(null)
const isSubmittingInquiry = ref(false)
const fileUploadKey = ref(0) // 用于强制重置附件上传组件

// 从 window 对象获取 store 实例
const store = window.vueStore

// 获取用户状态
const user = computed(() => store?.state?.user || null)
const isLoggedIn = computed(() => store?.state?.isLoggedIn || false)

// 获取当前用户的VIP等级
const userVipLevel = computed(() => {
  if (isLoggedIn.value && user.value) {
    return user.value.vipLevel || 0
  }
  return 0
})

// 获取当前用户的VIP等级名称
const userVipLevelName = computed(() => {
  if (isLoggedIn.value && user.value) {
    // 根据语言返回对应的VIP等级名称（使用 currentLang.value 确保响应式）
    const lang = currentLang.value
    if (lang === 'en') {
      // 英文：优先使用 vipLevelNameEn，如果没有则返回 'Normal User'
      return user.value.vipLevelNameEn || 'Normal User'
    }
    // 中文：使用 vipLevelName，如果没有则返回 '普通用户'
    return user.value.vipLevelName || '普通用户'
  }
  // 未登录时根据语言返回对应文本
  const lang = currentLang.value
  if (lang === 'en') {
    return translations.value['en']?.normalUser || 'Normal User'
  }
  return translations.value['zh-CN']?.normalUser || '普通用户'
})

// 获取当前区域当前用户VIP等级的会员折扣信息
const currentVipPriceInfo = computed(() => {
  const currentRegionData = getCurrentRegionData.value
  const vipLevel = userVipLevel.value
  
  console.log('=== VIP价格信息调试 ===')
  console.log('当前区域数据:', currentRegionData)
  console.log('价格对象:', currentRegionData?.price)
  console.log('用户VIP等级:', vipLevel)
  
  if (!currentRegionData || !currentRegionData.price || !currentRegionData.price.vipPrices) {
    console.log('没有VIP价格数据 - price对象keys:', currentRegionData?.price ? Object.keys(currentRegionData.price) : 'null')
    return null
  }
  
  // 从vipPrices数组中查找对应等级的价格信息
  const vipPrices = currentRegionData.price.vipPrices
  console.log('VIP价格数组:', vipPrices)
  const vipPriceData = vipPrices.find(vp => vp.vipLevel === vipLevel)
  console.log('当前等级VIP价格:', vipPriceData)
  
  return vipPriceData || null
})

// 计算会员折扣价（从后端返回的vipPrices中获取）
const memberPrice = computed(() => {
  const vipInfo = currentVipPriceInfo.value
  
  console.log('=== 会员价格计算 ===')
  console.log('VIP信息:', vipInfo)
  
  if (vipInfo && vipInfo.price) {
    const price = parseFloat(vipInfo.price)
    console.log('会员价格:', price)
    return price
  }
  
  console.log('无会员价格')
  return 0
})

// 原价（从当前区域配置中获取）
const originalPrice = computed(() => {
  const currentRegionData = getCurrentRegionData.value
  if (currentRegionData && currentRegionData.price) {
    return parseFloat(currentRegionData.price.originalPrice) || 0
  }
  return 0
})

// 显示的价格：如果用户有会员折扣，显示会员价格，否则显示基础售价
const displayPrice = computed(() => {
  if (memberPrice.value > 0) {
    return memberPrice.value
  }
  return basePrice.value
})

// 折扣百分比文本：如果有会员折扣，显示便宜的百分比（带减号）
const discountPercentText = computed(() => {
  const vipInfo = currentVipPriceInfo.value
  const selling = basePrice.value
  const memberPriceValue = memberPrice.value
  
  if (vipInfo && memberPriceValue > 0 && memberPriceValue < selling) {
    // 计算便宜的百分比：(售价 - 会员价) / 售价 * 100
    const savePercent = ((selling - memberPriceValue) / selling) * 100
    return `-${savePercent.toFixed(0)}%`
  }
  return ''
})

// 折扣文本：如果有会员折扣，显示折扣信息
const discountText = computed(() => {
  const vipInfo = currentVipPriceInfo.value
  
  if (vipInfo && vipInfo.discount) {
    const discount = parseFloat(vipInfo.discount)
    return `${discount.toFixed(1)}${t('discount')}`
  }
  return t('noDiscount')
})

// 基础价格（原价）
const basePrice = computed(() => {
  const currentRegionData = getCurrentRegionData.value
  if (currentRegionData && currentRegionData.price) {
    return parseFloat(currentRegionData.price.sellingPrice) || 0
  }
  return product.value?.basePrice || 0
})

const mainImageUrl = computed(() => {
  return (
    selectedImage.value ||
    (product.value?.images && product.value.images[selectedIndex.value]?.url) ||
    product.value?.mainImage ||
    ''
  )
})

// 总价格显示（使用后端计算的价格，而不是前端计算）
const totalPrice = computed(() => {
  // 如果有最新计算的价格，使用后端返回的总价
  if (latestCalculatedPrice.value && latestCalculatedPrice.value.totalPrice) {
    // 【原有显示逻辑 - 已注释】
    // 原逻辑：使用后端返回的currency字段或currentCurrency
    // const currency = latestCalculatedPrice.value.currency || currentCurrency.value
    // return `${currency} ${latestCalculatedPrice.value.totalPrice}`
    
    // 【新逻辑】使用从SiteConfig读取的网站货币符号
    return `${siteCurrency.value} ${latestCalculatedPrice.value.totalPrice}`
  }
  
  // 如果还没有后端计算结果（初始加载时），显示默认价格
  // 【原有显示逻辑 - 已注释】
  // 原逻辑：使用currentCurrency（从区域配置读取）
  // const currency = currentCurrency.value
  // const price = displayPrice.value * quantity.value
  // return `${currency} ${price.toFixed(2)}`
  
  // 【新逻辑】使用从SiteConfig读取的网站货币符号
  const price = displayPrice.value * quantity.value
  return `${siteCurrency.value} ${price.toFixed(2)}`
})

// 商品标题显示：优先英文，无英文时显示中文
const displayTitle = computed(() => {
  // 使用 currentLang.value 确保响应式更新
  const lang = currentLang.value
  // 中文环境显示中文标题
  if (lang === 'zh-CN') {
    return product.value?.title
  }
  // 英文环境优先显示英文标题，没有英文标题则显示中文标题
  return product.value?.titleEn || product.value?.title
})

// 格式化满减券显示文本
const couponText = computed(() => {
  const currentRegionData = getCurrentRegionData.value
  
  // 先检查当前区域是否有满减
  if (currentRegionData && currentRegionData.discountRule) {
    const { currency, minAmount, discountAmount } = currentRegionData.discountRule
    const lang = currentLang.value
    if (lang === 'en') {
      return `${t('couponTextFull')}${currency}${parseFloat(minAmount).toFixed(2)}${t('couponTextMinus')}${currency}${parseFloat(discountAmount).toFixed(2)}`
    }
    return `${t('couponTextFull')}${currency}${parseFloat(minAmount).toFixed(2)}${t('couponTextMinus')}${currency}${parseFloat(discountAmount).toFixed(2)}`
  }
  
  // 没有满减，返回"无活动"
  return t('noActivity')
})

// 仓库类型显示文本
const warehouseTypeText = computed(() => {
  return t('warehouseTypeSY')
})

// 发货区域列表（从 JSON 数组获取）
const shippingRegions = computed(() => {
  if (!product.value?.shippingRegion || !Array.isArray(product.value.shippingRegion)) {
    return []
  }
  return product.value.shippingRegion.map((code, index) => ({
    code: code,
    label: code
  }))
})

// 获取当前选中区域的数据
const getCurrentRegionData = computed(() => {
  const region = selectedRegion.value
  if (!region || !product.value?.regionConfigs || !product.value.regionConfigs[region]) {
    return null
  }
  return product.value.regionConfigs[region]
})

// 获取当前区域的库存
const currentStock = computed(() => {
  const currentRegionData = getCurrentRegionData.value
  if (currentRegionData) {
    return currentRegionData.stock || 0
  }
  return product.value?.stock || 0
})

// 获取当前区域的最小起订数量（默认为1）
const minOrderQuantity = computed(() => {
  const currentRegionData = getCurrentRegionData.value
  if (currentRegionData && currentRegionData.minOrderQty) {
    return parseInt(currentRegionData.minOrderQty) || 1
  }
  return 1
})

// 获取当前区域的货币信息
const currentCurrency = computed(() => {
  // 【原有显示逻辑 - 已注释】
  // 原逻辑：从商品数据中读取当前区域的货币符号
  // const currentRegionData = getCurrentRegionData.value
  // if (currentRegionData && currentRegionData.price) {
  //   return currentRegionData.price.currency || 'CNY'
  // }
  // return product.value?.currency || 'CNY'
  
  // 【新逻辑】使用从SiteConfig读取的网站货币符号
  return siteCurrency.value
})

// 获取当前区域的运费（根据选择的物流方式和购买数量）
const currentShippingFee = computed(() => {
  // 如果选择自提，运费为0
  if (selectedShippingMethod.value === 'SELF_PICKUP') {
    return 0
  }
  
  // 标准物流，从当前区域获取运费
  const currentRegionData = getCurrentRegionData.value
  if (currentRegionData && currentRegionData.shipping) {
    const shippingPrice = parseFloat(currentRegionData.shipping.shippingPrice) || 0 // 首件运费
    const additionalPrice = parseFloat(currentRegionData.shipping.additionalPrice) || 0 // 续件运费
    const qty = quantity.value
    
    // 如果数量为1，只收首件运费
    if (qty <= 1) {
      return shippingPrice
    }
    
    // 如果数量大于1，计算：首件运费 + 续件运费 × (数量 - 1)
    const totalShipping = shippingPrice + (additionalPrice * (qty - 1))
    return totalShipping
  }
  return 0
})

// 格式化运费显示
const formattedShippingFee = computed(() => {
  const fee = currentShippingFee.value
  const currency = currentCurrency.value
  const qty = quantity.value
  
  if (fee === 0) {
    return t('freeShipping')
  }
  
  const currentRegionData = getCurrentRegionData.value
  if (currentRegionData && currentRegionData.shipping) {
    const shippingPrice = parseFloat(currentRegionData.shipping.shippingPrice) || 0 // 首件运费
    const additionalPrice = parseFloat(currentRegionData.shipping.additionalPrice) || 0 // 续件运费
    
    // 如果没有续件运费，只显示总运费
    if (additionalPrice === 0) {
      return `${currency} ${fee.toFixed(2)}`
    }
    
    // 如果数量为1，只显示首件运费
    if (qty === 1) {
      return `${currency} ${shippingPrice.toFixed(2)}`
    }
    
    // 如果有续件运费且数量大于1，显示详细计算公式
    return `${currency} ${shippingPrice.toFixed(2)} + ${currency} ${additionalPrice.toFixed(2)} × ${qty - 1} = ${currency} ${fee.toFixed(2)}`
  }
  
  return `${currency} ${fee.toFixed(2)}`
})

// 获取当前区域的发货地址
const currentShippingAddress = computed(() => {
  const currentRegionData = getCurrentRegionData.value
  if (currentRegionData && currentRegionData.shippingAddress) {
    return currentRegionData.shippingAddress
  }
  return null
})

// 获取当前区域的退货地址
const currentReturnAddress = computed(() => {
  const currentRegionData = getCurrentRegionData.value
  if (currentRegionData && currentRegionData.returnAddress) {
    return currentRegionData.returnAddress
  }
  return null
})

// 传递给 ProductDetailTabs 组件的 product 对象（包含当前区域的地址信息）
const productForTabs = computed(() => {
  if (!product.value) return null
  return {
    ...product.value,
    shippingAddress: currentShippingAddress.value,
    returnAddress: currentReturnAddress.value
  }
})

// 选择区域
function selectRegion(regionCode) {
  selectedRegion.value = regionCode
  
  // 注意：这里不再自动切换Tab，只是让dynamicBusinessTypeLabel计算属性自动更新即可
  // 用户需要手动点击Tab来切换内容
  
  // 切换区域时，重置数量为当前区域的最小起订量
  quantity.value = minOrderQuantity.value
  quantityError.value = '' // 清除错误提示
}

// 面包屑分类信息（根据语言动态显示）
const breadcrumbCategory1 = computed(() => {
  if (!product.value?.category1) return null
  const lang = currentLang.value
  return {
    ...product.value.category1,
    displayName: lang === 'en' ? (product.value.category1.nameEn || product.value.category1.name) : product.value.category1.name
  }
})

const breadcrumbCategory2 = computed(() => {
  if (!product.value?.category2) return null
  const lang = currentLang.value
  return {
    ...product.value.category2,
    displayName: lang === 'en' ? (product.value.category2.nameEn || product.value.category2.name) : product.value.category2.name
  }
})

const breadcrumbCategory3 = computed(() => {
  if (!product.value?.category3) return null
  const lang = currentLang.value
  return {
    ...product.value.category3,
    displayName: lang === 'en' ? (product.value.category3.nameEn || product.value.category3.name) : product.value.category3.name
  }
})

// 相关商品推荐
const relatedProducts = computed(() => {
  return product.value?.relatedProducts || []
})

// 动态业务类型标签文本：根据当前区域的业务类型显示
const dynamicBusinessTypeLabel = computed(() => {
  const currentRegionData = getCurrentRegionData.value
  if (currentRegionData && currentRegionData.price && currentRegionData.price.businessType) {
    const businessType = currentRegionData.price.businessType
    // 如果是wholesale（批发），显示"批发"；否则显示"一件代发"
    return businessType === 'wholesale' ? t('wholesaleBusiness') : t('dropshipping')
  }
  // 默认显示"一件代发"
  return t('dropshipping')
})


async function loadProduct(id) {
  if (!id) {
    product.value = undefined
    plinfo.value = undefined
    selectedImage.value = ''
    selectedRegion.value = ''
    return
  }

  try {
    // 调用真实后端API，带上语言参数
    const lang = localStorage.getItem('app.lang') || currentLang.value
    const response = await fetch(`/shop/api/item-detail/product/${id}`, {
      headers: {
        'Accept-Language': lang
      }
    })
    const result = await response.json()
    
    if (result.success && result.product) {
      product.value = result.product
      plinfo.value = result.plinfo || {}
      
      // 保存网站货币符号
      if (result.siteCurrency) {
        siteCurrency.value = result.siteCurrency
      }
      
      // 默认选中第一个发货区域
      if (result.product.shippingRegion && result.product.shippingRegion.length > 0) {
        selectedRegion.value = result.product.shippingRegion[0]
        
        // 设置数量为该区域的最小起订量
        const firstRegion = result.product.shippingRegion[0]
        const regionConfig = result.product.regionConfigs?.[firstRegion]
        if (regionConfig && regionConfig.minOrderQty) {
          quantity.value = parseInt(regionConfig.minOrderQty) || 1
        } else {
          quantity.value = 1
        }
      }
      
      selectedIndex.value = 0
      selectedImage.value = result.product.mainImage || (result.product.images && result.product.images[0] && result.product.images[0].url) || ''
    } else {
      // 根据语言显示错误消息
      const errorMsg = lang === 'en' ? (result.messageEn || result.message) : result.message
      console.error('Failed to load product:', errorMsg || 'Unknown error')
      
      // 显示错误提示
      ElMessage.error(errorMsg || (lang === 'en' ? 'Failed to load product' : '加载商品失败'))
      
      product.value = undefined
      plinfo.value = undefined
      selectedRegion.value = ''
    }
  } catch (error) {
    console.error('Error loading product:', error)
    
    // 显示网络错误提示
    const lang = localStorage.getItem('app.lang') || currentLang.value
    ElMessage.error(lang === 'en' ? 'Network error, please try again' : '网络错误，请重试')
    
    product.value = undefined
    plinfo.value = undefined
    selectedRegion.value = ''
  }
}

onMounted(() => {
  // 初始加载翻译
  loadTranslations().then(() => {
    // 翻译加载完成后设置标题
    updatePageTitle()
  })
  loadProduct(productId.value)
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})

watch(() => productId.value, (newId) => {
  loadProduct(newId)
})

// 监听最小起订量变化，确保当前数量不小于最小起订量
watch(minOrderQuantity, (newMinQty) => {
  if (quantity.value < newMinQty) {
    quantity.value = newMinQty
  }
  quantityError.value = '' // 清除错误提示
})

// 监听数量变化，清除错误提示并重新计算价格
watch(quantity, (newQty) => {
  if (newQty >= minOrderQuantity.value) {
    quantityError.value = ''
  }
  // ❗ 数量变化时，重新调用后端接口计算价格
  debouncedFetchPrice()
})

// 监听区域变化，重新计算价格
watch(selectedRegion, () => {
  // 区域变化时立即计算价格
  debouncedFetchPrice()
})

// 监听物流方式变化，重新计算价格
watch(selectedShippingMethod, () => {
  // 物流方式变化时立即计算价格
  debouncedFetchPrice()
})

// 防抖动的价格计算函数（避免频繁调用后端接口）
let fetchPriceTimeout = null
const debouncedFetchPrice = () => {
  if (fetchPriceTimeout) {
    clearTimeout(fetchPriceTimeout)
  }
  fetchPriceTimeout = setTimeout(() => {
    // 只有当商品、区域、数量都存在时才调用
    if (product.value && selectedRegion.value && quantity.value > 0) {
      fetchPriceBreakdown()
    }
  }, 500) // 500ms 防抖
}

function selectThumbnail(url, index) {
  selectedImage.value = url
  if (typeof index === 'number') selectedIndex.value = index
}

function handleThumbnailMouseMove(e) {
  const wrapper = e.currentTarget
  const scrollContainer = wrapper.querySelector('.thumbnail-scroll-container')
  if (!scrollContainer || !product.value?.images) return

  const items = wrapper.querySelectorAll('.thumbnail-item')
  const wrapperRect = wrapper.getBoundingClientRect()
  const scrollLeft = scrollContainer.style.transform
    ? parseInt(scrollContainer.style.transform.match(/\d+/)?.[0] || '0')
    : 0

  items.forEach((item, index) => {
    const itemRect = item.getBoundingClientRect()
    const itemLeft = itemRect.left - wrapperRect.left + scrollLeft
    const itemRight = itemLeft + itemRect.width

    const mouseX = e.clientX - wrapperRect.left
    if (mouseX >= itemLeft && mouseX <= itemRight) {
      if (product.value?.images && index >= 0 && index < product.value.images.length) {
        selectThumbnail(product.value.images[index].url, index)
      }
    }
  })
}

function scrollThumbnails(direction) {
  if (!product.value?.images) return

  const itemCount = product.value.images.length
  const itemsPerPage = 5
  const maxPages = Math.ceil(itemCount / itemsPerPage)
  let currentPage = Math.floor(thumbnailOffset.value / itemsPerPage)

  if (direction === 'prev') {
    currentPage = Math.max(0, currentPage - 1)
  } else {
    currentPage = Math.min(maxPages - 1, currentPage + 1)
  }

  thumbnailOffset.value = currentPage * itemsPerPage
}

function decreaseQty() {
  const minQty = minOrderQuantity.value
  if (quantity.value > minQty) {
    quantity.value -= 1
    quantityError.value = '' // 清除错误提示
  }
}

function increaseQty() {
  quantity.value += 1
  quantityError.value = '' // 清除错误提示
}

function handlePublish(platform) {
  if (!product.value) return
  console.log(`Publishing to ${platform}:`, product.value.sku)
  // 这里可以添加实际的发布逻辑
}

// 价格明细（从后端获取，用于弹窗显示）
const priceBreakdown = ref([])
const isLoadingPriceBreakdown = ref(false)
// 保存最新计算的价格（包括 totalPrice、displayPrice、subtotal 等）
const latestCalculatedPrice = ref(null)

// 从后端获取价格明细
const fetchPriceBreakdown = async () => {
  console.log('📊 === fetchPriceBreakdown 开始执行 ===')
  console.log('📊 商品信息:', product.value)
  console.log('📊 选中区域:', selectedRegion.value)
  console.log('📊 购买数量:', quantity.value)
  
  if (!product.value || !selectedRegion.value || !quantity.value) {
    console.log('❌ 参数不完整:', { 
      hasProduct: !!product.value, 
      hasRegion: !!selectedRegion.value, 
      hasQuantity: !!quantity.value 
    })
    priceBreakdown.value = []
    return false
  }
  
  // 获取当前区域的业务类型
  const currentRegionData = getCurrentRegionData.value
  console.log('📊 当前区域数据:', currentRegionData)
  const businessType = currentRegionData?.price?.businessType || 'dropship'
  console.log('📊 业务类型:', businessType)
  
  isLoadingPriceBreakdown.value = true
  
  try {
    // 准备请求数据
    const requestData = {
      productId: product.value.id,
      region: selectedRegion.value,
      quantity: quantity.value,
      businessType: businessType,
      shippingMethod: selectedShippingMethod.value  // 传递物流方式
    }
    console.log('📊 请求原始数据:', requestData)
    
    // 使用加密服务加密整个JSON对象
    const encryptedData = encryptionService.prepareData(requestData, true)
    console.log('📊 加密后数据:', encryptedData)
    
    // 生成API签名
    const signedData = apiSignature.sign(encryptedData)
    console.log('📊 签名后数据:', signedData)
    
    // 调用后端API
    console.log('📊 开始调用 /shop/api/item-detail/calculate-price')
    const response = await fetch('/shop/api/item-detail/calculate-price', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    console.log('📊 响应状态:', response.status, response.statusText)
    const result = await response.json()
    console.log('📊 后端返回原始结果:', result)
    
    if (result.success && result.data) {
      // 使用后端返回的价格明细，并处理数据格式
      const breakdown = result.data.breakdown || []
      
      // 详细调试：打印原始数据
      console.log('📦 后端返回原始数据:', {
        breakdown_raw: breakdown,
        breakdown_length: breakdown.length,
        totalPrice: result.data.totalPrice,
        displayPrice: result.data.displayPrice,
        subtotal: result.data.subtotal,
        currency: result.data.currency
      })
      
      // 处理 amount 字段：将字符串转为数字
      priceBreakdown.value = breakdown.map(item => {
        const processedItem = {
          ...item,
          amount: parseFloat(item.amount) || 0
        }
        console.log('🔄 处理明细项:', {
          原始: item,
          处理后: processedItem
        })
        return processedItem
      })
      
      // ❗ 保存最新计算的价格（非常重要！用于后续提交订单时验证价格）
      latestCalculatedPrice.value = {
        totalPrice: result.data.totalPrice,
        displayPrice: result.data.displayPrice,
        subtotal: result.data.subtotal,
        currency: result.data.currency
      }
      
      // 调试：打印价格明细数据
      console.log('✅ 价格明细获取成功:', {
        breakdown: priceBreakdown.value,
        breakdown_count: priceBreakdown.value.length,
        totalPrice: result.data.totalPrice,
        currency: result.data.currency,
        latestCalculatedPrice: latestCalculatedPrice.value
      })
      return true
    } else {
      priceBreakdown.value = []
      latestCalculatedPrice.value = null
      console.error('❌ 获取价格明细失败:', result.message)
      console.error('❌ 详细错误信息:', result)
      return false
    }
  } catch (error) {
    console.error('❌ 获取价格明细异常:', error)
    console.error('❌ 异常堆栈:', error.stack)
    priceBreakdown.value = []
    return false
  } finally {
    isLoadingPriceBreakdown.value = false
    console.log('📊 === fetchPriceBreakdown 执行结束 ===')
  }
}

const isCheckingAddress = ref(false) // 检查地址加载状态

async function openPaymentModal() {
  console.log('🔍 === openPaymentModal 开始执行 ===')
  console.log('🔍 当前商品信息:', product.value)
  console.log('🔍 选中区域:', selectedRegion.value)
  console.log('🔍 购买数量:', quantity.value)
  console.log('🔍 物流方式:', selectedShippingMethod.value)
  console.log('🔍 最小起订量:', minOrderQuantity.value)
  
  if (!product.value) {
    console.log('❌ product.value 为空')
    return
  }
  
  // 1. 优先检查用户是否已登录
  console.log('🔍 检查用户登录状态:', { isLoggedIn: isLoggedIn.value, user: user.value })
  if (!isLoggedIn.value || !user.value?.id) {
    console.log('❌ 用户未登录')
    const lang = currentLang.value
    const title = lang === 'en' ? 'Login Required' : '请先登录'
    const message = lang === 'en' 
      ? 'Please log in to continue your purchase' 
      : '请先登录后再进行购买'
    const confirmText = lang === 'en' ? 'Go to Login' : '去登录'
    const cancelText = lang === 'en' ? 'Cancel' : '取消'
    
    ElMessageBox.confirm(message, title, {
      confirmButtonText: confirmText,
      cancelButtonText: cancelText,
      type: 'warning',
      closeOnClickModal: false  // 遵循项目规范：禁止点击外部区域关闭弹窗
    }).then(() => {
      // 点击去登录，跳转到登录页，并携带当前页面作为回跳地址
      const currentPath = window.location.pathname + window.location.search
      window.location.href = `/login?redirect=${encodeURIComponent(currentPath)}`
    }).catch(() => {
      // 点击取消，不做任何操作
    })
    return
  }
  
  // 2. 验证数量是否达到最小起订量
  console.log('🔍 验证数量:', { quantity: quantity.value, minOrderQuantity: minOrderQuantity.value })
  if (quantity.value < minOrderQuantity.value) {
    console.log('❌ 数量未达到最小起订量')
    quantityError.value = `${t('minOrderQuantityIs')}${minOrderQuantity.value}`
    return
  }
  
  quantityError.value = ''
  
  // 3. 检查会员是否有收货地址
  console.log('🔍 开始检查收货地址...')
  isCheckingAddress.value = true
  
  try {
    // 使用新的 fetchWithSignature API（自动处理签名刷新）
    const response = await fetchWithSignature(
      '/shop/api/customer/address/check',
      {},  // 需要签名的参数（这里为空）
      { method: 'GET' }
    )
    
    const result = await response.json()
    console.log('🔍 地址检查结果:', result)
    
    if (!result.success) {
      console.log('❌ 地址检查失败')
      const lang = currentLang.value
      const errorMsg = (lang === 'en' ? result.messageEn : result.message) || (lang === 'en' ? 'Failed to check address' : '检查地址失败')
      ElMessage.error(errorMsg)
      isCheckingAddress.value = false
      return
    }
    
    // 如果没有地址，使用 ElMessage 提示
    if (!result.hasAddress) {
      console.log('❌ 用户没有收货地址')
      const lang = currentLang.value
      const message = lang === 'en' 
        ? 'You have not added a shipping address yet. Please add one before making a purchase.' 
        : '您还没有添加收货地址，请先添加后再进行购买。'
      
      isCheckingAddress.value = false
      ElMessage.warning(message)
      
      return
    }
    
    // 4. 有地址且登录，获取价格明细后打开支付方式选择弹窗
    console.log('✅ 地址检查通过')
    isCheckingAddress.value = false
    
    // ❗ 重要：点击立即购买时，强制取消防抖，立即重新计算最新价格
    // 即使用户刚刚修改了参数，也要确保使用最新的数量/区域/物流方式重新计算
    console.log('📊 点击立即购买，强制重新计算最新价格...')
    console.log('📊 当前参数:', {
      productId: product.value?.id,
      region: selectedRegion.value,
      quantity: quantity.value,
      shippingMethod: selectedShippingMethod.value
    })
    
    // 取消正在进行的防抖计时器（如果有）
    if (fetchPriceTimeout) {
      clearTimeout(fetchPriceTimeout)
      fetchPriceTimeout = null
      console.log('🚫 已取消防抖计时器，立即重新计算价格')
    }
    
    // 立即调用后端重新计算价格（不经过防抖）
    const priceSuccess = await fetchPriceBreakdown()
    console.log('📊 最新价格计算结果:', priceSuccess, priceBreakdown.value)
    console.log('📊 最新总价:', latestCalculatedPrice.value)
    
    // 只有价格计算成功才打开弹窗
    if (!priceSuccess) {
      console.log('❌ 价格计算失败，不打开支付弹窗')
      return
    }
    
    // 打开弹窗
    console.log('✅ 打开支付弹窗')
    isPaymentModalOpen.value = true
    
  } catch (error) {
    isCheckingAddress.value = false
    console.error('❌ 检查地址失败:', error)
    
    // 检查是否是认证错误（401未登录或签名失效）
    // 注意：fetchWithSignature 已经处理了 401 和 needRelogin 的情况
    // 如果这里捕获到错误，说明 Token 刷新已失败，需要重新登录
    const { handleAuthError } = await import('../utils/authErrorHandler.js')
    
    // 直接处理为认证错误（因为 fetchWithSignature 已经尝试过刷新）
    handleAuthError(store)
    return
  }
}

async function handlePaymentConfirm(data) {
  console.log('🔔 === handlePaymentConfirm 开始执行 ===')
  console.log('🔔 传入参数:', data)
  console.log('🔔 当前商品:', product.value)
  
  if (!product.value) {
    console.log('❌ product.value 为空')
    return
  }
  
  // 前端验证：检查用户是否已登录（双重保险，理论上 openPaymentModal 已经检查过）
  console.log('🔔 检查登录状态:', { isLoggedIn: isLoggedIn.value, userId: user.value?.id })
  if (!isLoggedIn.value || !user.value?.id) {
    console.log('❌ 用户未登录')
    const lang = currentLang.value
    const message = lang === 'en' 
      ? 'Please log in to continue your purchase' 
      : '请先登录后再进行购买'
    ElMessage.warning(message)
    const currentPath = window.location.pathname + window.location.search
    window.location.href = `/login?redirect=${encodeURIComponent(currentPath)}`
    return
  }
  
  // 获取地址ID
  const addressId = data.addressId
  console.log('🔔 地址ID:', addressId)
  
  // 关闭支付方式弹窗
  isPaymentModalOpen.value = false
  
  // 步骤1：生成订单号
  // 【订单号生成规则】格式：ORD+年月日+微秒时间戳后6位+随机2位十六进制
  // 示例：ORD20250122456789A3（总长度18位）
  // 说明：与后端Order实体的generateOrderNo方法保持一致
  const now = new Date()
  const dateStr = now.getFullYear().toString() + 
                  (now.getMonth() + 1).toString().padStart(2, '0') + 
                  now.getDate().toString().padStart(2, '0')
  const microPart = Date.now().toString().slice(-6)
  const randomPart = Math.random().toString(16).substr(2, 2).toUpperCase()
  const orderNo = `ORD${dateStr}${microPart}${randomPart}`
  console.log('🔔 生成订单号:', orderNo)
  
  // 获取当前区域的业务类型
  const currentRegionData = getCurrentRegionData.value
  console.log('🔔 当前区域数据:', currentRegionData)
  let businessType = 'dropship' // 默认一件代发
  
  if (currentRegionData && currentRegionData.price && currentRegionData.price.businessType) {
    businessType = currentRegionData.price.businessType
  }
  console.log('🔔 业务类型:', businessType)
  
  // ❗ 重要：使用刚刚获取的价格明细中的总价，而不是 totalPrice.value（可能是过期的缓存值）
  // latestCalculatedPrice 是在 fetchPriceBreakdown() 时保存的最新后端计算结果
  let calculatedTotalPrice = 0
  if (latestCalculatedPrice.value && latestCalculatedPrice.value.totalPrice) {
    calculatedTotalPrice = parseFloat(latestCalculatedPrice.value.totalPrice)
    console.log('📊 使用最新计算的总价:', calculatedTotalPrice)
  } else {
    // 如果没有最新价格，使用 totalPrice.value 作为后备
    calculatedTotalPrice = parseFloat(totalPrice.value.replace(/[^0-9.]/g, ''))
    console.warn('⚠️ 没有最新计算价格，使用 totalPrice.value:', calculatedTotalPrice)
  }
  
  // 步骤2：准备请求数据（不立即提交）
  // ❗ 重要：支付方式为空，等待订单生成成功后再填写
  const requestData = {
    orderNo: orderNo,
    productId: product.value.id,
    region: selectedRegion.value,
    quantity: quantity.value,
    paymentMethod: '', // 支付方式留空
    shippingMethod: selectedShippingMethod.value,
    customerId: user.value.id,
    businessType: businessType, // 添加业务类型字段
    totalPrice: calculatedTotalPrice, // 使用最新计算的总价
    addressId: addressId // 添加地址ID
  }
  console.log('🔔 准备的请求数据:', requestData)
  
  // 保存待提交的订单数据
  pendingOrderData.value = { orderNo, requestData }
  
  // 步骤3：显示订单状态监控弹窗（会立即建立 Mercure 连接）
  processingOrderNo.value = orderNo
  showOrderMonitor.value = true
  
  // 步骤4：等待 Mercure 连接就绪后，handleMercureReady 会被触发，然后才提交订单
  console.log('🔔 等待 Mercure 连接就绪...')
  console.log('🔔 === handlePaymentConfirm 执行结束 ===')
}

// 关闭订单监控弹窗
function handleOrderMonitorClose() {
  console.log('🚪 关闭订单监控弹窗')
  showOrderMonitor.value = false
  
  // 延迟清空所有状态，避免关闭动画时看到状态变化
  setTimeout(() => {
    console.log('🧹 清空订单相关状态')
    processingOrderNo.value = ''
    pendingOrderData.value = null
    // OrderStatusMonitor 组件会在自己的 close() 方法中调用 cleanupConnection()
  }, 300)
}

// Mercure 连接就绪后的回调
const pendingOrderData = ref(null)

async function handleMercureReady() {
  console.log('🔌 === handleMercureReady 开始执行 ===')
  console.log('🔌 Mercure 连接已就绪，开始提交订单')
  
  if (!pendingOrderData.value) {
    console.warn('❌ 没有待提交的订单数据')
    return
  }
  
  const { orderNo, requestData } = pendingOrderData.value
  console.log('🔌 订单号:', orderNo)
  console.log('🔌 原始请求数据:', requestData)
  
  try {
    // 使用加密服务加密整个JSON对象
    console.log('🔌 开始加密请求数据...')
    const encryptedData = encryptionService.prepareData(requestData, true)
    console.log('🔌 加密后数据:', encryptedData)
    
    // 生成API签名
    console.log('🔌 生成API签名...')
    const signedData = apiSignature.sign(encryptedData)
    console.log('🔌 签名后数据:', signedData)
    
    console.log('🔌 发送支付确认请求（加密+签名）:', signedData)
    
    // 调用后端 API
    console.log('🔌 开始调用 /shop/api/item-detail/confirm-payment')
    const response = await fetch('/shop/api/item-detail/confirm-payment', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    console.log('🔌 响应状态:', response.status, response.statusText)
    const result = await response.json()
    console.log('🔌 后端返回结果:', result)
    
    if (!result.success) {
      // 订单创建失败
      console.error('❌ 订单创建失败:', result)
      showOrderMonitor.value = false
      processingOrderNo.value = ''
      pendingOrderData.value = null
      
      const lang = currentLang.value
      const errorMsg = (lang === 'en' ? result.messageEn : result.message) || (lang === 'en' ? 'Purchase failed, please try again' : '购买失败，请重试')
      ElMessage.error(errorMsg)
      console.error('❌ 订单创建失败:', result)
    } else {
      console.log('✅ 订单创建成功，等待 Mercure 消息更新状态')
    }
    // 成功的话，等待 Mercure 消息更新状态
    
    // 清空待提交数据
    pendingOrderData.value = null
    console.log('🔌 === handleMercureReady 执行结束 ===')
    
  } catch (error) {
    console.error('❌ 支付请求异常:', error)
    console.error('❌ 异常堆栈:', error.stack)
    
    showOrderMonitor.value = false
    processingOrderNo.value = ''
    pendingOrderData.value = null
    
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'System error, please try again later' : '系统错误，请稍后重试')
  }
}

// 重新支付
function handleRetryPayment() {
  console.log('🔄 重新支付')
  showOrderMonitor.value = false
  
  // 立即清空订单号和待提交数据，为下次支付做准备
  setTimeout(() => {
    console.log('🧹 清空订单状态，准备重新支付')
    processingOrderNo.value = ''
    pendingOrderData.value = null
    // 重新打开支付方式选择弹窗
    isPaymentModalOpen.value = true
  }, 300)
}

// 查看订单详情
function handleViewOrder(orderNo) {
  console.log('📋 查看订单详情:', orderNo)
  showOrderMonitor.value = false
  
  // 清空状态后跳转
  setTimeout(() => {
    processingOrderNo.value = ''
    pendingOrderData.value = null
    // 跳转到订单详情页
    window.location.href = `/customer/orders/${orderNo}`
  }, 100)
}

// 继续购物
function handleContinueShopping() {
  console.log('🛍️ 继续购物')
  showOrderMonitor.value = false
  
  // 延迟清空订单号，为下次支付做准备
  setTimeout(() => {
    console.log('🧹 清空订单状态')
    processingOrderNo.value = ''
    pendingOrderData.value = null
  }, 300)
  // 可选：跳转到首页或商品列表页
  // window.location.href = '/'
}

// 支付成功后的处理
function handlePaymentSuccess() {
  console.log('🎉 支付成功')
  
  // 关闭监控弹窗
  showOrderMonitor.value = false
  
  // 清空状态
  setTimeout(() => {
    processingOrderNo.value = ''
    pendingOrderData.value = null
  }, 300)
  
  // 注意：不在这里显示成功提示，因为 OrderStatusMonitor 组件已经显示了
}

// 加入购物车
async function handleAddToCart() {
  if (!product.value) return
  
  // 验证数量是否达到最小起订量
  if (quantity.value < minOrderQuantity.value) {
    quantityError.value = `${t('minOrderQuantityIs')}${minOrderQuantity.value}`
    return
  }
  
  // 验证用户是否登录
  if (!isLoggedIn.value || !user.value?.id) {
    const lang = currentLang.value
    const message = lang === 'en' ? 'Please log in first' : '请先登录'
    ElMessage.warning(message)
    const currentPath = window.location.pathname + window.location.search
    window.location.href = `/login?redirect=${encodeURIComponent(currentPath)}`
    return
  }
  
  // 验证是否选择了发货区域
  if (!selectedRegion.value) {
    const lang = currentLang.value
    const message = lang === 'en' ? 'Please select a shipping region' : '请选择发货区域'
    ElMessage.warning(message)
    return
  }
  
  // 防止重复提交
  if (isAddingToCart.value) return
  
  quantityError.value = ''
  isAddingToCart.value = true
  
  try {
    // 获取当前区域的业务类型（从后端返回的 regionData.price.businessType 获取）
    const currentRegionData = getCurrentRegionData.value
    let businessType = 'dropship' // 默认一件代发
    
    if (currentRegionData && currentRegionData.price && currentRegionData.price.businessType) {
      businessType = currentRegionData.price.businessType
    }
    
    const requestData = {
      productId: product.value.id,
      sku: product.value.sku,
      region: selectedRegion.value,
      quantity: quantity.value,
      businessType: businessType, // 使用当前区域的业务类型
      sellingPrice: displayPrice.value.toString(),
      originalPrice: originalPrice.value > 0 ? originalPrice.value.toString() : null,
      currency: currentCurrency.value,
      availableStock: currentStock.value
    }
    
    // 使用加密服务加密整个JSON对象（和立即购买保持一致）
    const encryptedData = encryptionService.prepareData(requestData, true)
    
    // 生成API签名
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/cart/add', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const result = await response.json()
    
    if (result.success) {
      const lang = currentLang.value
      const message = lang === 'en' ? 'Added to cart successfully' : '已添加到购物车'
      ElMessage.success(message)
    } else {
      const lang = currentLang.value
      const errorMsg = (lang === 'en' ? result.messageEn : result.message) || (lang === 'en' ? 'Failed to add to cart' : '加入购物车失败')
      ElMessage.error(errorMsg)
      console.error('加入购物车失败:', result)
    }
  } catch (error) {
    console.error('加入购物车请求失败:', error)
    
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'System error, please try again later' : '系统错误，请稍后重试')
  } finally {
    isAddingToCart.value = false
  }
}

// 下载商品数据
async function handleDownloadProduct() {
  if (isDownloading.value) return
  
  // 验证用户是否登录
  if (!isLoggedIn.value || !user.value?.id) {
    const lang = currentLang.value
    const title = lang === 'en' ? 'Login Required' : '请先登录'
    const message = lang === 'en' 
      ? 'Please log in to download product data' 
      : '请先登录后再下载商品数据'
    const confirmText = lang === 'en' ? 'Go to Login' : '去登录'
    const cancelText = lang === 'en' ? 'Cancel' : '取消'
    
    ElMessageBox.confirm(message, title, {
      confirmButtonText: confirmText,
      cancelButtonText: cancelText,
      type: 'warning',
      closeOnClickModal: false
    }).then(() => {
      const currentPath = window.location.pathname + window.location.search
      window.location.href = `/login?redirect=${encodeURIComponent(currentPath)}`
    }).catch(() => {})
    return
  }
  
  if (!product.value?.id) {
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'Product information not loaded' : '商品信息未加载')
    return
  }
  
  isDownloading.value = true
  
  try {
    const requestData = {
      productId: product.value.id
    }
    
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/download-center/download', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const result = await response.json()
    
    if (result.success) {
      const lang = currentLang.value
      const message = lang === 'en' 
        ? 'Download task created successfully! Please check the download center later to get the file.' 
        : '下载任务已创建成功！请稍后到下载中心查看生成的文件。'
      
      ElMessage.success({
        message: message,
        duration: 5000,
        showClose: true
      })
    } else {
      const lang = currentLang.value
      const errorMsg = (lang === 'en' ? result.messageEn : result.message) 
        || (lang === 'en' ? 'Download failed' : '下载失败')
      ElMessage.error(errorMsg)
    }
  } catch (error) {
    console.error('下载请求失败:', error)
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'System error, please try again later' : '系统错误，请稍后重试')
  } finally {
    isDownloading.value = false
  }
}

// 提交工厂直采询价表单
async function handleSubmitInquiry() {
  // 验证用户是否登录
  if (!isLoggedIn.value || !user.value?.id) {
    const lang = currentLang.value
    const title = lang === 'en' ? 'Login Required' : '请先登录'
    const message = lang === 'en' 
      ? 'Please log in to submit inquiry' 
      : '请先登录后再提交询价'
    const confirmText = lang === 'en' ? 'Go to Login' : '去登录'
    const cancelText = lang === 'en' ? 'Cancel' : '取消'
    
    ElMessageBox.confirm(message, title, {
      confirmButtonText: confirmText,
      cancelButtonText: cancelText,
      type: 'warning',
      closeOnClickModal: false
    }).then(() => {
      const currentPath = window.location.pathname + window.location.search
      window.location.href = `/login?redirect=${encodeURIComponent(currentPath)}`
    }).catch(() => {})
    return
  }
  
  // 验证表单
  if (!inquiryForm.value.contactName || !inquiryForm.value.contactPhone || !inquiryForm.value.inquiryQuantity || !inquiryForm.value.requirementDescription) {
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'Please fill in all required fields' : '请填写所有必填项')
    return
  }
  
  // 验证手机号
  const phonePattern = /^1[3-9]\d{9}$/
  const intlPhonePattern = /^\+?[\d\s-]{8,20}$/
  if (!phonePattern.test(inquiryForm.value.contactPhone) && !intlPhonePattern.test(inquiryForm.value.contactPhone)) {
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'Please enter a valid phone number' : '请输入正确的手机号')
    return
  }
  
  // 验证数量
  if (inquiryForm.value.inquiryQuantity < 1) {
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'Quantity must be greater than 0' : '数量必须大于0')
    return
  }
  
  if (!product.value?.id) {
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'Product information not loaded' : '商品信息未加载')
    return
  }
  
  isSubmittingInquiry.value = true
  
  try {
    const requestData = {
      productId: product.value.id,
      contactName: inquiryForm.value.contactName.trim(),
      contactPhone: inquiryForm.value.contactPhone.trim(),
      inquiryQuantity: inquiryForm.value.inquiryQuantity,
      requirementDescription: inquiryForm.value.requirementDescription.trim(),
      attachments: inquiryForm.value.attachments
    }
    
    const signedData = apiSignature.sign(requestData)
    
    const response = await fetch('/shop/api/inquiry/submit', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const result = await response.json()
    
    if (result.success) {
      const lang = currentLang.value
      const message = lang === 'en' ? result.messageEn : result.message
      
      ElMessage.success({
        message: message,
        duration: 5000,
        showClose: true
      })
      
      // 清空表单
      inquiryForm.value = {
        contactName: '',
        contactPhone: '',
        inquiryQuantity: 1,
        requirementDescription: '',
        attachments: []
      }
      
      // 强制重置附件上传组件，清除所有附件显示
      fileUploadKey.value++
    } else {
      const lang = currentLang.value
      const errorMsg = (lang === 'en' ? result.messageEn : result.message) 
        || (lang === 'en' ? 'Submission failed' : '提交失败')
      ElMessage.error(errorMsg)
    }
  } catch (error) {
    console.error('提交询价单失败:', error)
    const lang = currentLang.value
    ElMessage.error(lang === 'en' ? 'System error, please try again later' : '系统错误，请稍后重试')
  } finally {
    isSubmittingInquiry.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex flex-col page">
    <SiteHeader />

    <main class="flex-1">
      <!-- 面包屑导航 -->
      <div class="breadcrumb-wrapper">
        <div class="breadcrumb-container">
          <a href="/" class="breadcrumb-link">{{ t('home') }}</a>
          <template v-if="breadcrumbCategory1">
            <ChevronRight :size="16" class="breadcrumb-arrow" />
            <a :href="`/all-categories-products?categoryId=${breadcrumbCategory1.id}`" class="breadcrumb-link">
              {{ breadcrumbCategory1.displayName }}
            </a>
          </template>
          <template v-if="breadcrumbCategory2">
            <ChevronRight :size="16" class="breadcrumb-arrow" />
            <a :href="`/all-categories-products?subcategoryId=${breadcrumbCategory2.id}`" class="breadcrumb-link">
              {{ breadcrumbCategory2.displayName }}
            </a>
          </template>
          <template v-if="breadcrumbCategory3">
            <ChevronRight :size="16" class="breadcrumb-arrow" />
            <a :href="`/all-categories-products?itemId=${breadcrumbCategory3.id}`" class="breadcrumb-link">
              {{ breadcrumbCategory3.displayName }}
            </a>
          </template>
        </div>
      </div>

      <div class="content-container">
        <!-- 左侧：商品图片区域 -->
        <div class="product-section">
          <div class="image-gallery-wrapper">
            <!-- 主图 -->
            <div class="main-image-container">
              <img v-if="mainImageUrl" :src="mainImageUrl" width="500" height="500" :alt="displayTitle" loading="lazy" class="main-image" />
              <div v-else class="no-image-placeholder">{{ t('noImageAvailable') }}</div>
              <div class="image-overlay"></div>
            </div>

            <!-- 缩略图导航 -->
            <div class="thumbnail-navigation">
              <div class="thumbnails-wrapper">
                <ul class="thumbnail-scroll-container">
                  <li v-for="(img, idx) in (product?.images || [])" :key="img.id || idx" class="thumbnail-item" @click="selectThumbnail(img.url, idx)">
                    <div class="thumbnail-image-wrapper" :class="{ active: (selectedImage ? selectedImage === img.url : selectedIndex === idx) }">
                      <img :alt="img.alt || displayTitle" loading="lazy" :src="img.url" class="thumbnail-image" />
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <!-- 大图预览 -->
            <div class="large-preview">
              <img v-if="mainImageUrl" :src="mainImageUrl" width="1000" height="1000" :alt="t('productLargeImage')" loading="lazy" class="large-preview-image" />
            </div>
          </div>
        </div>

        <!-- 右侧：商品信息区域 -->
        <div class="product-details-section">
          <!-- 广告横幅 -->
          <div class="warning-banner">
            <img loading="lazy" src="/images/icons/prohibition.svg" class="warning-icon" alt="禁止" />
            <p class="warning-text"></p>
          </div>

          <div class="product-info-wrapper">
            <!-- 商品标题信息 -->
            <div class="product-header">
              <h1 :title="displayTitle" class="product-title">
                <span class="title-text">{{ displayTitle }}</span>
              </h1>
              <p class="sku-info">SKU：{{ product?.sku || '' }}</p>
              <span class="spu-info">SPU：{{ product?.spu || '' }}</span>
              <span class="publish-date">{{ t('publishDate') }}{{ product?.publishDate || '' }}</span>
            </div>

            <!-- 商品标签/Tab菜单 -->
            <div class="product-tags">
              <ul class="tags-list">
                <li class="tag-dropship" :class="{ active: activeTab === 'dropship' }" @click="activeTab = 'dropship'">
                  {{ dynamicBusinessTypeLabel }}
                </li>
                <li class="tag-direct" :class="{ active: activeTab === 'factory' }" @click="activeTab = 'factory'">
                  {{ t('factoryDirect') }}
                </li>
              </ul>
            </div>

            <!-- 一件代发Tab内容 -->
            <div v-show="activeTab === 'dropship'" class="tab-content dropship-content">
              <!-- 价格与会员折扣区域 -->
              <div class="product-pricing-section">
                <div class="price-display">
                  <p class="price-amount">
                    <b class="price-value">{{ currentCurrency }} {{ basePrice.toFixed(2) }}</b>
                    <span v-if="originalPrice > 0 && originalPrice > basePrice" class="original-price">{{ currentCurrency }} {{ originalPrice.toFixed(2) }}</span>
                  </p>
                </div>

                <div class="member-discount-box">
                  <p class="member-level">
                    <span class="level-name">
                      <template v-if="isLoggedIn">
                        {{ userVipLevelName }}
                      </template>
                      <template v-else>
                        <span>{{ t('memberDiscount') }}，</span>
                        <a href="/login" class="login-link">{{ t('login') }}</a>
                        <span>{{ t('afterViewDiscount') }}</span>
                      </template>
                    </span>
                    <template v-if="isLoggedIn && memberPrice > 0 && memberPrice < basePrice">
                      <span class="vip-price">{{ currentCurrency }} {{ memberPrice.toFixed(2) }}</span>
                      <span v-if="discountPercentText" class="discount-percent">{{ discountPercentText }}</span>
                    </template>
                    <template v-else-if="isLoggedIn">
                      <span class="no-discount-text">{{ t('noDiscountForLevel') }}</span>
                    </template>
                  </p>
                  <a target="_blank" href="/membership" class="member-link">{{ t('learnMoreMembership') }}</a>
                </div>
              </div>

              <!-- 批发说明区域 -->
              <div class="wholesale-section">
                <div class="wholesale-content">
                  <h5 class="section-title">{{ t('wholesaleTitle') }}</h5>
                  <p class="section-description">
                    {{ t('wholesaleDescription') }}
                  </p>
                  <h5 class="section-title">{{ t('wholesaleProcessTitle') }}</h5>
                  <img loading="lazy" src="https://www.saleyee.com/ContentNew/Images/2023/202305/wholesale-guidance.png" class="workflow-image" :alt="t('wholesaleProcessTitle')" />
                  <div class="button-group">
                    <button type="button" class="btn-primary">{{ t('tryNow') }}</button>
                    <a target="_blank" href="https://www.saleyee.com/guide/hp746811.html" class="help-link">{{ t('viewMoreHelp') }} &gt;</a>
                  </div>
                </div>
              </div>

              <!-- 商品详情列表 -->
              <div class="product-details-list">
                <ul class="details-list-primary">
                  <li class="list-item">
                    <span class="item-label">{{ t('couponLabel') }}</span>
                    <div class="item-content">
                      <span class="coupon-badge">{{ couponText }}</span>
                    </div>
                  </li>

                  <li class="list-item">
                    <span class="item-label">{{ t('warehouseTypeLabel') }}</span>
                    <div class="item-content">{{ t('warehouseTypeSY') }}</div>
                  </li>

                  <li class="list-item">
                    <span class="item-label">{{ t('serviceLabel') }}</span>
                    <div class="item-services">
                      <span :class="['service-badge', product?.supportDropship ? 'supported' : 'unsupported']" :title="product?.supportDropship ? t('supportedService') + t('dropship') : t('unsupportedService') + t('dropship')">
                        <Check v-if="product?.supportDropship" class="service-icon" :size="16" :stroke-width="2" />
                        <X v-else class="service-icon" :size="16" :stroke-width="2" />
                        {{ t('dropship') }}
                      </span>
                      <span :class="['service-badge', product?.supportWholesale ? 'supported' : 'unsupported']" :title="product?.supportWholesale ? t('supportedService') + t('wholesale') : t('unsupportedService') + t('wholesale')">
                        <Check v-if="product?.supportWholesale" class="service-icon" :size="16" :stroke-width="2" />
                        <X v-else class="service-icon" :size="16" :stroke-width="2" />
                        {{ t('wholesale') }}
                      </span>
                      <span :class="['service-badge', product?.supportCircle_buy ? 'supported' : 'unsupported']" :title="product?.supportCircle_buy ? t('supportedService') + t('circleBuy') : t('unsupportedService') + t('circleBuy')">
                        <Check v-if="product?.supportCircle_buy" class="service-icon" :size="16" :stroke-width="2" />
                        <X v-else class="service-icon" :size="16" :stroke-width="2" />
                        {{ t('circleBuy') }}
                      </span>
                      <span :class="['service-badge', product?.supportSelf_pickup ? 'supported' : 'unsupported']" :title="product?.supportSelf_pickup ? t('supportedService') + t('selfPickup') : t('unsupportedService') + t('selfPickup')">
                        <Check v-if="product?.supportSelf_pickup" class="service-icon" :size="16" :stroke-width="2" />
                        <X v-else class="service-icon" :size="16" :stroke-width="2" />
                        {{ t('selfPickup') }}
                      </span>
                    </div>
                  </li>

                  <li class="list-item">
                    <span class="item-label">{{ t('shippingRegionLabel') }}</span>
                    <div class="item-content">
                      <div class="region-tags">
                        <em 
                          v-for="region in shippingRegions" 
                          :key="region.code" 
                          class="region-code"
                          :class="{ 'region-selected': selectedRegion === region.code }"
                          @click="selectRegion(region.code)"
                        >
                          {{ region.code }}
                        </em>
                      </div>
                    </div>
                  </li>
                </ul>

                <!-- 发货信息区域 -->
                <div class="details-list-secondary-wrapper">
                  <ul class="details-list-secondary">
                    <li class="list-item">
                      <span class="item-label">{{ t('shippingMethodLabel') }}</span>
                      <div class="item-content">
                        <div class="logistics-select">
                          <select class="select-dropdown" v-model="selectedShippingMethod">
                            <option value="STANDARD_SHIPPING">{{ t('standardShipping') }}</option>
                            <option value="SELF_PICKUP">{{ t('selfPickupOption') }}</option>
                          </select>
                          <span class="shipping-time">{{ t('estimatedTime') }}，{{ formattedShippingFee }}</span>
                        </div>
                      </div>
                    </li>

                    <li class="list-item">
                      <span class="item-label">{{ t('quantityLabel') }}</span>
                      <div class="item-content">
                        <div class="quantity-control" role="group" :aria-label="t('quantityLabel')">
                          <em class="quantity-btn" @click="decreaseQty" :aria-label="t('decreaseQuantity')">-</em>
                          <input
                            type="number"
                            class="quantity-input"
                            v-model.number="quantity"
                            :min="minOrderQuantity"
                            :aria-label="t('quantityLabel')"
                          />
                          <em class="quantity-btn" @click="increaseQty" :aria-label="t('increaseQuantity')">+</em>
                        </div>
                        <i class="stock-info">{{ t('stockLabel') }}<em class="stock-value">{{ currentStock }}</em></i>
                        <i v-if="quantityError" class="error-info">{{ quantityError }}</i>
                      </div>
                    </li>

                    <li class="list-item hidden">
                      <span class="item-label">{{ t('expectedPrice') }}<div class="price-help"><img loading="lazy" src="/images/icons/insurance.svg" class="help-icon" :alt="t('helpIconAlt')" /><div class="help-tooltip">{{ t('expectedPriceHelp') }}</div></div>：</span>
                      <div class="price-input-wrapper">
                        <div class="currency-input">
                          <span class="currency">USD</span>
                          <input type="text" :placeholder="t('expectedPriceOptional')" class="price-input" />
                        </div>
                        <i class="price-note">{{ t('expectedPriceNote') }}<em class="price-value">0</em></i>
                      </div>
                    </li>

                    <li class="list-item hidden">
                      <span class="item-label">{{ t('expectedQuantity') }}</span>
                      <div class="item-content">
                        <div class="quantity-control">
                          <em class="quantity-btn">--</em>
                          <input type="text" :placeholder="t('expectedQuantityRequired')" value="0" class="quantity-input" />
                          <em class="quantity-btn">+</em>
                        </div>
                        <i class="stock-info">{{ t('availableStock') }}<em class="stock-value">0</em></i>
                        <i class="stock-info">{{ t('suggestedMinOrder') }}<em class="stock-value">0</em></i>
                        <i class="stock-info">{{ t('estimatedPallets') }}<em class="stock-value">0</em></i>
                      </div>
                    </li>
                  </ul>
                </div>

                <!-- 操作按钮组 -->
                <div class="action-buttons-wrapper">
                  <div class="button-group-primary">
                    <button 
                      type="button" 
                      class="btn-orange" 
                      @click="openPaymentModal"
                      :disabled="isCheckingAddress"
                      :class="{ 'btn-loading': isCheckingAddress }"
                    >
                      <svg v-if="isCheckingAddress" class="loading-spinner" viewBox="0 0 24 24">
                        <circle class="loading-circle" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="3"/>
                      </svg>
                      <span>{{ isCheckingAddress ? (currentLang === 'en' ? 'Checking...' : '检查中...') : t('buyNow') }}</span>
                    </button>
                    <button 
                      type="button" 
                      class="btn-secondary" 
                      @click="handleAddToCart"
                      :disabled="isAddingToCart"
                      :class="{ 'btn-loading': isAddingToCart }"
                    >
                      <svg v-if="isAddingToCart" class="loading-spinner" viewBox="0 0 24 24">
                        <circle class="loading-circle" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="3"/>
                      </svg>
                      <span>{{ isAddingToCart ? t('adding') : t('addToCart') }}</span>
                    </button>
                    <OneClickPublishButton @open="isPublishModalOpen = true" />
                    <button type="button" class="btn-favorite" :title="t('addToFavorites')">
                      <img :title="t('addToFavorites')" loading="lazy" src="/frondend/images/ItemDetailPage/favorites_icon.png" class="btn-favorite-icon" :alt="t('addToFavorites')" />
                    </button>
                  </div>

                  <div class="button-group-secondary">
                    <button type="button" class="btn-link-text btn-download" @click="handleDownloadProduct" :disabled="isDownloading">
                      <svg class="btn-download-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                      </svg>
                      {{ isDownloading ? t('downloading') : t('downloadData') }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- 工厂直采Tab内容 -->
            <div v-show="activeTab === 'factory'" class="tab-content factory-content">
              <div class="inquiry-form-container">
                <div class="form-header">
                  <h3 class="form-title">{{ t('factoryInquiryTitle') }}</h3>
                  <p class="form-subtitle">{{ t('factoryInquirySubtitle') }}</p>
                </div>
                
                <div class="form-body">
                  <!-- 联系人姓名、联系电话、询价数量（同一行） -->
                  <div class="form-field-row">
                    <div class="form-field form-field-third">
                      <label class="field-label">
                        <span class="required-star">*</span>
                        {{ t('contactName') }}
                      </label>
                      <input 
                        v-model="inquiryForm.contactName" 
                        type="text" 
                        :placeholder="t('contactNamePlaceholder')" 
                        class="field-input"
                      />
                    </div>
                    
                    <div class="form-field form-field-third">
                      <label class="field-label">
                        <span class="required-star">*</span>
                        {{ t('contactPhone') }}
                      </label>
                      <input 
                        v-model="inquiryForm.contactPhone" 
                        type="text" 
                        :placeholder="t('contactPhonePlaceholder')" 
                        class="field-input"
                      />
                    </div>
                    
                    <div class="form-field form-field-third">
                      <label class="field-label">
                        <span class="required-star">*</span>
                        {{ t('inquiryQuantity') }}
                      </label>
                      <input 
                        v-model.number="inquiryForm.inquiryQuantity" 
                        type="number" 
                        :min="1"
                        :placeholder="t('inquiryQuantityPlaceholder')" 
                        class="field-input"
                      />
                    </div>
                  </div>
                  
                  <!-- 需求描述 -->
                  <div class="form-field">
                    <label class="field-label">
                      <span class="required-star">*</span>
                      {{ t('requirementDescription') }}
                    </label>
                    <textarea 
                      v-model="inquiryForm.requirementDescription" 
                      rows="4"
                      :placeholder="t('requirementDescriptionPlaceholder')" 
                      class="field-textarea"
                    ></textarea>
                  </div>
                  
                  <!-- 附件上传 -->
                  <div class="form-field">
                    <label class="field-label">
                      {{ t('uploadAttachment') }}
                    </label>
                    <InquiryFileUpload 
                      :key="fileUploadKey"
                      v-model="inquiryForm.attachments"
                      :max-files="10"
                      :translations="{
                        clickOrDragToUpload: t('clickOrDragToUpload'),
                        uploadHint: t('uploadAttachmentHint'),
                        filesUploaded: t('filesUploaded'),
                        imagePreview: t('imagePreview')
                      }"
                    />
                  </div>
                  
                  <!-- 提交按钮 -->
                  <div class="form-actions">
                    <button 
                      type="button" 
                      class="submit-inquiry-btn"
                      :disabled="isSubmittingInquiry"
                      @click="handleSubmitInquiry"
                    >
                      {{ isSubmittingInquiry ? t('submitting') : t('submitInquiry') }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <RelatedProducts :products="relatedProducts" />
      <ProductDetailTabs :product="productForTabs" :plinfo="plinfo" />
    </main>

    <SiteFooter />
    <OneClickPublishModal
      :isOpen="isPublishModalOpen"
      :productId="product?.sku"
      @close="isPublishModalOpen = false"
      @publish="handlePublish"
    />
    <PaymentMethodModal
      :isOpen="isPaymentModalOpen"
      :productTitle="product?.title || ''"
      :productTitleEn="product?.titleEn || ''"
      :productImage="mainImageUrl"
      :quantity="quantity"
      :totalPrice="totalPrice"
      :priceBreakdown="priceBreakdown"
      :siteCurrency="siteCurrency"
      @close="isPaymentModalOpen = false"
      @confirm="handlePaymentConfirm"
    />
    
    <!-- 订单状态监控弹窗 -->
    <OrderStatusMonitor
      :is-visible="showOrderMonitor"
      :order-no="processingOrderNo"
      @close="handleOrderMonitorClose"
      @ready="handleMercureReady"
      @payment-success="handlePaymentSuccess"
    />
  </div>
</template>

<style scoped>
.page {
  background-color: #f2f3f7;
}

.breadcrumb-wrapper {
  max-width: 1500px;
  min-width: 1200px;
  width: 80%;
  margin: 0 auto;
  background-color: #f2f3f7;
  padding: 10px 0;
}

.breadcrumb-container {
  display: flex;
  align-items: center;
  line-height: 30px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.breadcrumb-container > *:not(:last-child) {
  margin-right: 5px;
}


.breadcrumb-link {
  color: #999;
  text-decoration: none;
  padding: 0 5px;
  transition: color 0.3s;
  font-size: 14px;
}

.breadcrumb-link:hover {
  color: #ff6600;
}

.breadcrumb-arrow {
  color: #999;
  flex-shrink: 0;
}

.important-notice-wrapper {
  max-width: 1500px;
  min-width: 1200px;
  width: 80%;
  margin: 0 auto;
}

.content-container {
  max-width: 1500px;
  min-width: 1200px;
  width: 80%;
  margin: 0 auto;
  background-color: #ffffff;
  display: flex;
  overflow: hidden;
}

/* 左侧商品图片区域 */
.product-section {
  flex: 0 0 500px;
  background-color: #ffffff;
}

.image-gallery-wrapper {
  position: relative;
  width: 500px;
}

.main-image-container {
  border: 1px solid #f1f1f1;
  height: 500px;
  overflow: hidden;
  position: relative;
  width: 500px;
}

.main-image {
  height: 500px;
  left: 50%;
  margin-left: -250px;
  position: absolute;
  vertical-align: middle;
  width: 500px;
  object-fit: cover;
}

.no-image-placeholder {
  height: 100%;
  position: relative;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #f5f5f5;
  color: #999;
}

.image-overlay {
  background-color: rgba(255, 255, 255, 0.5);
  display: none;
  height: 250px;
  position: absolute;
  width: 250px;
}

.thumbnail-navigation {
  overflow: visible;
  padding: 0;
  position: relative;
  margin-top: 15px;
  display: flex;
  align-items: center;
}


.nav-arrows {
  display: none;
}

.arrow-left {
  display: none;
}

.arrow-right {
  display: none;
}

.thumbnails-wrapper {
  overflow: visible;
  position: relative;
  padding: 0;
  flex: 1;
}

.thumbnail-scroll-container {
  display: grid;
  grid-template-columns: repeat(5, 88px);
  position: relative;
  transform: none !important;
  width: fit-content;
  margin: 0 auto;
  list-style: none;
  padding: 0;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.thumbnail-scroll-container > *:not(:last-child) {
  margin-right: 12px;
}


.thumbnail-item {
  cursor: pointer;
  width: 88px;
  height: 88px;
  list-style: none;
  margin: 0;
  padding: 0;
}

.thumbnail-image-wrapper {
  display: flex;
  width: 100%;
  height: 100%;
  align-items: center;
  justify-content: center;
  margin: 0;
  padding: 1px;
  text-align: center;
  border: 1px solid #ddd;
  transition: border-color 0.2s;
  box-sizing: border-box;
}

.thumbnail-image-wrapper:hover {
  border-color: #ff6600;
}

.thumbnail-image-wrapper.active {
  border-color: #ff6600;
}

.thumbnail-image {
  cursor: pointer;
  max-height: 100%;
  max-width: 100%;
  object-fit: cover;
}

.large-preview {
  background-color: #ffffff;
  border: 1px solid #f1f1f1;
  display: none;
  height: 500px;
  position: absolute;
  right: -100.5%;
  top: 0;
  width: 500px;
  z-index: 9999;
  overflow: hidden;
}

.large-preview-image {
  height: 1000px;
  width: 1000px;
  object-fit: cover;
}

/* 右侧商品详情区域 */
.product-details-section {
  padding: 0 20px 20px;
  vertical-align: top;
  width: calc(100% - 500px);
}

.warning-banner {
  background-color: #fff7f6;
  display: none;
  margin-top: 20px;
  padding: 13px;
  border-radius: 4px;
}

.warning-icon {
  display: inline-block;
  height: 19px;
  margin-right: 11px;
  vertical-align: middle;
}

.warning-text {
  color: #cb261c;
  line-height: 20px;
  margin: 0;
}

.product-info-wrapper {
  width: 100%;
}

/* 商品标题信息 */
.product-header {
  width: 100%;
}

.product-title {
  color: #000;
  font-size: 16px;
  font-weight: 700;
  line-height: 24px;
  padding: 20px 0 5px 0;
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.title-text {
  display: inline;
  font-weight: 700;
}

.product-subtitle {
  color: #999;
  line-height: 24px;
  margin: 0 0 10px 0;
  font-size: 14px;
}

.sku-info {
  color: #cb261c;
  display: inline-block;
  font-weight: 700;
  margin-bottom: 10px;
  margin-right: 20px;
  font-size: 13px;
}

.spu-info {
  display: inline;
  margin-right: 20px;
  font-size: 13px;
  color: #666;
}

.publish-date {
  display: inline;
  font-size: 13px;
  color: #666;
}

/* 商品标签/Tab菜单 */
.product-tags {
  width: 100%;
  margin-top: 10px;
}

.tags-list {
  align-items: center;
  background-color: #f5f5f5;
  display: flex;
  height: 52px;
  margin: 10px 0;
  padding: 0;
  list-style: none;
  justify-content: flex-start;
}


.tag-dropship,
.tag-direct {
  cursor: pointer;
  font-size: 16px;
  font-weight: 700;
  line-height: 44px;
  min-width: 118px;
  padding: 0 27px;
  text-align: center;
  margin: 0;
  list-style: none;
  border-top: 4px solid #f5f5f5;
  color: #333;
  transition: all 0.3s;
}

.tag-dropship {
  background-color: #fff;
}

.tag-direct {
  background-color: transparent;
}

.tag-dropship.active,
.tag-direct.active {
  background-color: #fff;
  border-top-color: #cb261c;
  color: #cb261c;
}

/* Tab内容 */
.tab-content {
  animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

/* 工厂直采表单样式 */
.factory-form {
  margin-top: 20px;
}

.form-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.form-item {
  display: flex;
  margin-bottom: 16px;
}

.form-label {
  line-height: 38px;
  width: 180px;
  margin: 0;
  font-weight: 500;
  color: #333;
}

.required-mark {
  color: #ff0000;
  margin-right: 4px;
}

.form-control {
  flex: 1;
  max-width: calc(100% - 180px);
}

.form-input,
.form-textarea {
  width: 100%;
  border: 1px solid #e6e6e6;
  border-radius: 2px;
  padding: 6px 10px;
  font-size: 14px;
  font-family: inherit;
  transition: border-color 0.3s;
}

.form-input {
  height: 38px;
  line-height: 26px;
}

.form-textarea {
  min-height: 100px;
  resize: vertical;
  line-height: 20px;
}

.form-input:focus,
.form-textarea:focus {
  outline: none;
  border-color: #cb261c;
}

.upload-btn {
  background-color: #fff;
  border: 1px solid #c9c9c9;
  border-radius: 2px;
  color: #555;
  cursor: pointer;
  display: inline-block;
  height: 38px;
  line-height: 38px;
  padding: 0 18px;
  text-align: center;
  transition: all 0.3s;
  font-size: 14px;
}

.upload-btn:hover {
  border-color: #999;
  background-color: #f5f5f5;
}

.file-input {
  display: none !important;
}

.file-tip {
  display: block;
  color: #999;
  font-size: 12px;
  margin-top: 8px;
  line-height: 1.5;
}

.submit-btn {
  background-color: #cb261c;
  border: none;
  border-radius: 2px;
  color: #fff;
  cursor: pointer;
  display: inline-block;
  height: 38px;
  line-height: 38px;
  padding: 0 18px;
  text-align: center;
  transition: background-color 0.3s;
  font-size: 14px;
  font-weight: 600;
}

.submit-btn:hover {
  background-color: #b01f15;
}

/* 价格与折扣区域 */
.product-pricing-section {
  width: 100%;
  margin-top: 10px;
}

/* 业务类型标签 */
.business-type-label {
  margin-bottom: 12px;
}

.type-badge {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: #fff;
  font-size: 13px;
  border-radius: 4px;
  box-shadow: 0 2px 4px rgba(102, 126, 234, 0.2);
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.type-badge > *:not(:last-child) {
  margin-right: 6px;
}


.type-badge strong {
  font-weight: 600;
  font-size: 14px;
}

.price-display {
  background-color: #fffbfb;
  padding: 15px 20px;
  margin-bottom: 10px;
  border-radius: 4px;
}

.price-amount {
  margin: 0;
  display: flex;
  align-items: center;
  font-size: 24px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.price-amount > *:not(:last-child) {
  margin-right: 10px;
}


.price-value {
  color: #cb261c;
  font-weight: 700;
  font-size: 24px;
}

.discount-percent {
  color: #999;
  font-size: 14px;
  font-weight: 400;
  margin-left: 5px;
}

.original-price {
  color: #999;
  text-decoration: line-through;
  font-size: 16px;
}

.member-discount-box {
  background-color: #fffbfb;
  border: 1px solid #ffeaea;
  border-radius: 4px;
  height: 44px;
  margin-bottom: 16px;
  padding: 0 15px;
  position: relative;
  display: flex;
  align-items: center;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.member-discount-box > *:not(:last-child) {
  margin-right: 10px;
}


.member-level {
  display: inline-block;
  font-size: 16px;
  line-height: 44px;
  width: auto;
  margin: 0;
  display: flex;
  align-items: center;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.member-level > *:not(:last-child) {
  margin-right: 8px;
}


.level-name {
  font-size: 16px;
  color: #333;
}

.vip-price {
  color: #cb261c;
  font-weight: 600;
  font-size: 16px;
}

.discount-percent {
  color: #999;
  font-size: 12px;
  font-weight: 400;
}

.discount-text {
  display: inline;
  line-height: 44px;
  font-size: 13px;
  color: #666;
}

.member-link {
  color: #cb261c;
  position: absolute;
  right: 20px;
  text-decoration: none;
  transition: color 0.3s;
  font-size: 13px;
}

.member-link:hover {
  color: #b01f15;
}

.login-link {
  color: #d85850;
  text-decoration: none;
  transition: color 0.3s;
  font-size: 16px;
}

.login-link:hover {
  color: #cb261c;
  text-decoration: underline;
}

.no-discount-text {
  color: #999;
  font-size: 13px;
  margin-left: 10px;
}

/* 批发说明区域 */
.wholesale-section {
  display: none;
  margin-bottom: 20px;
}

.wholesale-content {
  background-color: #ffffff;
  border: 2px solid #e6e6e6;
  padding: 24px;
  border-radius: 4px;
}

.section-title {
  color: #000;
  font-size: 16px;
  line-height: 24px;
  margin: 0 0 16px 0;
  font-weight: 600;
}

.section-description {
  color: #999;
  line-height: 20px;
  margin-bottom: 24px;
  font-size: 14px;
}

.workflow-image {
  display: inline-block;
  max-width: 100%;
  margin-bottom: 20px;
}

.button-group {
  margin-top: 20px;
}

.btn-primary {
  background-color: #cb261c;
  color: #fff;
  cursor: pointer;
  display: inline-block;
  height: 38px;
  line-height: 38px;
  margin-right: 24px;
  padding: 0 18px;
  text-align: center;
  border: none;
  border-radius: 2px;
  transition: background-color 0.3s;
  font-size: 14px;
}

.button-group .help-link {
  color: #cb261c;
  text-decoration: none;
  transition: color 0.3s;
  line-height: 38px;
  font-size: 14px;
}

/* 商品详情列表 */
.product-details-list {
  padding-left: 0;
  margin-top: 20px;
}

.details-list-primary {
  list-style: none;
  margin: 0;
  padding: 0;
}

.list-item {
  display: table;
  width: 100%;
  line-height: 30px;
  margin-bottom: 16px;
}

.list-item-hidden {
  display: none;
}

.item-label {
  display: table-cell;
  line-height: 1.5;
  vertical-align: middle;
  width: 150px;
  color: #666;
  font-size: 14px;
  font-weight: 500;
}

.item-content {
  display: table-cell;
  vertical-align: middle;
  width: calc(100% - 150px);
  line-height: 1.5;
}

.item-services {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  line-height: 40px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.item-services > *:not(:last-child) {
  margin-right: 8px;
}


.coupon-badge {
  background-color: #ffededed;
  border: 1px solid #db1200;
  border-radius: 2px;
  color: #cb261c;
  display: inline-flex;
  height: 24px;
  line-height: 24px;
  padding: 0 10px;
  align-items: center;
  font-size: 13px;
  margin-bottom: 8px;
}

.service-badge {
  align-items: center;
  background-color: #ffffff;
  border: 1px solid #26bc00;
  border-radius: 3px;
  color: #26bc00;
  cursor: pointer;
  display: inline-flex;
  height: 24px;
  line-height: 24px;
  padding: 0 4px;
  position: relative;
  font-size: 13px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.service-badge > *:not(:last-child) {
  margin-right: 4px;
}


.service-badge.unsupported {
  border-color: #eb7e38;
  color: #eb7e38;
}

.service-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

/* 发货信息区域 */
.details-list-secondary-wrapper {
  margin-top: 20px;
}

.details-list-secondary {
  list-style: none;
  margin: 0;
  padding: 0;
}

.region-tags {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.region-tags > *:not(:last-child) {
  margin-right: 10px;
}


.region-code {
  background-color: #ffededed;
  border: 1px solid #cb261c;
  border-radius: 3px;
  color: #cb261c;
  cursor: pointer;
  display: inline-table;
  line-height: 28px;
  padding: 0 10px;
  font-size: 13px;
  transition: all 0.3s;
}

.region-code:hover {
  background-color: #cb261c;
  color: #fff;
}

.region-code.region-selected {
  background-color: #cb261c;
  color: #fff;
  font-weight: 600;
}

.logistics-select {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.logistics-select > *:not(:last-child) {
  margin-right: 15px;
}


.select-dropdown {
  appearance: auto;
  background-color: #ffffff;
  border: 1px solid #d5d5d5;
  cursor: default;
  display: inline-block;
  height: 34px;
  padding: 0 5px;
  width: 220px;
  font-size: 13px;
  outline: none;
}

.select-dropdown:focus {
  outline: none;
  border-color: #cb261c;
}

.shipping-time {
  display: inline;
  color: #666;
  font-size: 13px;
  white-space: nowrap;
}

/* 数量和价格控制 */
.list-item.hidden {
  display: none;
}

.quantity-control {
  background-color: #fff;
  border: 1px solid #ddd;
  border-radius: 5px;
  display: inline-table;
  line-height: 24px;
}

.quantity-btn {
  color: #ccc;
  cursor: pointer;
  display: inline-table;
  font-family: arial;
  font-size: 18px;
  line-height: 28px;
  text-align: center;
  width: 26px;
}

.quantity-input {
  appearance: textfield;
  -moz-appearance: textfield;
  background-color: transparent;
  border: none;
  border-radius: 0;
  color: #333;
  cursor: text;
  display: inline-block;
  height: 34px;
  padding: 0;
  text-align: center;
  outline: none;
  box-shadow: none;
  width: 44px;
  font-size: 13px;
}

.quantity-input::-webkit-outer-spin-button,
.quantity-input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

.stock-info {
  color: #999;
  display: inline;
  line-height: 1.5;
  padding-left: 10px;
  vertical-align: middle;
  font-size: 13px;
}

.error-info {
  color: #cb261c;
  display: block;
  line-height: 1.5;
  padding-left: 10px;
  vertical-align: middle;
  font-size: 13px;
  margin-top: 5px;
}

.stock-value {
  display: inline;
}

.price-help {
  cursor: pointer;
  display: inline-block;
  position: relative;
  margin-left: 5px;
}

.help-icon {
  cursor: pointer;
  display: inline-block;
  filter: grayscale(1);
  vertical-align: middle;
  width: 18px;
  height: 18px;
}

.help-tooltip {
  background-color: #4e4e4e;
  border-radius: 3px;
  box-shadow: rgba(0, 0, 0, 0.2) 0 0 7px;
  color: #ffffff;
  display: none;
  left: -10px;
  padding: 5px;
  position: absolute;
  top: 25px;
  width: 200px;
  z-index: 9;
  font-size: 12px;
  line-height: 20px;
}

.price-input-wrapper {
  display: flex;
  align-items: center;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.price-input-wrapper > *:not(:last-child) {
  margin-right: 15px;
}


.currency-input {
  align-items: center;
  background-color: #fff;
  border: 1px solid #ddd;
  border-radius: 3px;
  display: flex;
  line-height: 30px;
  padding: 0 8px;
  width: 150px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.currency-input > *:not(:last-child) {
  margin-right: 6px;
}


.currency {
  color: #999;
  font-size: 13px;
  white-space: nowrap;
}

.price-input {
  appearance: auto;
  background-color: #fff;
  border: none;
  border-radius: 2px;
  cursor: text;
  height: 34px;
  padding: 3px 5px;
  text-align: left;
  width: 94px;
  font-size: 13px;
  flex: 1;
}

.price-note {
  color: #999;
  font-size: 13px;
  line-height: 40px;
}

.price-value {
  color: #cb261c;
  font-weight: 700;
}

/* 操作按钮 */
.action-buttons-wrapper {
  margin-top: 30px;
}

.button-group-primary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  margin-top: 15px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.button-group-primary > *:not(:last-child) {
  margin-right: 15px;
}


.btn-secondary {
  appearance: auto;
  background-color: #cb261c;
  border: none;
  border-radius: 3px;
  color: #fff;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 40px;
  line-height: 40px;
  padding: 0 18px;
  text-align: center;
  transition: background-color 0.3s;
  font-size: 14px;
  white-space: nowrap;
  min-width: 150px;
  position: relative;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.btn-secondary > *:not(:last-child) {
  margin-right: 8px;
}


.btn-secondary:hover:not(:disabled) {
  background-color: #b01f15;
}

.btn-secondary:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.btn-secondary.btn-loading {
  pointer-events: none;
}

.btn-orange:disabled,
.btn-orange.btn-loading {
  opacity: 0.6;
  cursor: not-allowed;
  pointer-events: none;
}

.btn-orange .loading-spinner {
  margin-right: 6px;
}

.loading-spinner {
  width: 16px;
  height: 16px;
  animation: spin 1s linear infinite;
}

.loading-circle {
  stroke-dasharray: 63;
  stroke-dashoffset: 0;
  animation: dash 1.5s ease-in-out infinite;
}

@keyframes spin {
  0% {
    transform: rotate(0deg);
  }
  100% {
    transform: rotate(360deg);
  }
}

@keyframes dash {
  0% {
    stroke-dashoffset: 63;
  }
  50% {
    stroke-dashoffset: 15.75;
  }
  100% {
    stroke-dashoffset: 63;
  }
}

.btn-orange {
  align-items: center;
  appearance: auto;
  background-color: #ff6f00;
  border: none;
  border-radius: 3px;
  color: #fff;
  cursor: pointer;
  display: inline-flex;
  height: 40px;
  justify-content: center;
  line-height: 40px;
  padding: 0 18px;
  text-align: center;
  transition: background-color 0.3s;
  font-size: 14px;
  white-space: nowrap;
}

.btn-orange:hover {
  background-color: #e55d00;
}

.btn-publish {
  appearance: auto;
  background-color: #ffffff;
  border: 1px solid #d5d5d5;
  border-radius: 3px;
  cursor: pointer;
  display: inline-flex;
  height: 40px;
  align-items: center;
  padding: 0 12px;
  text-align: center;
  transition: all 0.3s;
  font-size: 14px;
  color: #333;
  white-space: nowrap;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.btn-publish > *:not(:last-child) {
  margin-right: 6px;
}


.btn-publish:hover {
  border-color: #999;
  background-color: #f5f5f5;
}

.btn-publish-icon {
  cursor: pointer;
  width: 18px;
  height: 18px;
  flex-shrink: 0;
}

.btn-favorite {
  appearance: auto;
  border: 1px solid #ccc;
  border-radius: 3px;
  cursor: pointer;
  height: 40px;
  width: 40px;
  padding: 0;
  text-align: center;
  transition: all 0.3s;
  background-color: transparent;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.btn-favorite:hover {
  border-color: #999;
}

.btn-favorite-icon {
  cursor: pointer;
  width: 20px;
  height: 20px;
}

.button-group-secondary {
  display: flex;
  flex-wrap: wrap;
  margin-top: 20px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.button-group-secondary > *:not(:last-child) {
  margin-right: 24px;
}


.btn-link-text {
  appearance: auto;
  background-color: transparent;
  border: none;
  cursor: pointer;
  display: inline-block;
  height: 34px;
  line-height: 34px;
  padding: 0;
  text-align: center;
  transition: color 0.3s;
  color: #666;
  font-size: 14px;
}

.btn-link-text:hover {
  color: #999;
}

.btn-download {
  display: inline-flex;
  align-items: center;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.btn-download > *:not(:last-child) {
  margin-right: 6px;
}


.btn-download-icon {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
  transition: color 0.3s;
}

.btn-download:hover {
  color: #8B0000;
}

.btn-download:hover .btn-download-icon {
  color: #8B0000;
}

.btn-link-feedback {
  appearance: auto;
  background-color: transparent;
  border: none;
  color: #cb261c;
  cursor: pointer;
  display: inline-flex;
  height: 34px;
  line-height: 34px;
  padding: 0;
  text-align: center;
  transition: color 0.3s;
  align-items: center;
  font-size: 14px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.btn-link-feedback > *:not(:last-child) {
  margin-right: 4px;
}


.btn-link-feedback:hover {
  color: #b01f15;
}

.btn-link-icon {
  cursor: pointer;
  display: inline-block;
  width: 20px;
  height: 20px;
  flex-shrink: 0;
}


/* 工厂直采询价表单样式 */
.inquiry-form-container {
  padding: 30px;
  background-color: #fff;
}

.form-header {
  margin-bottom: 30px;
  border-bottom: 1px solid #e5e7eb;
  padding-bottom: 20px;
}

.form-title {
  font-size: 20px;
  font-weight: 600;
  color: #111827;
  margin: 0 0 8px 0;
}

.form-subtitle {
  font-size: 14px;
  color: #6b7280;
  margin: 0;
}

.form-body {
  display: flex;
  flex-direction: column;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.form-body > *:not(:last-child) {
  margin-bottom: 24px;
}


.form-field-row {
  display: flex;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.form-field-row > *:not(:last-child) {
  margin-right: 16px;
}


.form-field {
  display: flex;
  flex-direction: column;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.form-field > *:not(:last-child) {
  margin-bottom: 8px;
}


.form-field-half {
  flex: 1;
  min-width: 0;
}

.form-field-third {
  flex: 1;
  min-width: 0;
}

.field-label {
  font-size: 14px;
  font-weight: 500;
  color: #374151;
  display: flex;
  align-items: center;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.field-label > *:not(:last-child) {
  margin-right: 4px;
}


.required-star {
  color: #cb261c;
  font-size: 16px;
}

.field-input,
.field-textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  font-size: 14px;
  color: #111827;
  transition: border-color 0.3s, box-shadow 0.3s;
  outline: none;
}

.field-input:focus,
.field-textarea:focus {
  border-color: #cb261c;
  box-shadow: 0 0 0 3px rgba(203, 38, 28, 0.1);
}

.field-input::placeholder,
.field-textarea::placeholder {
  color: #9ca3af;
}

.field-textarea {
  resize: vertical;
  min-height: 100px;
}

.form-actions {
  margin-top: 10px;
  padding-top: 20px;
  border-top: 1px solid #e5e7eb;
}

.submit-inquiry-btn {
  padding: 12px 32px;
  background-color: #cb261c;
  color: #fff;
  border: none;
  border-radius: 6px;
  font-size: 15px;
  font-weight: 500;
  cursor: pointer;
  transition: background-color 0.3s, transform 0.1s;
  outline: none;
}

.submit-inquiry-btn:hover:not(:disabled) {
  background-color: #a61e16;
  transform: translateY(-1px);
}

.submit-inquiry-btn:active:not(:disabled) {
  transform: translateY(0);
}

.submit-inquiry-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* 响应式调整 */
@media (max-width: 1200px) {
  .content-container,
  .breadcrumb-wrapper,
  .important-notice-wrapper {
    width: 95%;
  }
}

@media (max-width: 768px) {
  .content-container {
    flex-direction: column;
  }

  .product-section {
    flex: 1 1 auto;
  }

  .product-details-section {
    width: 100%;
  }

  .tags-list {
    flex-direction: column;
    height: auto;
  }

  .tag-dropship,
  .tag-direct {
    width: 100%;
  }

  .form-item {
    flex-direction: column;
  }

  .form-label {
    width: 100%;
    margin-bottom: 8px;
  }

  .form-control {
    width: 100%;
    max-width: 100%;
  }
  
  .inquiry-form-container {
    padding: 20px 15px;
  }
  
  .form-title {
    font-size: 18px;
  }
  
  .form-field-row {
    flex-direction: column;
  }

  /* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
  .form-field-row > *:not(:last-child) {
    margin-bottom: 24px;
  }

  
  .submit-inquiry-btn {
    width: 100%;
  }
}
</style>

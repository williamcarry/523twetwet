<template>
  <div class="payment-success-page">
    <!-- 使用公用的网站头部 -->
    <SiteHeader />

    <!-- Main content -->
    <main class="main min-h-[60vh] flex items-center justify-center py-12">
      <div class="container mx-auto px-4">
        <div class="max-w-2xl mx-auto">
          <!-- Success Card -->
          <div class="bg-white rounded-2xl shadow-xl p-8 md:p-12 text-center">
            <!-- Success Icon -->
            <div class="mb-6 flex justify-center">
              <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center">
                <CheckCircle2 :size="48" class="text-green-600" />
              </div>
            </div>

            <!-- Success Title -->
            <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">
              {{ t('successTitle') }}
            </h1>

            <!-- Success Message -->
            <p class="text-lg text-gray-600 mb-8">
              {{ t('successMessage') }}
            </p>

            <!-- Order Number (if available) -->
            <div v-if="orderNo" class="bg-gray-50 rounded-lg p-4 mb-8">
              <p class="text-sm text-gray-500 mb-1">{{ t('orderNumberLabel') }}</p>
              <p class="text-xl font-semibold text-gray-900">{{ orderNo }}</p>
            </div>

            <!-- Success Tips -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-8 text-left">
              <div class="flex items-start">
                <Info :size="20" class="text-blue-600 mr-3 mt-0.5 flex-shrink-0" />
                <div>
                  <p class="text-sm text-blue-900 font-medium mb-2">{{ t('tipsTitle') }}</p>
                  <ul class="text-sm text-blue-800 space-y-1">
                    <li>{{ t('tip1') }}</li>
                    <li>{{ t('tip2') }}</li>
                    <li>{{ t('tip3') }}</li>
                  </ul>
                </div>
              </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
              <a
                href="/user-center"
                class="inline-flex items-center justify-center px-6 py-3 border-2 border-red-600 text-red-600 font-medium rounded-lg hover:bg-red-50 transition-colors duration-200"
              >
                <User :size="20" class="mr-2" />
                {{ t('goToMemberCenterButton') }}
              </a>
              <a
                href="/"
                class="inline-flex items-center justify-center px-6 py-3 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 transition-colors duration-200"
              >
                <Home :size="20" class="mr-2" />
                {{ t('backToHomeButton') }}
              </a>
            </div>
          </div>

          <!-- Additional Info -->
          <div class="mt-8 text-center text-sm text-gray-600">
            <span>{{ t('needHelpText') }}</span>
            <a href="/contact-us" class="text-blue-600 hover:text-blue-700 underline ml-1">
              {{ t('contactUsLink') }}
            </a>
          </div>
        </div>
      </div>
    </main>

    <!-- 使用公用的网站底部 -->
    <SiteFooter />
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { CheckCircle2, Info, User, Home } from 'lucide-vue-next'
import SiteHeader from '../components/SiteHeader.vue'
import SiteFooter from '../components/SiteFooter.vue'

// 页面翻译数据
const translations = ref({})

// 当前语言
const currentLang = ref('zh-CN')

// 订单号（从URL参数获取）
const orderNo = ref('')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/PaymentSuccessPage.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
    // 如果加载失败，使用默认翻译
    translations.value = getDefaultTranslations()
  }
}

// 默认翻译（备用）
const getDefaultTranslations = () => ({
  'zh-CN': {
    successTitle: '支付成功！',
    successMessage: '您的订单已成功支付，我们将尽快为您处理订单。',
    orderNumberLabel: '订单编号',
    tipsTitle: '温馨提示',
    tip1: '您可以在个人中心查看订单详情和物流信息',
    tip2: '如有任何问题，请及时联系客服',
    tip3: '感谢您的购买，祝您购物愉快！',
    goToMemberCenterButton: '返回个人中心',
    backToHomeButton: '返回首页',
    needHelpText: '需要帮助？',
    contactUsLink: '联系我们'
  },
  'en': {
    successTitle: 'Payment Successful!',
    successMessage: 'Your order has been paid successfully. We will process your order as soon as possible.',
    orderNumberLabel: 'Order Number',
    tipsTitle: 'Tips',
    tip1: 'You can view order details and logistics information in your account',
    tip2: 'If you have any questions, please contact customer service',
    tip3: 'Thank you for your purchase, happy shopping!',
    goToMemberCenterButton: 'Back to Member Center',
    backToHomeButton: 'Back to Home',
    needHelpText: 'Need help?',
    contactUsLink: 'Contact Us'
  }
})

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

// 从URL获取订单号
const getOrderNoFromUrl = () => {
  const urlParams = new URLSearchParams(window.location.search)
  orderNo.value = urlParams.get('orderNo') || urlParams.get('order_no') || ''
}

onMounted(() => {
  // 初始加载翻译
  loadTranslations()
  
  // 获取订单号
  getOrderNoFromUrl()
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
.payment-success-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background-color: #f9fafb;
}

.main {
  flex: 1;
}

.container {
  width: 100%;
  margin-left: auto;
  margin-right: auto;
  padding-left: 1rem;
  padding-right: 1rem;
}

@media (min-width: 640px) {
  .container {
    max-width: 640px;
  }
}

@media (min-width: 768px) {
  .container {
    max-width: 768px;
  }
}

@media (min-width: 1024px) {
  .container {
    max-width: 1024px;
  }
}

@media (min-width: 1280px) {
  .container {
    max-width: 1280px;
  }
}
</style>

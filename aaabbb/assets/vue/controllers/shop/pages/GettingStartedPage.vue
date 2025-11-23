<template>
  <div class="min-h-screen flex flex-col bg-white">
    <SiteHeader />

    <main class="flex-1">
      <!-- Hero -->
      <section class="bg-slate-50 border-b">
        <div class="mx-auto w-full max-w-[1500px] md:w-[80%] md:min-w-[1200px] px-4 md:px-0 py-10 md:py-14">
          <div class="text-center max-w-4xl mx-auto">
            <h1 class="text-2xl md:text-3xl font-semibold text-slate-900 mb-3">{{ t('heroTitle') }}</h1>
            <p class="text-slate-600 leading-relaxed">
              {{ t('heroDescription') }}
            </p>
            <div class="hero-buttons flex items-center justify-center mt-6">
              <a href="/register" class="btn-primary">{{ t('registerButton') }}</a>
              <a href="/login" class="btn-secondary">{{ t('loginButton') }}</a>
            </div>
          </div>
        </div>
      </section>

      <!-- 加入赛盈一件代发分销平台的六大理由 -->
      <section class="bg-slate-50">
        <div class="mx-auto w-full max-w-[1500px] md:w-[80%] md:min-w-[1200px] px-4 md:px-0 pt-10 md:pt-14 pb-0">
          <div class="text-center mb-8">
            <h2 class="text-xl md:text-2xl font-semibold text-slate-900">{{ t('reasonsTitle') }}</h2>
          </div>

          <div class="reasons-grid grid grid-cols-1 md:grid-cols-2">
            <div class="card">
              <h3 class="card-title">{{ t('reason1Title') }}</h3>
              <p class="card-desc">{{ t('reason1Description') }}</p>
              <ul class="list-disc pl-5 text-slate-700 text-sm leading-6">
                <li>{{ t('reason1Item1') }}</li>
                <li>{{ t('reason1Item2') }}</li>
                <li>{{ t('reason1Item3') }}</li>
                <li>{{ t('reason1Item4') }}</li>
                <li>{{ t('reason1Item5') }}</li>
              </ul>
            </div>
            <div class="card">
              <img class="w-full h-40 object-cover rounded mb-3" :src="reason2ImageUrl" :alt="t('reason2ImageAlt')" />
              <h3 class="card-title">{{ t('reason2Title') }}</h3>
              <ul class="list-disc pl-5 text-slate-700 text-sm leading-6">
                <li>{{ t('reason2Item1') }}</li>
                <li>{{ t('reason2Item2') }}</li>
                <li>{{ t('reason2Item3') }}</li>
              </ul>
            </div>
            <div class="card">
              <h3 class="card-title">{{ t('reason3Title') }}</h3>
              <ul class="list-disc pl-5 text-slate-700 text-sm leading-6">
                <li>{{ t('reason3Item1') }}</li>
                <li>{{ t('reason3Item2') }}</li>
                <li>{{ t('reason3Item3') }}</li>
                <li>{{ t('reason3Item4') }}</li>
              </ul>
            </div>
            <div class="card">
              <img class="w-full h-40 object-cover rounded mb-3" :src="reason4ImageUrl" :alt="t('reason4ImageAlt')" />
              <h3 class="card-title">{{ t('reason4Title') }}</h3>
              <p class="card-desc">{{ t('reason4Description') }}</p>
              <ul class="list-disc pl-5 text-slate-700 text-sm leading-6">
                <li>{{ t('reason4Item1') }}</li>
                <li>{{ t('reason4Item2') }}</li>
                <li>{{ t('reason4Item3') }}</li>
                <li>{{ t('reason4Item4') }}</li>
              </ul>
            </div>
            <div class="card">
              <h3 class="card-title">{{ t('reason5Title') }}</h3>
              <p class="card-desc">{{ t('reason5Description') }}</p>
              <div class="text-slate-700 text-sm leading-6 space-y-2">
                <p>{{ t('reason5Item1') }}</p>
                <p>{{ t('reason5Item2') }}</p>
              </div>
            </div>
            <div class="card">
              <img class="w-full h-40 object-cover rounded mb-3" :src="reason6ImageUrl" :alt="t('reason6ImageAlt')" />
              <h3 class="card-title">{{ t('reason6Title') }}</h3>
              <p class="card-desc">{{ t('reason6Description') }}</p>
              <ul class="list-disc pl-5 text-slate-700 text-sm leading-6">
                <li>{{ t('reason6Item1') }}</li>
                <li>{{ t('reason6Item2') }}</li>
                <li>{{ t('reason6Item3') }}</li>
              </ul>
            </div>
          </div>
        </div>
      </section>

    </main>

    <SiteFooter />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import SiteHeader from '@/components/SiteHeader.vue'
import SiteFooter from '@/components/SiteFooter.vue'

// 页面翻译数据
const translations = ref({})

// 当前语言
const currentLang = ref('zh-CN')

// 图片URL
const reason2ImageUrl = '/frondend/images/GettingStartedPage/goten_join2.png'
const reason4ImageUrl = '/frondend/images/GettingStartedPage/goten_newjoin3.png'
const reason6ImageUrl = '/frondend/images/GettingStartedPage/goten_join5.png'

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/GettingStartedPage.json')
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
  loadTranslations()
  // 更新页面标题
  updatePageTitle()
}

onMounted(() => {
  // 初始加载翻译
  loadTranslations().then(() => {
    // 翻译加载完成后设置标题
    updatePageTitle()
  })
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.hero-buttons > *:not(:last-child) {
  margin-right: 0.75rem; /* gap-3 */
}

@media (min-width: 768px) {
  .hero-buttons > *:not(:last-child) {
    margin-right: 1rem; /* md:gap-4 */
  }
}

/* Grid布局兼容性修复：使用margin-bottom替代row-gap */
.reasons-grid > * {
  margin-bottom: 1rem; /* gap-4 */
}

.reasons-grid > *:nth-last-child(-n+1) {
  margin-bottom: 0; /* 最后一行不需要下边距 */
}

@media (min-width: 768px) {
  .reasons-grid > * {
    margin-bottom: 1.5rem; /* md:gap-6 */
  }
  
  /* 两列布局，最后两个元素不需要下边距 */
  .reasons-grid > *:nth-last-child(-n+2) {
    margin-bottom: 0;
  }
  
  /* 列间距：奇数列右边距 */
  .reasons-grid > *:nth-child(odd) {
    margin-right: 1.5rem; /* md:gap-6 */
  }
}

.btn-primary { @apply bg-primary text-white px-4 md:px-6 py-2 rounded font-medium hover:brightness-95; }
.btn-secondary { @apply bg-white text-primary border border-primary px-4 md:px-6 py-2 rounded font-medium hover:bg-slate-50; }
.btn-light { @apply bg-white text-primary px-4 md:px-6 py-2 rounded font-medium hover:bg-slate-100; }
.btn-dark { @apply bg-primary text-white px-4 md:px-6 py-2 rounded font-medium hover:brightness-95; }

.card { @apply rounded-lg border bg-white p-5 md:p-6 shadow-sm hover:shadow-md transition; }
.card-icon { @apply w-8 h-8 rounded-full bg-primary text-white flex items-center justify-center font-semibold mb-3; }
.card-title { @apply text-base md:text-lg font-semibold text-slate-900 mb-1; }
.card-desc { @apply text-slate-600 text-sm mb-3; }
.card-btn { @apply inline-block bg-primary text-white px-3 py-1.5 rounded text-sm font-medium hover:brightness-95; }
.card-link { @apply inline-block text-primary px-3 py-1.5 rounded text-sm font-medium hover:bg-slate-50; }
</style>
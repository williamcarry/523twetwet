<script setup>
import { ref, onMounted, onUnmounted, computed, provide } from 'vue'
import SiteHeader from '../components/SiteHeader.vue'
import CategoryNavBar from '../components/CategoryNavBar.vue'
import HeroBanner from '../components/HeroBanner.vue'
import CategorySidebar from '../components/CategorySidebar.vue'
import SiteFooter from '../components/SiteFooter.vue'
import RightFloatNav from '../components/RightFloatNav.vue'
import PlatformBoutique from '../components/PlatformBoutique.vue'
import FestivalDecorations from '../components/FestivalDecorations.vue'
import SkeletonLoader from '../components/SkeletonLoader.vue'

const heroHeight = ref(undefined)
const homeData = ref(null) // 存储首页所有数据
const isLoading = ref(true) // 加载状态

// 多语言配置
const translations = ref({})
const currentLang = ref('zh-CN')

// 提供数据给子组件
provide('homeData', homeData)

// 获取当前语言环境
const currentLanguage = computed(() => {
  return localStorage.getItem('app.lang') || 'zh-CN'
})

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/HomePage.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
  }
}

// 翻译函数
const t = (key) => {
  // 优先从localStorage获取语言，确保与全局语言设置一致
  const lang = localStorage.getItem('app.lang') || currentLang.value
  
  if (translations.value[lang] && translations.value[lang][key]) {
    return translations.value[lang][key]
  }
  
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
  loadTranslations().then(() => {
    updatePageTitle()
  })
}

// 获取首页数据
const fetchHomeData = async () => {
  try {
    const response = await fetch('/shop/api/home/categories')
    const result = await response.json()
    
    console.log('🔍 首页数据加载:', result)
    
    if (result.success) {
      homeData.value = result.data
      console.log('✅ 首页数据加载成功:', homeData.value)
    }
  } catch (error) {
    console.error('❌ 获取首页数据失败:', error)
  } finally {
    isLoading.value = false
    console.log('✅ 加载状态已设置为 false')
  }
}

function updateHeroHeight() {
  const el = document.querySelector('.category-sidebar')
  if (el && window.innerWidth >= 768) {
    // use fixed height to match Saleyee homepage carousel
    heroHeight.value = 480
  } else {
    heroHeight.value = undefined
  }
}

onMounted(() => {
  // 先设置当前语言，再加载翻译
  const savedLang = localStorage.getItem('app.lang')
  if (savedLang) {
    currentLang.value = savedLang
  }
  
  // 加载翻译
  loadTranslations().then(() => {
    updatePageTitle()
  })
  
  // 监听语言变化
  window.addEventListener('languagechange', handleLangChange)
  
  updateHeroHeight()
  window.addEventListener('resize', updateHeroHeight)
  fetchHomeData() // 组件挂载时获取首页数据
})

onUnmounted(() => {
  window.removeEventListener('resize', updateHeroHeight)
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<template>
  <div id="top" class="min-h-screen flex flex-col">
    <SiteHeader />
    <CategoryNavBar />
    <main class="flex-1" style="background-color: #F2F3F7;">
      <!-- Banner 区域（不加载骨架屏，直接显示） -->
      <div class="mx-auto w-full max-w-[1500px] md:w-[80%] md:min-w-[1150px] px-4 md:px-0">
        <div class="flex gap-0">
          <div class="hidden md:block w-1/4 lg:w-auto" style="width: 210px; flex-shrink: 0">
            <CategorySidebar />
          </div>
          <div class="flex-1 w-full">
            <HeroBanner :heroHeight="heroHeight" />
          </div>
        </div>
      </div>

      <!-- 平台爆款区域 -->
      <div id="platform-boutique">
        <template v-if="isLoading">
          <SkeletonLoader type="platform-boutique" />
        </template>
        <template v-else>
          <PlatformBoutique />
        </template>
      </div>

      <!-- 楼层区域 -->
      <template v-if="isLoading">
        <!-- 加载中显示骨架屏 -->
        <SkeletonLoader type="floor" :count="10" />
        <SkeletonLoader type="floor" :count="10" />
        <SkeletonLoader type="floor" :count="10" />
      </template>
      <template v-else>
        <!-- 加载完成显示实际楼层 -->
        <div 
          v-if="homeData && homeData.horizontalMenus && homeData.horizontalMenus.floor" 
          v-for="(floor, index) in homeData.horizontalMenus.floor" 
          :key="floor.id" 
          :id="'floor-' + floor.id"
        >
          <!-- 只显示有商品ID的楼层 -->
          <FestivalDecorations 
            v-if="floor.productIds && floor.productIds.length > 0"
            :floorData="floor" 
          />
        </div>
      </template>
    </main>

    <RightFloatNav />
    <SiteFooter />
  </div>
</template>

<style scoped></style>
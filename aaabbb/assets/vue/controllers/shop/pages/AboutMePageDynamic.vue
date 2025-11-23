<template>
  <div class="about-me-page">
    <SiteHeader />
    
    <!-- Main content -->
    <main class="main">
      <!-- Banner -->
      <div class="about-banner-wrapper">
        <img :src="getImageUrl(pageContent.banner.image)" :alt="pageContent.banner.alt.zh" class="banner-image" />
      </div>

      <div class="container">
        <!-- Introduction section -->
        <section class="intro-section">
          <div class="intro-content">
            <div class="intro-text">
              <h2>{{ pageContent.intro.title.zh }}</h2>
              <p>{{ pageContent.intro.text.zh }}</p>
            </div>
          </div>
        </section>

        <!-- Business section -->
        <section class="business-section">
          <h2>{{ pageContent.business.title.zh }}</h2>
          <div class="business-content">
            <img :src="getImageUrl(pageContent.business.image)" :alt="pageContent.business.alt.zh" class="business-image" />
          </div>
        </section>

        <!-- Services section -->
        <section class="services-section">
          <h2>{{ pageContent.services.title.zh }}</h2>
          <div class="services-grid">
            <!-- Service 1 (attachment layout) -->
            <div class="service-block">
              <img
                :alt="pageContent.services.items[0].alt.zh"
                loading="lazy"
                :src="getImageUrl(pageContent.services.items[0].image)"
                class="service-img"
              />
              <div class="service-block-content">
                <h3 class="service-title">{{ pageContent.services.items[0].title.zh }}</h3>
                <span class="service-hr"></span>
                <p class="service-desc">
                  {{ pageContent.services.items[0].description.zh }}
                </p>
                <router-link :to="pageContent.services.items[0].cta.link" class="service-cta">
                  {{ pageContent.services.items[0].cta.text.zh }}
                </router-link>
              </div>
            </div>

            <!-- Service 2 (supplier block matching attachment) -->
            <div class="service-block supplier-block">
              <div class="service-block-content">
                <h3 class="service-title">{{ pageContent.services.items[1].title.zh }}</h3>
                <span class="service-hr"></span>
                <p class="service-desc">
                  {{ pageContent.services.items[1].description.zh }}
                </p>
                <router-link :to="pageContent.services.items[1].cta.link" class="service-cta">
                  {{ pageContent.services.items[1].cta.text.zh }}
                </router-link>
              </div>
              <img
                :alt="pageContent.services.items[1].alt.zh"
                loading="lazy"
                :src="getImageUrl(pageContent.services.items[1].image)"
                class="service-img"
              />
            </div>
          </div>
        </section>

        <!-- Warehouse section -->
        <section class="warehouse-section">
          <h2>{{ pageContent.warehouse.title.zh }}</h2>
          <div class="warehouse-wrapper" ref="warehouseWrapper">
            <div class="warehouse-track" :style="trackStyle">
              <div v-for="(warehouse, idx) in pageContent.warehouse.images" :key="idx" class="warehouse-item">
                <img :src="getImageUrl(warehouse.src)" :alt="warehouse.name.zh" class="warehouse-img" loading="lazy" />
                <p class="warehouse-name">{{ warehouse.name.zh }}</p>
              </div>
            </div>

            <button v-if="maxIndex > 0" class="carousel-button next" @click="nextWarehouse" :disabled="currentWarehouse >= maxIndex" aria-label="Next slide">
              <ChevronRightIcon size="24" />
            </button>

            <button v-if="maxIndex > 0" class="carousel-button prev" @click="prevWarehouse" :disabled="currentWarehouse === 0" aria-label="Previous slide">
              <ChevronLeftIcon size="24" />
            </button>
          </div>
        </section>
      </div>
    </main>

    <SiteFooter />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { ChevronLeft as ChevronLeftIcon, ChevronRight as ChevronRightIcon } from 'lucide-vue-next'
import SiteHeader from '../components/SiteHeader.vue'
import SiteFooter from '../components/SiteFooter.vue'

// Props
const props = defineProps({
  pageContent: {
    type: Object,
    default: () => ({})
  }
})

// 内部数据
const internalPageContent = ref({
  banner: {
    image: '',
    alt: {
      zh: '',
      en: ''
    }
  },
  intro: {
    title: {
      zh: '',
      en: ''
    },
    text: {
      zh: '',
      en: ''
    }
  },
  business: {
    title: {
      zh: '',
      en: ''
    },
    image: '',
    alt: {
      zh: '',
      en: ''
    }
  },
  services: {
    title: {
      zh: '',
      en: ''
    },
    items: []
  },
  warehouse: {
    title: {
      zh: '',
      en: ''
    },
    images: []
  }
})

// 使用传入的props或内部数据
const pageContent = computed(() => {
  return props.pageContent && Object.keys(props.pageContent).length > 0 
    ? props.pageContent 
    : internalPageContent.value
})

const currentWarehouse = ref(0)
const warehouseWrapper = ref(null)
const visibleCount = ref(1)

const trackStyle = computed(() => ({
  transform: `translateX(-${currentWarehouse.value * 304}px)`,
  transition: 'transform 0.4s ease',
}))

const maxIndex = computed(() => Math.max(0, props.pageContent.warehouse?.images?.length - visibleCount.value || 0))

// 获取图片URL（用于显示）
const getImageUrl = (imageKey) => {
  if (!imageKey) return ''
  
  // 如果已经是完整URL，直接返回
  if (imageKey.startsWith('http')) {
    return imageKey
  }
  
  // 否则返回带签名的URL
  return `/shop/api/public-resource/image/signed-url?key=${encodeURIComponent(imageKey)}`
}

// 获取页面内容
const fetchPageContent = async () => {
  try {
    const response = await fetch('/shop/api/about-me-page')
    const result = await response.json()
    
    if (result.success) {
      internalPageContent.value = result.data
    }
  } catch (error) {
    console.error('获取页面内容失败:', error)
  }
}

function updateVisibleCount() {
  const width = warehouseWrapper.value ? warehouseWrapper.value.clientWidth : window.innerWidth
  const count = Math.max(1, Math.floor(width / 304))
  visibleCount.value = count
  if (currentWarehouse.value > maxIndex.value) {
    currentWarehouse.value = maxIndex.value
  }
}

onMounted(() => {
  updateVisibleCount()
  window.addEventListener('resize', updateVisibleCount)
  
  // 如果没有传入数据，则从API获取
  if (!props.pageContent || Object.keys(props.pageContent).length === 0) {
    fetchPageContent()
  }
})

onBeforeUnmount(() => window.removeEventListener('resize', updateVisibleCount))

const nextWarehouse = () => {
  if (currentWarehouse.value < maxIndex.value) {
    currentWarehouse.value++
  }
}

const prevWarehouse = () => {
  if (currentWarehouse.value > 0) {
    currentWarehouse.value--
  }
}
</script>

<style scoped>
.about-me-page {
  font-family: 'PingFang SC', 'Microsoft YaHei', 'Helvetica Neue', Arial, sans-serif;
  color: #2f2f2f;
  background: #f7f9fb;
  line-height: 1.5;
}

.container {
  width: 100%;
  max-width: 1500px;
  min-width: 1150px;
  margin: 0 auto;
  padding: 0 16px;
  box-sizing: border-box;
}

/* ============================================================
   Main Area
   ============================================================ */
.main {
  background: linear-gradient(180deg, #f7f9fb 0%, #ffffff 100%);
  padding: 40px 0;
  min-height: calc(100vh - 300px);
}

/* ============================================================
   Banner
   ============================================================ */
.about-banner-wrapper {
  width: 100vw;
  margin-left: calc(-50vw + 50%);
  margin-bottom: 60px;
  overflow: hidden;
}

.banner-image {
  width: 100%;
  height: auto;
  display: block;
}

/* ============================================================
   Introduction Section
   ============================================================ */
.intro-section {
  margin-bottom: 80px;
}

.intro-content {
  display: grid;
  grid-template-columns: 1fr;
  gap: 24px;
  margin-right: -24px;
  align-items: center;
  justify-items: center;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.intro-content {
  margin-right: -24px;
}

.intro-content > * {
  margin-right: 24px;
}


.intro-text { text-align: center; }

.intro-text p { max-width: 900px; margin: 0 auto; color: #374151; }

.intro-text h2 {
  font-size: 32px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 24px;
}

.intro-text p {
  font-size: 16px;
  color: #374151;
  line-height: 1.8;
  margin: 0;
}

.intro-video {
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.1);
}

.intro-video video {
  width: 100%;
  height: auto;
  display: block;
}

/* ============================================================
   Business Section
   ============================================================ */
.business-section {
  margin-bottom: 80px;
  text-align: center;
}

.business-section h2 {
  font-size: 32px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 40px;
}

.business-content {
  display: flex;
  justify-content: center;
}

.business-image {
  max-width: 100%;
  height: auto;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.1);
}

/* ============================================================
   Services Section
   ============================================================ */
.services-section {
  margin-bottom: 80px;
}

.services-section h2 {
  font-size: 32px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 40px;
  text-align: center;
}

.services-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 60px;
  margin-right: -60px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.services-grid {
  margin-right: -60px;
}

.services-grid > * {
  margin-right: 60px;
}


.service-card {
  display: grid;
  grid-template-columns: 1fr;
  gap: 24px;
  margin-right: -24px;
  background: #fff;
  padding: 32px;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
  transition: all 0.3s ease;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.service-card {
  margin-right: -24px;
}

.service-card > * {
  margin-right: 24px;
}


.service-card:hover {
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.15);
  transform: translateY(-4px);
}

.service-card:nth-child(2) {
  grid-template-columns: 1fr 1fr;
  gap: 24px;
}

.service-card:nth-child(2) .service-content {
  order: 1;
}

.service-card:nth-child(2) .service-image {
  order: 2;
}

.service-image {
  width: 100%;
  height: auto;
  border-radius: 8px;
  object-fit: cover;
}

.service-content h3 {
  font-size: 20px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 16px;
}

.service-content p {
  font-size: 15px;
  color: #374151;
  line-height: 1.8;
  margin: 0 0 20px;
}

.service-link {
  display: inline-block;
  padding: 12px 32px;
  background: #cb261c;
  color: #fff;
  text-decoration: none;
  border-radius: 6px;
  font-weight: 600;
  transition: all 0.2s ease;
}

.service-link:hover {
  background: #a81f18;
  transform: translateY(-2px);
}

/* Custom service-block styles */
.service-block {
  align-items: center;
  display: flex;
  justify-content: space-between;
  overflow-x: hidden;
  overflow-y: hidden;
  background-color: rgba(0, 0, 0, 0);
  grid-column: 1 / -1;
}
.service-img {
  max-width: 650px;
  overflow-clip-margin: content-box;
  overflow-x: clip;
  overflow-y: clip;
  vertical-align: middle;
  width: 45%;
}
.service-block-content {
  width: 50%;
}
.service-title {
  border-color: rgb(51, 51, 51);
  color: rgb(51, 51, 51);
  font-size: 36px;
  font-weight: 700;
  line-height: 54px;
  margin: 0 0 8px 0;
}
.service-hr {
  background-color: rgb(51, 51, 51);
  display: inline-block;
  height: 4px;
  margin-bottom: 30px;
  margin-top: 15px;
  width: 105px;
}
.service-desc {
  font-size: 16px;
  line-height: 30px;
  margin-bottom: 20px;
  color: #374151;
}
.service-cta {
  background-color: rgb(203, 38, 28);
  border-radius: 4px;
  box-shadow: rgba(18, 21, 22, 0.1) 0px 10px 20px 0px;
  color: rgb(255, 255, 255);
  cursor: pointer;
  display: inline-block;
  font-size: 24px;
  height: 65px;
  line-height: 65px;
  margin-top: 30px;
  text-align: center;
  text-decoration: none;
  transition-duration: 0.3s;
  width: 253px;
}

/* ============================================================
   Warehouse Section
   ============================================================ */
.warehouse-section {
  margin-bottom: 80px;
  text-align: center;
}

.warehouse-section h2 {
  font-size: 32px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 40px;
}

.warehouse-carousel {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 24px;
  margin-right: -24px;
  background: #fff;
  padding: 40px;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.warehouse-carousel {
  margin-right: -24px;
}

.warehouse-carousel > * {
  margin-right: 24px;
}


.carousel-button {
  width: 48px;
  height: 48px;
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
  color: #374151;
  flex-shrink: 0;
}

.carousel-button:hover:not(:disabled) {
  background: #cb261c;
  color: #fff;
  border-color: #cb261c;
}

.carousel-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Position arrows overlayed on the carousel */
.carousel-button.next {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  z-index: 20;
}
.carousel-button.prev {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  z-index: 20;
}

.carousel-content {
  flex: 1;
  max-width: 600px;
}

.warehouse-image {
  width: 100%;
  height: auto;
  max-height: 400px;
  object-fit: cover;
  border-radius: 8px;
  margin-bottom: 16px;
}

.warehouse-name {
  font-size: 18px;
  font-weight: 600;
  color: #111827;
  margin: 0;
}

/* New track styles for multi-item carousel */
.warehouse-wrapper {
  position: relative;
  width: 100%;
  overflow: hidden;
}
.warehouse-track {
  display: flex;
  align-items: center;
  transition: transform 0.4s ease;
  will-change: transform;
}
.warehouse-item {
  width: 284px;
  margin-right: 20px;
  text-align: center;
  flex: 0 0 auto;
}
.warehouse-img {
  width: 100%;
  height: auto;
  border-radius: 8px;
}

/* ============================================================
   Responsive Design
   ============================================================ */
@media (max-width: 768px) {
  .intro-content {
  grid-template-columns: 1fr;
    gap: 30px;
  margin-right: -30px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.intro-content {
  margin-right: -30px;
}

.intro-content > * {
  margin-right: 30px;
}


  .intro-text h2 {
    font-size: 24px;
  }

  .intro-text p {
    font-size: 14px;
  }

  .business-section h2,
  .services-section h2,
  .warehouse-section h2 {
    font-size: 24px;
  }

  .services-grid {
  grid-template-columns: 1fr;
    gap: 30px;
  margin-right: -30px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.services-grid {
  margin-right: -30px;
}

.services-grid > * {
  margin-right: 30px;
}


  .service-card:nth-child(2) {
    grid-template-columns: 1fr;
  }

  .service-card:nth-child(2) .service-content {
    order: 0;
  }

  .service-card:nth-child(2) .service-image {
    order: 1;
  }

  .warehouse-carousel {
  gap: 12px;
  margin-right: -12px;
    padding: 24px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.warehouse-carousel {
  margin-right: -12px;
}

.warehouse-carousel > * {
  margin-right: 12px;
}


  .carousel-button {
    width: 40px;
    height: 40px;
  }

  .top-bar-inner {
  flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  margin-right: -8px;
    height: auto;
    padding: 8px 0;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.top-bar-inner {
  margin-right: -8px;
}

.top-bar-inner > * {
  margin-right: 8px;
}

}

@media (max-width: 480px) {
  .container {
    padding: 0 12px;
  }

  .about-banner-wrapper {
    margin-bottom: 40px;
  }

  .intro-section,
  .business-section,
  .services-section,
  .warehouse-section {
    margin-bottom: 50px;
  }

  .intro-text h2,
  .business-section h2,
  .services-section h2,
  .warehouse-section h2 {
    font-size: 20px;
  }

  .intro-text p {
    font-size: 13px;
  }

  .services-grid {
  grid-template-columns: 1fr;
    gap: 20px;
  margin-right: -20px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.services-grid {
  margin-right: -20px;
}

.services-grid > * {
  margin-right: 20px;
}


  .service-card {
    padding: 20px;
  }

  .service-content h3 {
    font-size: 18px;
  }

  .service-content p {
    font-size: 14px;
    margin-bottom: 16px;
  }

  .warehouse-carousel {
  flex-direction: column;
    gap: 16px;
  margin-right: -16px;
    padding: 16px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.warehouse-carousel {
  margin-right: -16px;
}

.warehouse-carousel > * {
  margin-right: 16px;
}


  .carousel-button {
    display: none;
  }

  .top-bar-link {
    font-size: 11px;
  }

  .nav-link {
    font-size: 12px;
  }
}
</style>
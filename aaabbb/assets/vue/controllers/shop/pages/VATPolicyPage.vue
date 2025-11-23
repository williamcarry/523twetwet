<!--
CSS 引用说明：
1. 全局样式：在 src/main.ts 中自动加载
   - src/assets/main.css (导入 src/assets/base.css)
     - @tailwind base, components, utilities (Tailwind CSS)
     - 全局 CSS 变量 (--color-*, --section-gap, --category-width 等)
   - Element Plus 样式 (element-plus/dist/index.css)
2. 页面局部样式：该文件底部的 <style scoped> 块
3. 导入的子组件样式：由各子组件的 <style scoped> 块提供
-->
<template>
  <div class="vat-page">
    <header class="header">
      <div class="container header-inner">
        <div class="header-brand">
          <a class="header-logo" href="/">
            <img src="/frondend/images/logo.png" :alt="t('headerLogoAlt')" />
          </a>
          <div class="header-title">
            <h1>{{ t('headerTitle') }}</h1>
            <p>{{ t('headerSubtitle') }}</p>
          </div>
        </div>
        <nav class="header-nav">
          <a class="nav-link" href="/">{{ t('navHome') }}</a>
          <a class="nav-link" href="/help-center">{{ t('navHelpCenter') }}</a>
          <a class="nav-link" href="/contact-us">{{ t('navContactUs') }}</a>
        </nav>
      </div>
    </header>

    <main class="main container">
      <div class="breadcrumb">
        <!-- <a href="/help-center">{{ t('breadcrumbHelpCenter') }}</a>
        <span>&gt;</span>
        <span>{{ t('breadcrumbVATPolicy') }}</span> -->
      </div>

      <article class="vat-article">
        <h1 class="article-title">{{ t('articleTitle') }}</h1>

        <div class="article-content">
          <p>{{ t('introText1') }}</p>

          <p>{{ t('introText2') }}</p>

          <section class="section">
            <h2>{{ t('section1Title') }}</h2>

            <div class="subsection">
              <h3>{{ t('subsection1_1Title') }}</h3>
              <p>{{ t('subsection1_1Text') }}</p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection1_2Title') }}</h3>
              <p>{{ t('subsection1_2Text') }}</p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection1_3Title') }}</h3>
              <p>{{ t('subsection1_3Text') }}</p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection1_4Title') }}</h3>
              <p>{{ t('subsection1_4Text') }}</p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection1_5Title') }}</h3>
              <p>{{ t('subsection1_5Text') }}</p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection1_6Title') }}</h3>
              <p>{{ t('subsection1_6Text1') }}</p>

              <p>{{ t('subsection1_6Text2') }}</p>

              <p>{{ t('subsection1_6Process') }}</p>
              <ul>
                <li>{{ t('process1') }}</li>
                <li>{{ t('process2') }}</li>
                <li>{{ t('process3') }}</li>
              </ul>

              <p>{{ t('subsection1_6Text3') }}</p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection1_7Title') }}</h3>
              <p>{{ t('subsection1_7Text') }}</p>
            </div>
          </section>

          <section class="section">
            <h2>{{ t('section2Title') }}</h2>

            <p>{{ t('section2Text') }}</p>
            <ul>
              <li>{{ t('amazonListItem1') }}</li>
              <li>{{ t('amazonListItem2') }}</li>
            </ul>

            <p>{{ t('section2Text2') }}</p>

            <div class="subsection">
              <h3>{{ t('subsection2_1Title') }}</h3>
              <p>{{ t('subsection2_1Text1') }}</p>

              <p><strong>{{ t('subsection2_1Condition1') }}</strong></p>

              <p><strong>{{ t('subsection2_1Condition2') }}</strong></p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection2_2Title') }}</h3>
              <p>{{ t('subsection2_2Text') }}</p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection2_3Title') }}</h3>
              <p>{{ t('subsection2_3Text') }}</p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection2_4Title') }}</h3>
              <ul>
                <li>{{ t('change1') }}</li>
                <li>{{ t('change2') }}</li>
                <li>{{ t('change3') }}</li>
              </ul>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection2_5Title') }}</h3>
              <ul>
                <li>{{ t('preparation1') }}</li>
                <li>{{ t('preparation2') }}</li>
                <li>{{ t('preparation3') }}</li>
              </ul>
            </div>
          </section>

          <section class="section">
            <h2>{{ t('section3Title') }}</h2>

            <p>{{ t('section3Text1') }}</p>
            <ul>
              <li>{{ t('ebayListItem1') }}</li>
              <li>{{ t('ebayListItem2') }}</li>
            </ul>

            <p>{{ t('section3Text2') }}</p>
          </section>

          <section class="section">
            <h2>{{ t('section4Title') }}</h2>

            <p>{{ t('section4Text') }}</p>

            <div class="subsection">
              <h3>{{ t('subsection4_1Title') }}</h3>
              <p>{{ t('subsection4_1Text') }}</p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection4_2Title') }}</h3>
              <p>{{ t('subsection4_2Text1') }}</p>

              <p>{{ t('subsection4_2Text2') }}</p>

              <p><strong>{{ t('situation1') }}</strong>{{ t('situation1Desc') }}</p>

              <p><strong>{{ t('situation2') }}</strong>{{ t('situation2Desc1') }}</p>
            </div>

            <div class="subsection">
              <h3>{{ t('subsection4_3Title') }}</h3>
              <p>{{ t('subsection4_3Text') }}</p>
            </div>
          </section>

          <section class="section">
            <h2>{{ t('section5Title') }}</h2>

            <div class="qa-items">
              <div class="qa-item">
                <h3>{{ t('qa1Question') }}</h3>
                <p>{{ t('qa1Answer') }}</p>
              </div>

              <div class="qa-item">
                <h3>{{ t('qa2Question') }}</h3>
                <ul>
                  <li>{{ t('qa2ListItem1') }}</li>
                  <li>{{ t('qa2ListItem2') }}</li>
                </ul>
              </div>

              <div class="qa-item">
                <h3>{{ t('qa3Question') }}</h3>
                <p>{{ t('qa3Answer') }}</p>
              </div>

              <div class="qa-item">
                <h3>{{ t('qa4Question') }}</h3>
                <p>{{ t('qa4Answer') }}</p>
              </div>

              <div class="qa-item">
                <h3>{{ t('qa5Question') }}</h3>
                <p>{{ t('qa5Answer') }}</p>
              </div>

              <div class="qa-item">
                <h3>{{ t('qa6Question') }}</h3>
                <p>{{ t('qa6Answer') }}</p>
              </div>

              <div class="qa-item">
                <h3>{{ t('qa7Question') }}</h3>
                <p>{{ t('qa7Answer') }}</p>
              </div>

              <div class="qa-item">
                <h3>{{ t('qa8Question') }}</h3>
                <p>{{ t('qa8Answer') }}</p>
              </div>

              <div class="qa-item">
                <h3>{{ t('qa9Question') }}</h3>
                <ul>
                  <li>{{ t('qa9ListItem1') }}</li>
                  <li>{{ t('qa9ListItem2') }}</li>
                  <li>{{ t('qa9ListItem3') }}</li>
                  <li>{{ t('qa9ListItem4') }}</li>
                  <li>{{ t('qa9ListItem5') }}</li>
                  <li>{{ t('qa9ListItem6') }}</li>
                  <li>{{ t('qa9ListItem7') }}</li>
                  <li>{{ t('qa9ListItem8') }}</li>
                  <li>{{ t('qa9ListItem9') }}</li>
                </ul>
              </div>

              <div class="qa-item">
                <h3>{{ t('qa10Question') }}</h3>
                <ul>
                  <li>{{ t('qa10ListItem1') }}</li>
                  <li>{{ t('qa10ListItem2') }}</li>
                  <li>{{ t('qa10ListItem3') }}</li>
                  <li>{{ t('qa10ListItem4') }}</li>
                  <li>{{ t('qa10ListItem5') }}</li>
                  <li>{{ t('qa10ListItem6') }}</li>
                </ul>
              </div>
            </div>

            <p class="conclusion">{{ t('conclusionText1') }}</p>

            <p>{{ t('conclusionText2') }}</p>
          </section>
        </div>
      </article>
    </main>

    <!-- 使用公用的网站底部 -->
    <SiteFooter />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import SiteHeader from '../components/SiteHeader.vue'
import SiteFooter from '../components/SiteFooter.vue'

// 页面翻译数据
const translations = ref({})

// 当前语言
const currentLang = ref('zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/VATPolicyPage.json')
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

// 监听语言变化事件
// 更新页面标题
const updatePageTitle = () => {
  const title = t('pageTitle')
  if (title && title !== 'pageTitle') {
    document.title = title
  }
}

const handleLangChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  }
  // 重新加载翻译以确保语言切换时更新
  loadTranslations().then(() => {
    updatePageTitle()
  })
}

// 组件挂载时获取数据
onMounted(() => {
  // 初始加载翻译
  loadTranslations().then(() => {
    // 翻译加载完成后设置标题
    updatePageTitle()
  })
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

// 组件卸载前移除事件监听器
// onBeforeUnmount(() => {
//   window.removeEventListener('languagechange', handleLangChange)
// })
// VAT政策页面组件
</script>

<style scoped>
.vat-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: #fff;
}

.container {
  max-width: 1500px;
  min-width: 1150px;
  margin: 0 auto;
  padding: 0 16px;
}

.top-bar {
  background: #f5f7fa;
  border-bottom: 1px solid #e5e7eb;
  font-size: 12px;
  color: #666;
}

.top-bar-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 36px;
}

.top-bar-notice {
  color: #cb261c;
  text-decoration: none;
  font-weight: 500;
}

.top-bar-link {
  color: #666;
  text-decoration: none;
  margin-left: 18px;
  transition: color 0.3s;
}

.top-bar-link:hover {
  color: #cb261c;
}

.header {
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
}

.header-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 0;
}

.header-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-right: -12px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.header-brand {
  margin-right: -12px;
}

.header-brand > * {
  margin-right: 12px;
}


.header-logo img {
  height: 56px;
}

.header-title h1 {
  margin: 0;
  font-size: 20px;
  color: #1f2937;
}

.header-title p {
  margin: 0;
  font-size: 14px;
  color: #6b7280;
}

.header-nav {
  display: flex;
  gap: 24px;
  margin-right: -24px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.header-nav {
  margin-right: -24px;
}

.header-nav > * {
  margin-right: 24px;
}


.nav-link {
  color: #374151;
  text-decoration: none;
  transition: color 0.3s;
}

.nav-link:hover {
  color: #cb261c;
}

.main {
  padding: 40px 0;
  flex: 1;
}

.breadcrumb {
  color: #6b7280;
  margin-bottom: 24px;
  font-size: 14px;
}

.breadcrumb a {
  color: #3b82f6;
  text-decoration: none;
}

.breadcrumb span {
  margin: 0 8px;
}

.vat-article {
  background: #fff;
  padding: 40px;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.article-title {
  text-align: center;
  font-size: 28px;
  font-weight: bold;
  color: #1f2937;
  margin: 0 0 30px 0;
  line-height: 1.4;
}

.article-content {
  color: #374151;
  line-height: 1.8;
  font-size: 15px;
}

.article-content > p {
  margin-bottom: 16px;
}

.section {
  margin-bottom: 32px;
}

.section h2 {
  font-size: 20px;
  font-weight: bold;
  color: #1f2937;
  margin: 24px 0 16px 0;
  padding-bottom: 12px;
  border-bottom: 2px solid #e5e7eb;
}

.subsection {
  margin-bottom: 20px;
  padding-left: 16px;
}

.subsection h3 {
  font-size: 16px;
  font-weight: 600;
  color: #374151;
  margin: 16px 0 12px 0;
}

.subsection p {
  margin-bottom: 12px;
  color: #374151;
}

.subsection ul {
  margin: 12px 0 12px 24px;
  padding: 0;
}

.subsection li {
  margin-bottom: 8px;
  color: #374151;
  list-style-type: disc;
}

.subsection strong {
  color: #1f2937;
  font-weight: 600;
}

.qa-items {
  margin: 20px 0;
}

.qa-item {
  margin-bottom: 24px;
  padding: 16px;
  background: #f9fafb;
  border-radius: 6px;
  border-left: 4px solid #cb261c;
}

.qa-item h3 {
  font-size: 15px;
  font-weight: 600;
  color: #1f2937;
  margin: 0 0 12px 0;
}

.qa-item p {
  margin-bottom: 12px;
  color: #374151;
}

.qa-item ul {
  margin: 12px 0 0 24px;
  padding: 0;
}

.qa-item li {
  margin-bottom: 8px;
  color: #374151;
  list-style-type: disc;
}

.conclusion {
  margin-top: 24px;
  padding: 16px;
  background: #f0fdf4;
  border-radius: 6px;
  border-left: 4px solid #10b981;
  color: #374151;
}

/* 为SiteFooter组件添加样式 */
:deep(footer.bg-slate-900) {
  background: #fafafa !important;
  border-top: 1px solid #e5e7eb !important;
}

/* 优化SiteFooter在浅色背景下的文字颜色 */
:deep(footer.bg-slate-900 .text-slate-200) {
  color: #6b7280 !important;
}

:deep(footer.bg-slate-900 .text-white) {
  color: #1f2937 !important;
}

:deep(footer.bg-slate-900 .text-slate-300) {
  color: #6b7280 !important;
}

:deep(footer.bg-slate-900 .text-slate-500) {
  color: #9ca3af !important;
}

:deep(footer.bg-slate-900 a) {
  color: #6b7280 !important;
}

:deep(footer.bg-slate-900 a:hover) {
  color: #1f2937 !important;
}
</style>

<template>
  <div class="div" v-if="products && products.length > 0">
    <div class="div-2">
      <p class="p">
        <b class="b"></b>
        <span class="span">{{ t('relatedProductsTitle') }}</span>
      </p>
      <ul class="ul">
        <li v-for="product in products" :key="product.id" class="li">
          <a :href="`/shop/item/${product.id}`" class="a">
            <img
              :alt="displayTitle(product)"
              loading="lazy"
              :src="product.thumbnailImage"
              class="img"
            />
          </a>
          <a :href="`/shop/item/${product.id}`" class="a">
            <p class="p-2" :title="displayTitle(product)">
              {{ displayTitle(product) }}
            </p>
          </a>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

// 页面翻译数据
const translations = ref({})

// 当前语言
const currentLang = ref('zh-CN')

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

// 翻译函数
const t = (key) => {
  const lang = localStorage.getItem('app.lang') || currentLang.value
  if (translations.value[lang] && translations.value[lang][key]) {
    return translations.value[lang][key]
  }
  return key
}

// 商品标题显示：优先英文，无英文时显示中文
const displayTitle = (product) => {
  // 使用 currentLang.value 确保响应式更新
  const lang = currentLang.value
  // 中文环境显示中文标题
  if (lang === 'zh-CN') {
    return product?.title || ''
  }
  // 英文环境优先显示英文标题，没有英文标题则显示中文标题
  return product?.titleEn || product?.title || ''
}

// 监听语言变化事件
const handleLangChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  }
  loadTranslations()
}

// 接收父组件传递的相关商品数据
const props = defineProps({
  products: {
    type: Array,
    default: () => []
  }
})

onMounted(() => {
  loadTranslations()
  window.addEventListener('languagechange', handleLangChange)
})

onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
.div {
  max-width: 1500px;
  min-width: 1200px;
  position: relative;
  width: 80%;
  background-color: rgb(242, 243, 247);
  margin: 0 auto;
}
.div-2 {
  background-color: rgb(255, 255, 255);
  margin-bottom: 20px;
  margin-top: 20px;
}
.p {
  padding-top: 15px;
  position: relative;
  text-align: center;
}
.b {
  display: inline;
  font-weight: 700;
  text-align: center;
}
.span {
  background-color: rgb(255, 255, 255);
  border-color: rgb(51, 51, 51);
  color: rgb(51, 51, 51);
  display: inline-block;
  font-size: 16px;
  font-weight: 700;
  line-height: 30px;
  outline-color: rgb(51, 51, 51);
  padding-left: 20px;
  padding-right: 20px;
  position: relative;
  text-align: center;
  text-decoration-color: rgb(51, 51, 51);
  text-emphasis-color: rgb(51, 51, 51);
  z-index: 9;
}
.ul {
  display: flex;
  flex-wrap: nowrap;
  justify-content: flex-start;
  align-items: flex-start;
  padding-bottom: 20px;
  padding-left: 30px;
  padding-right: 30px;
  padding-top: 20px;
  overflow: hidden;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.ul > * {
  margin-bottom: 0;
}

/* 只给非第7个商品添加右边距 */
.ul > *:not(:nth-child(7n)) {
  margin-right: 12px;
}

.li {
  display: flex;
  flex-direction: column;
  position: relative;
  text-align: center;
  width: calc((100% - 72px) / 7);
  flex: 0 0 calc((100% - 72px) / 7);
  height: 230px;
  box-sizing: border-box;
  justify-content: flex-start;
  overflow: hidden;
}
.div-3 {
  background-color: rgb(255, 97, 40);
  border-bottom-left-radius: 3px;
  border-bottom-right-radius: 3px;
  border-color: rgb(255, 255, 255);
  border-radius: 3px;
  border-top-left-radius: 3px;
  border-top-right-radius: 3px;
  color: rgb(255, 255, 255);
  font-size: 12px;
  height: 24px;
  line-height: 24px;
  outline-color: rgb(255, 255, 255);
  padding-left: 5px;
  padding-right: 5px;
  position: absolute;
  right: 0px;
  text-align: center;
  text-decoration-color: rgb(255, 255, 255);
  text-emphasis-color: rgb(255, 255, 255);
  top: 0px;
}
.a {
  border-color: rgb(51, 51, 51);
  color: rgb(51, 51, 51);
  cursor: pointer;
  display: block;
  outline-color: rgb(51, 51, 51);
  text-align: center;
  text-decoration-color: rgb(51, 51, 51);
  text-emphasis-color: rgb(51, 51, 51);
  transition-duration: 0.3s;
  padding: 0;
}
/* ensure consistent 5px gap between image and title */
.li > a:first-of-type { margin-bottom: 5px; }
.img {
  cursor: pointer;
  display: block;
  margin-left: auto;
  margin-right: auto;
  width: 100%;
  height: auto;
  aspect-ratio: 1 / 1;
  object-fit: cover;
  overflow-clip-margin: content-box;
  overflow-x: clip;
  overflow-y: clip;
  text-align: center;
  vertical-align: middle;
}
.p-2,
.p-3,
.p-4,
.p-5,
.p-6,
.p-7 {
  cursor: pointer;
  display: block;
  height: 35px;
  line-height: 35px;
  margin: 0;
  padding: 0;
  overflow: hidden;
  text-align: center;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.div-4,
.div-5 {
  background-color: rgb(255, 97, 40);
  border-bottom-left-radius: 3px;
  border-bottom-right-radius: 3px;
  border-color: rgb(255, 255, 255);
  border-radius: 3px;
  border-top-left-radius: 3px;
  border-top-right-radius: 3px;
  color: rgb(255, 255, 255);
  font-size: 12px;
  height: 24px;
  line-height: 24px;
  outline-color: rgb(255, 255, 255);
  padding-left: 5px;
  padding-right: 5px;
  position: absolute;
  right: 0px;
  text-align: center;
  text-decoration-color: rgb(255, 255, 255);
  text-emphasis-color: rgb(255, 255, 255);
  top: 0px;
}
</style>
<template>
  <div class="div">
    <div class="div-2">
      <div class="div-3">
        <ul class="ul">
          <li class="li" :class="{ active: tab==='details' }" @click="tab='details'">{{ t('detailInfoTab') }}</li>
          <li class="li-2" :class="{ active: tab==='limit' }">零售限价</li>
          <li class="li-3" :class="{ active: tab==='shipping' }" @click="tab='shipping'">{{ t('shippingPaymentTab') }}</li>
          <li class="li-4" :class="{ active: tab==='addresses' }" @click="tab='addresses'">{{ t('addressesTab') }}</li>
          <li class="li-5">销售指导</li>
          <li class="li-6">附件</li>
          <li class="li-7">兼容属性</li>
        </ul>

        <div class="div-4">
          <!-- 详细信息 -->
          <section v-show="tab==='details'">
            <div class="divs">
              <div class="div-5">
                <ul class="ul-2">
                  <li class="li-8">{{ t('lengthLabel') }}<em class="em">{{ product?.length || '-' }}</em></li>
                  <li class="li-9">{{ t('widthLabel') }}<em class="em">{{ product?.width || '-' }}</em></li>
                  <li class="li-10">{{ t('heightLabel') }}<em class="em">{{ product?.height || '-' }}</em></li>
                  <li class="li-11">{{ t('weightLabel') }}<em class="em">{{ product?.weight || '-' }}</em></li>
                  <div class="div-6"></div>
                </ul>
              </div>
              <div class="div-7"></div>
              <div class="div-8"></div>
              <div class="div-9">
                <!-- 商品详情富文本内容 -->
                <div v-if="product?.richContent" v-html="product.richContent"></div>
                <div v-else>
                  <p class="p">{{ t('noDetailInfo') }}</p>
                </div>
                
                <br class="br-2" />
                <!-- 商品详情图片 -->
                <div class="div-10" v-if="product?.detailImages && product.detailImages.length > 0">
                  <img 
                    v-for="(img, index) in product.detailImages" 
                    :key="index"
                    :alt="`${t('detailImageAlt')}${index + 1}`" 
                    loading="lazy" 
                    :src="img.url" 
                    class="img" 
                  />
                </div>
              </div>
            </div>
          </section>

          <!-- 零售限价（默认隐藏） -->
          <section v-show="tab==='limit'">
            <div class="div-12">
              <table class="table">
                <thead class="thead">
                  <tr class="tr">
                    <td class="td">SKU</td>
                    <td class="td">属性</td>
                    <td class="td">版本编码</td>
                    <td class="td">价格类型</td>
                    <td class="td">销售平台</td>
                    <td class="td">销售站点</td>
                    <td class="td">销售国家</td>
                    <td class="td">最低零售价</td>
                    <td class="td">币别</td>
                    <td class="td">生效时间</td>
                    <td class="td">失效时间</td>
                  </tr>
                </thead>
                <tbody class="tbody">
                </tbody>
              </table>
            </div>
          </section>

          <!-- 物流&支付信息 -->
          <section v-show="tab==='shipping'">
            <div class="div-14">
              <!-- 使用后端返回的 plinfo 数据，根据语言选择内容 -->
              <div v-if="plinfoContent" v-html="plinfoContent"></div>
              <div v-else>
                <p class="p">{{ t('noShippingPaymentInfo') }}</p>
              </div>
            </div>
          </section>

          <!-- 发货&退货地址 -->
          <section v-show="tab==='addresses'">
            <div class="div-43">
              <p class="p-2"><b class="b">{{ t('shippingAddressTitle') }}</b></p>
              <div v-if="product?.shippingAddress">
                <p class="p" style="white-space: pre-wrap;">{{ product.shippingAddress }}</p>
              </div>
              <div v-else>
                <p class="p">{{ t('noShippingAddress') }}</p>
              </div>
              
              <p class="p-3"><b class="b">{{ t('returnAddressTitle') }}</b></p>
              <div v-if="product?.returnAddress">
                <p class="p" style="white-space: pre-wrap;">{{ product.returnAddress }}</p>
              </div>
              <div v-else>
                <p class="p">{{ t('noReturnAddress') }}</p>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

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

// 翻译函数
const t = (key) => {
  const lang = localStorage.getItem('app.lang') || currentLang.value
  if (translations.value[lang] && translations.value[lang][key]) {
    return translations.value[lang][key]
  }
  return key
}

// 物流与支付信息内容（根据语言选择）
const plinfoContent = computed(() => {
  // 使用 currentLang.value 确保响应式更新
  const lang = currentLang.value
  // 中文环境显示中文内容
  if (lang === 'zh-CN') {
    return props.plinfo?.content || ''
  }
  // 英文环境优先显示英文内容，没有英文内容则显示中文内容
  return props.plinfo?.contentEN || props.plinfo?.content || ''
})

// 商品标题显示：根据语言动态选择
const displayTitle = computed(() => {
  // 使用 currentLang.value 确保响应式更新
  const lang = currentLang.value
  // 中文环境显示中文标题
  if (lang === 'zh-CN') {
    return props.product?.title || ''
  }
  // 英文环境优先显示英文标题，没有英文标题则显示中文标题
  return props.product?.titleEn || props.product?.title || ''
})

// 监听语言变化事件
const handleLangChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  }
  loadTranslations()
}

// 接收父组件传递的数据
const props = defineProps({
  product: {
    type: Object,
    default: () => ({})
  },
  plinfo: {
    type: Object,
    default: () => ({})
  }
})

const tab = ref('details')

onMounted(() => {
  loadTranslations()
  window.addEventListener('languagechange', handleLangChange)
})

onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
.div { max-width: 1500px; min-width: 1200px; position: relative; width: 80%; background-color: #f2f3f7; margin: 0 auto; }
.div-2 { background-color: #fff; float: left; width: 100%; }
.div-3 { position: relative; text-align: left; }
.ul { border-bottom: 1px solid #cb261c; height: 40px; position: relative; white-space: nowrap; font-size: 0; text-align: left; background-color: #fff; }
.li, .li-3, .li-4 { display: inline-block; min-width: 110px; padding: 0 15px; line-height: 40px; text-align: center; cursor: pointer; white-space: nowrap; vertical-align: middle; transition: .2s; color: #333; background-color: transparent; font-size: 14px; }
.li.active, .li-3.active, .li-4.active { background-color: #cb261c; color: #fff; }
.li:hover, .li-3:hover, .li-4:hover { background-color: #ffeaea; color: #cb261c; }
.li-2, .li-5, .li-6, .li-7 { display: none; }
.li, .li-3, .li-4 { margin: 0; }
.div-4 { padding: 10px; text-align: left; }
/* Following styles mirror provided attachment */
.divs { text-align: left; }
.div-5 { text-align: left; }
.ul-2 { border-bottom: 1px solid #eee; border-color: #666 #666 #eee; border-style: none none solid; }
.li-8, .li-9, .li-10, .li-11 { float: left; width: 25%; height: 40px; line-height: 40px; font-weight: 700; text-align: left; }
.em { display: inline; font-weight: 700; line-height: 40px; }
.div-6 { clear: both; }
.p { text-align: left; }
.span { background: #fff; color: #333; display: inline; font-family: Arial; font-size: 12px; }
.ul-3, .ul-4, .ul-5, .ul-6, .ul-7, .ul-8, .ul-9, .ul-10, .ul-11 { background: #fff; color: #333; font-family: Arial; font-size: 12px; }
.li-12, .li-13, .li-14, .li-15, .li-16 { margin-left: 30px; text-align: left; font-family: Arial; }
.br, .br-2, .br-3, .br-4, .br-5, .br-6, .br-7, .br-8, .br-9, .br-10, .br-11, .br-12, .br-13, .br-14, .br-15, .br-16, .br-17, .br-18, .br-19, .br-20, .br-21, .br-22, .br-23, .br-24, .br-25, .br-26, .br-27, .br-28 { display: inline; }
.div-10 { text-align: left; }
.img { margin: 0 auto; vertical-align: middle; }
.div-12 { margin: 10px 0; max-height: 440px; overflow: auto; }
.table { width: 100%; border-collapse: collapse; background: #fff; }
.thead { vertical-align: middle; }
.tr { background: #f2f2f2; transition: .3s; }
.td { border: 1px solid #e6e6e6; padding: 9px 15px; line-height: 20px; }
.div-14 { text-align: left; }
.strong { font-weight: 700; }
.div-41, .div-42 { text-align: justify; }
.strong-2, .strong-3 { font-weight: 700; }
.div-43 { text-align: left; }
.p-2, .p-3 { text-align: left; }
.table-2, .table-3 { width: 100%; border-collapse: collapse; background: #fff; }
.td-2, .td-3, .td-4, .td-5 { border: 1px solid #e6e6e6; padding: 9px 15px; line-height: 20px; }
</style>
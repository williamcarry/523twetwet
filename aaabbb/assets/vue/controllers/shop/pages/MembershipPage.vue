<template>
  <div class="min-h-screen bg-gray-50">
    <SiteHeader />
    
    <div class="container mx-auto max-w-[1500px] px-4 py-8">
      <div class="bg-white rounded-lg p-6 shadow-sm">
        <!-- About Saleyee Platform Section -->
        <section class="mb-8">
          <h3 class="text-xl font-bold text-gray-800 mb-4">{{ t('aboutPlatformTitle') }}</h3>
          <p class="text-gray-600 leading-relaxed">
            {{ t('aboutPlatformContent') }}
          </p>
        </section>

        <!-- Membership Levels Section -->
        <section class="mb-8">
          <h3 class="text-xl font-bold text-gray-800 mb-4">{{ t('membershipLevelsTitle') }}</h3>
          <table class="w-full border-collapse border border-red-600 rounded-lg">
            <thead>
              <tr class="bg-gray-200">
                <th class="border border-red-600 p-3 text-center">{{ t('membershipLevelHeader') }}</th>
                <th class="border border-red-600 p-3 text-center">{{ t('monthlySalesHeader') }}</th>
                <th class="border border-red-600 p-3 text-center">{{ t('memberDiscountHeader') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 p-3 text-center">
                  <span>{{ t('normalMember') }}</span>
                </td>
                <td class="border border-gray-300 p-3 text-center">{{ membershipData.normal_member?.sales || t('incompleteProfile') }}</td>
                <!-- 原有会员折扣代码：不同商品、不同会员等级有不同折扣 -->
                <td v-if="false" class="border border-gray-300 p-3 text-center">{{ formatDiscount(membershipData.normal_member?.discount) || '0%' }}</td>
                <td class="border border-gray-300 p-3 text-center">
                  <span class="text-gray-600 text-sm">{{ t('differentDiscountsByProduct') }}</span>
                </td>
              </tr>
              <tr v-for="n in 5" :key="n" class="hover:bg-gray-50">
                <td class="border border-gray-300 p-3 text-center">
                  <span class="text-orange-600 font-bold">
                    <b>{{ t('vipLevel') }}</b>{{ n }}
                  </span>
                  <em class="text-xs ml-1">({{ t('vipAbbreviation') }}{{ n }})</em>
                </td>
                <td class="border border-gray-300 p-3 text-center">{{ formatSales(membershipData.membership_levels?.['vip'+n]?.sales) || formatSales(defaultMembershipLevels[n-1].sales) }}</td>
                <!-- 原有会员折扣代码：不同商品、不同会员等级有不同折扣 -->
                <td v-if="false" class="border border-gray-300 p-3 text-center">{{ formatDiscount(membershipData.membership_levels?.['vip'+n]?.discount) || formatDiscount(defaultMembershipLevels[n-1].discount) }}</td>
                <td class="border border-gray-300 p-3 text-center">
                  <span class="text-gray-600 text-sm">{{ t('differentDiscountsByProduct') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </section>

        <!-- Membership Level Explanation -->
        <section class="mb-8">
          <h4 class="text-lg font-bold text-gray-800 mb-4">{{ t('membershipLevelExplanation') }}</h4>
          <div class="text-gray-600 membership-explanation-list">
            <p>
              <strong>{{ t('normalMemberExplanation') }}</strong> {{ t('normalMemberDescription') }}
              <a href="/user-center" class="text-blue-500 hover:underline">
                {{ t('completeProfileLink') }} 
              </a>
            </p>
            <p>
              <strong>{{ t('vipExplanation') }}</strong> {{ t('vipDescription') }}
            </p>
            <p>
              <strong>{{ t('howToUpgrade') }}</strong> {{ t('upgradeDescription') }}
            </p>
            <p>
              <strong>{{ t('upgradePolicy') }}</strong> {{ t('upgradePolicyDescription') }}
            </p>
            <p>
              <strong>{{ t('downgradePolicy') }}</strong> {{ t('downgradePolicyDescription') }}
            </p>
            <p class="text-red-600">
              <strong>{{ t('directShippingNote') }}</strong> {{ t('directShippingDescription') }}
            </p>
            <p class="text-red-600">
              <strong>{{ t('overseasWarehouseNote') }}</strong> {{ t('overseasWarehouseDescription') }}
            </p>
          </div>
        </section>

        <!-- Membership Rights Section -->
        <section class="mb-8">
          <h3 class="text-xl font-bold text-gray-800 mb-4">
            {{ t('membershipRightsTitle') }} 
            <span class="text-sm text-gray-600">{{ t('membershipRightsSubtitle') }}</span>
          </h3>
          <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-red-600 rounded-lg">
              <thead>
                <tr class="bg-red-600 text-white">
                  <th class="border p-3 text-left w-1/6">{{ t('rightsVipLevelHeader') }}</th>
                  <th class="border p-3 text-center">{{ t('normalMemberRights') }}</th>
                  <th class="border p-3 text-center">V1</th>
                  <th class="border p-3 text-center">V2</th>
                  <th class="border p-3 text-center">V3</th>
                  <th class="border p-3 text-center">V4</th>
                  <th class="border p-3 text-center">V5</th>
                  <th class="border p-3 text-center">{{ t('description') }}</th>
                </tr>
              </thead>
              <tbody>
                <!-- 每月下载SKU数行 -->
                <tr class="hover:bg-gray-50">
                  <td class="border p-3 bg-blue-50 font-semibold">{{ t('downloadSkuLimit') }}</td>
                  <td v-for="(value, level) in getDownloadLimitLevels()" :key="level" class="border p-3 text-center">
                    <span>{{ formatDownloadLimit(value) }}</span>
                  </td>
                  <td class="border p-3">/</td>
                </tr>
                
                <!-- 平台载单授权行 -->
                <tr class="hover:bg-gray-50">
                  <td class="border p-3 bg-blue-50 font-semibold">{{ t('platformOrderAuthorization') }}</td>
                  <td v-for="(value, level) in getOrderAuthLevels()" :key="level" class="border p-3 text-center">
                    <img v-if="value === 'yes'" src="/frondend/images/MembershipPage/yes.png" :alt="t('yes')" class="w-4 h-4 mx-auto">
                    <img v-else-if="value === 'no'" src="/frondend/images/MembershipPage/no.png" :alt="t('no')" class="w-4 h-4 mx-auto">
                    <span v-else>{{ value }}</span>
                  </td>
                  <td class="border p-3">{{ t('platformOrderAuthDescription') }}</td>
                </tr>
                
                <!-- API 对接行 -->
                <tr class="hover:bg-gray-50">
                  <td class="border p-3 bg-blue-50 font-semibold">{{ t('apiIntegration') }}</td>
                  <td v-for="(value, level) in getApiIntegrationLevels()" :key="level" class="border p-3 text-center">
                    <img v-if="value === 'yes'" src="/frondend/images/MembershipPage/yes.png" :alt="t('yes')" class="w-4 h-4 mx-auto">
                    <img v-else-if="value === 'no'" src="/frondend/images/MembershipPage/no.png" :alt="t('no')" class="w-4 h-4 mx-auto">
                    <span v-else>{{ value }}</span>
                  </td>
                  <td class="border p-3">{{ t('apiIntegrationDescription') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Registration Call to Action -->
        <section class="text-center py-8">
          <h4 class="text-xl font-bold text-gray-800 mb-4">{{ t('registrationCallToAction') }}</h4>
          <p class="text-gray-600 mb-6">{{ isLoggedIn ? t('goToUserCenter') : t('registrationDescription') }}</p>
          <a 
            :href="isLoggedIn ? '/user-center' : '/register'" 
            class="bg-red-600 text-white px-8 py-3 rounded-lg hover:bg-red-700 transition-colors inline-block"
          >
            {{ isLoggedIn ? t('goToUserCenterButton') : t('freeRegistration') }}
          </a>
        </section>
      </div>
    </div>

    <SiteFooter />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import SiteHeader from '@/components/SiteHeader.vue'
import SiteFooter from '@/components/SiteFooter.vue'

// 从 window 对象获取 store 实例
const store = window.vueStore

// 获取用户登录状态
const isLoggedIn = computed(() => store?.state?.isLoggedIn || false)

// 页面翻译数据
const translations = ref({})

// 当前语言
const currentLang = ref('zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/MembershipPage.json')
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

// 会员等级数据
const membershipData = ref({})

// 会员权益数据
const membershipRights = ref([])

// 默认会员等级数据
const defaultMembershipLevels = [
  { level: 'VIP1', sales: '', discount: '' },
  { level: 'VIP2', sales: '', discount: '' },
  { level: 'VIP3', sales: '', discount: '' },
  { level: 'VIP4', sales: '', discount: '' },
  { level: 'VIP5', sales: '', discount: '' }
]

// 货币符号，默认$
const currencySymbol = ref('$')

// 格式化销售额显示
const formatSales = (sales) => {
  if (!sales) return ''
  
  // 如果已经是格式化后的数据，直接返回
  if (sales.includes('≥ ' + currencySymbol.value) || sales.includes('≥' + currencySymbol.value) || sales.includes(t('incompleteProfile'))) {
    return sales
  }
  
  // 如果是数字，添加货币符号和大于等于符号
  if (sales.startsWith('>=')) {
    return '≥ ' + currencySymbol.value + sales.substring(2)
  } else if (sales.startsWith('>')) {
    return '≥ ' + currencySymbol.value + sales.substring(1)
  } else if (!isNaN(sales)) {
    return '≥ ' + currencySymbol.value + sales
  }
  
  return sales
}

// 格式化折扣显示
const formatDiscount = (discount) => {
  if (!discount) return ''
  
  // 如果已经是百分比格式，直接返回
  if (discount.includes('%')) {
    return discount
  }
  
  // 如果是小数，转换为百分比
  if (!isNaN(discount)) {
    return (parseFloat(discount) * 100) + '%'
  }
  
  // 如果是带小数点的格式，转换为百分比
  if (discount.includes('.')) {
    const num = parseFloat(discount)
    if (!isNaN(num)) {
      return (num * 100) + '%'
    }
  }
  
  return discount + '%'
}

// 格式化下载SKU数显示
const formatDownloadLimit = (limit) => {
  if (!limit) return ''
  // 如果已经是带"个"的格式，直接返回
  if (limit.includes(t('items'))) {
    return limit
  }
  // 添加"个"字
  return limit + t('items')
}

// 获取下载限制级别数据
const getDownloadLimitLevels = () => {
  if (membershipRights.value && membershipRights.value.downloadLimits) {
    return membershipRights.value.downloadLimits;
  }
  // 默认数据
  return {
    normal: '',
    v1: '',
    v2: '',
    v3: '',
    v4: '',
    v5: ''
  };
};

// 获取订单授权级别数据
const getOrderAuthLevels = () => {
  if (membershipRights.value && membershipRights.value.orderAuthorization) {
    return membershipRights.value.orderAuthorization;
  }
  // 默认数据
  return {
    normal: 'yes',
    v1: 'yes',
    v2: 'yes',
    v3: 'yes',
    v4: 'yes',
    v5: 'yes'
  };
};

// 获取API集成级别数据
const getApiIntegrationLevels = () => {
  if (membershipRights.value && membershipRights.value.apiIntegration) {
    return membershipRights.value.apiIntegration;
  }
  // 默认数据
  return {
    normal: 'no',
    v1: 'no',
    v2: 'no',
    v3: 'yes',
    v4: 'yes',
    v5: 'yes'
  };
};

// 获取会员权益数据
const fetchMembershipData = async () => {
  try {
    const response = await fetch('/shop/api/membership/benefits')
    const result = await response.json()
    
    if (result.success) {
      membershipData.value = result.data
      
      // 获取货币符号
      if (result.data.currencySymbol) {
        currencySymbol.value = result.data.currencySymbol
      }
      
      // 如果返回了会员权益数据，设置会员权益
      if (result.data.membership_rights) {
        membershipRights.value = result.data.membership_rights
      }
      
      // 如果没有会员权益数据，构建默认数据
      if (!result.data.membership_rights) {
        // 构建每月下载SKU数权益数据
        const downloadLimits = {
          normal: formatDownloadLimit(result.data.normal_member?.download_limit || ''),
          v1: formatDownloadLimit(result.data.membership_levels?.vip1?.download_limit || ''),
          v2: formatDownloadLimit(result.data.membership_levels?.vip2?.download_limit || ''),
          v3: formatDownloadLimit(result.data.membership_levels?.vip3?.download_limit || ''),
          v4: formatDownloadLimit(result.data.membership_levels?.vip4?.download_limit || ''),
          v5: formatDownloadLimit(result.data.membership_levels?.vip5?.download_limit || '')
        };
        
        membershipRights.value = {
          downloadLimits: downloadLimits,
          orderAuthorization: { 
            normal: 'yes', 
            v1: 'yes', 
            v2: 'yes', 
            v3: 'yes', 
            v4: 'yes', 
            v5: 'yes' 
          },
          apiIntegration: { 
            normal: 'no', 
            v1: 'no', 
            v2: 'no', 
            v3: 'yes', 
            v4: 'yes', 
            v5: 'yes' 
          }
        };
      }
    }
    // 如果API调用失败，将继续使用默认数据
  } catch (err) {
    console.error('获取会员数据失败:', err)
    // 如果API调用失败，将继续使用默认数据
  }
}

// 组件挂载时获取数据
onMounted(() => {
  // 初始加载翻译
  loadTranslations().then(() => {
    // 翻译加载完成后设置标题
    updatePageTitle()
  })
  fetchMembershipData()
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

// 组件卸载前移除事件监听器
// onBeforeUnmount(() => {
//   window.removeEventListener('languagechange', handleLangChange)
// })
</script>

<style scoped>
.container {
  max-width: 1500px;
  min-width: 1200px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：space-y-3 -> margin */
.membership-explanation-list > *:not(:last-child) {
  margin-bottom: 0.75rem; /* 3 * 0.25rem = 0.75rem */
}
</style>
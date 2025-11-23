<template>
  <div class="security-page-container">
    <!-- 更改密码卡片 -->
    <div class="bg-white rounded-b-lg border-x border-b border-slate-200 p-6">
      <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-slate-900">{{ t('passwordTitle') }}</h3>
        <span class="text-sm text-slate-600">{{ t('passwordDescription') }}</span>
      </div>

      <form class="max-w-lg password-form">
        <div>
          <label class="block text-sm font-medium text-slate-900 mb-2">{{ t('labelOldPassword') }}</label>
          <input
            v-model="passwordForm.oldPassword"
            type="password"
            :placeholder="t('placeholderOldPassword')"
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-900 mb-2">{{ t('labelNewPassword') }}</label>
          <input
            v-model="passwordForm.newPassword"
            type="password"
            :placeholder="t('placeholderNewPassword')"
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
          />
          <div class="text-xs text-slate-600 mt-3 password-requirements">
            <div :class="passwordStrength.length ? 'text-green-600' : ''">{{ t('passwordRequirementLength') }}</div>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-900 mb-2">{{ t('labelConfirmPassword') }}</label>
          <input
            v-model="passwordForm.confirmPassword"
            type="password"
            :placeholder="t('placeholderConfirmPassword')"
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
          />
        </div>

        <button
          @click="handleChangePassword"
          type="button"
          :disabled="isChangingPassword"
          class="px-6 py-2 border border-primary text-primary rounded-lg hover:bg-red-50 transition text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ isChangingPassword ? t('buttonChangingPassword') : t('buttonChangePassword') }}
        </button>
      </form>
    </div>

    <!-- 邮箱验证卡片 -->
    <div class="bg-white rounded-lg border border-slate-200 p-6">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h3 class="text-lg font-semibold text-slate-900">{{ t('emailTitle') }}</h3>
          <p class="text-sm text-slate-600 mt-1">
            {{ t('emailDescription') }}
          </p>
        </div>
        <div class="text-right">
          <p class="text-sm font-medium text-slate-900">{{ emailMasked }}</p>
          <p class="text-xs text-slate-600 mt-1">{{ t('currentEmail') }}</p>
        </div>
      </div>

      <form class="max-w-lg email-form">
        <div>
          <label class="block text-sm font-medium text-slate-900 mb-2">{{ t('labelNewEmail') }}</label>
          <input
            v-model="emailForm.newEmail"
            type="email"
            :placeholder="t('placeholderNewEmail')"
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-900 mb-2">{{ t('labelEmailCode') }}</label>
          <div class="flex email-code-input">
            <input
              v-model="emailForm.code"
              type="text"
              :placeholder="t('placeholderEmailCode')"
              maxlength="6"
              class="flex-1 px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
            <button
              @click="handleSendEmailCode"
              type="button"
              :disabled="emailCodeCountdown > 0 || isSendingEmailCode"
              class="px-6 py-2 border border-primary text-primary rounded-lg hover:bg-red-50 transition text-sm font-medium whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ emailCodeCountdown > 0 ? t('buttonRetryAfter', { seconds: emailCodeCountdown }) : (isSendingEmailCode ? t('buttonSending') : t('buttonSendCode')) }}
            </button>
          </div>
        </div>
        <div>
          <button
            @click="handleChangeEmail"
            type="button"
            :disabled="isChangingEmail"
            class="px-6 py-2 border border-primary text-primary rounded-lg hover:bg-red-50 transition text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ isChangingEmail ? t('buttonSaving') : t('buttonSave') }}
          </button>
        </div>
      </form>
    </div>

    <!-- 手机号验证卡片 -->
    <div class="bg-white rounded-lg border border-slate-200 p-6">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h3 class="text-lg font-semibold text-slate-900">{{ t('mobileTitle') }}</h3>
          <p class="text-sm text-slate-600 mt-1">
            {{ t('mobileDescription') }}
          </p>
        </div>
        <div class="text-right">
          <p class="text-sm font-medium text-slate-900">{{ mobileMasked }}</p>
          <p class="text-xs text-slate-600 mt-1">{{ t('currentMobile') }}</p>
        </div>
      </div>

      <form class="max-w-lg mobile-form">
        <div>
          <label class="block text-sm font-medium text-slate-900 mb-2">{{ t('labelNewMobile') }}</label>
          <input
            v-model="mobileForm.newMobile"
            type="tel"
            :placeholder="t('placeholderNewMobile')"
            class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-900 mb-2">{{ t('labelMobileCode') }}</label>
          <div class="flex mobile-code-input">
            <input
              v-model="mobileForm.code"
              type="text"
              :placeholder="t('placeholderMobileCode')"
              maxlength="6"
              class="flex-1 px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
            <button
              @click="handleSendMobileCode"
              type="button"
              :disabled="mobileCodeCountdown > 0 || isSendingMobileCode"
              class="px-6 py-2 border border-primary text-primary rounded-lg hover:bg-red-50 transition text-sm font-medium whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ mobileCodeCountdown > 0 ? t('buttonRetryAfter', { seconds: mobileCodeCountdown }) : (isSendingMobileCode ? t('buttonSending') : t('buttonSendCode')) }}
            </button>
          </div>
        </div>

        <div>
          <button
            @click="handleChangeMobile"
            type="button"
            :disabled="isChangingMobile"
            class="px-6 py-2 border border-primary text-primary rounded-lg hover:bg-red-50 transition text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ isChangingMobile ? t('buttonSaving') : t('buttonSave') }}
          </button>
        </div>
      </form>
    </div>

    <!-- 安全建议 -->
    <div class="rounded-lg p-6 bg-yellow-50 border border-yellow-200">
      <h4 class="font-semibold mb-3 text-yellow-900">{{ t('securityTipsTitle') }}</h4>
      <ul class="text-sm text-yellow-900 security-tips-list">
        <li>{{ t('securityTip1') }}</li>
        <li>{{ t('securityTip2') }}</li>
        <li>{{ t('securityTip3') }}</li>
        <li>{{ t('securityTip4') }}</li>
        <li>{{ t('securityTip5') }}</li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import encryptionService from '../data/encryption-service.js'
import apiSignature from '../services/apiSignature.js'

// 获取store实例
const store = window.vueStore

// 页面翻译数据
const translations = ref({})

// 当前语言 - 使用ref以便能响应事件更新
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/SecurityPage.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    // 翻译文件加载失败时静默处理
  }
}

// 翻译函数 - 直接从页面特定的JSON文件读取
const t = (key, params = {}) => {
  // 获取当前语言，优先从localStorage获取，否则使用默认值
  const lang = localStorage.getItem('app.lang') || currentLang.value
  
  // 从页面特定的翻译文件中获取翻译
  let text = key
  if (translations.value[lang] && translations.value[lang][key]) {
    text = translations.value[lang][key]
  }
  
  // 替换参数
  Object.keys(params).forEach(paramKey => {
    text = text.replace(`{${paramKey}}`, params[paramKey])
  })
  
  return text
}

// 监听语言变化事件
const handleLangChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  }
  // 重新加载翻译以确保语言切换时更新
  loadTranslations()
}

// 获取用户信息
const user = computed(() => store?.state?.user || {})

// 从store中获取并脱敏显示
const emailMasked = computed(() => {
  const email = user.value.email
  if (!email) return t('notSet')
  
  const parts = email.split('@')
  if (parts.length !== 2) return email
  
  const username = parts[0]
  const domain = parts[1]
  const len = username.length
  
  if (len <= 2) {
    return username[0] + '***@' + domain
  }
  
  return username[0] + '*'.repeat(Math.min(len - 2, 4)) + username[len - 1] + '@' + domain
})

const mobileMasked = computed(() => {
  const mobile = user.value.mobile
  if (!mobile) return t('notSet')
  
  const len = mobile.length
  if (len <= 7) {
    return mobile.substring(0, 3) + '****'
  }
  
  return mobile.substring(0, 3) + '****' + mobile.substring(len - 4)
})

// 密码表单
const passwordForm = reactive({
  oldPassword: '',
  newPassword: '',
  confirmPassword: '',
})

// 邮箱表单
const emailForm = reactive({
  newEmail: '',
  code: ''
})

// 手机号表单
const mobileForm = reactive({
  newMobile: '',
  code: ''
})

// 加载状态
const isChangingPassword = ref(false)
const isChangingEmail = ref(false)
const isChangingMobile = ref(false)
const isSendingEmailCode = ref(false)
const isSendingMobileCode = ref(false)

// 验证码倒计时
const emailCodeCountdown = ref(0)
const mobileCodeCountdown = ref(0)
let emailCountdownTimer = null
let mobileCountdownTimer = null

// 计算密码强度
const passwordStrength = computed(() => {
  const pwd = passwordForm.newPassword
  return {
    length: pwd.length >= 8,
    uppercase: /[A-Z]/.test(pwd),
    lowercase: /[a-z]/.test(pwd),
    number: /[0-9]/.test(pwd),
  }
})

// 更改密码
async function handleChangePassword() {
  if (!passwordForm.oldPassword) {
    ElMessage.warning(t('warningEnterOldPassword'))
    return
  }
  if (!passwordForm.newPassword) {
    ElMessage.warning(t('warningEnterNewPassword'))
    return
  }
  if (passwordForm.newPassword.length < 8) {
    ElMessage.warning(t('warningPasswordLength'))
    return
  }
  if (passwordForm.newPassword !== passwordForm.confirmPassword) {
    ElMessage.warning(t('warningPasswordMismatch'))
    return
  }

  if (isChangingPassword.value) return
  isChangingPassword.value = true

  console.log('🔒 [SecurityPage] 开始修改密码流程')

  try {
    // 准备数据并加密
    const requestData = {
      oldPassword: passwordForm.oldPassword,
      newPassword: passwordForm.newPassword,
      confirmPassword: passwordForm.confirmPassword
    }
    console.log('📦 [SecurityPage] 原始请求数据:', { ...requestData, oldPassword: '***', newPassword: '***', confirmPassword: '***' })
    
    const encryptedData = encryptionService.prepareData(requestData, true)
    console.log('🔐 [SecurityPage] 加密后数据:', encryptedData)
    
    // 生成签名
    console.log('✍️ [SecurityPage] 准备生成签名...')
    const signedData = apiSignature.sign(encryptedData)
    console.log('✅ [SecurityPage] 签名数据生成完成:', signedData)

    console.log('🚀 [SecurityPage] 发送请求到 /shop/api/account-security/change-password')
    const response = await fetch('/shop/api/account-security/change-password', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })

    console.log('📥 [SecurityPage] 收到响应:', { status: response.status, statusText: response.statusText })
    const result = await response.json()
    console.log('📄 [SecurityPage] 响应数据:', result)

    if (result.success) {
      ElMessage.success(result.message || t('successPasswordChanged'))
      // 清空表单
      passwordForm.oldPassword = ''
      passwordForm.newPassword = ''
      passwordForm.confirmPassword = ''
    } else {
      // 根据当前语言显示错误信息
      const errorMsg = currentLang.value.startsWith('en') 
        ? (result.messageEn || result.message || t('errorPasswordChangeFailed'))
        : (result.message || t('errorPasswordChangeFailed'))
      ElMessage.error(errorMsg)
    }
  } catch (error) {
    ElMessage.error(t('errorOperationFailed'))
  } finally {
    isChangingPassword.value = false
  }
}

// 发送邮箱验证码
async function handleSendEmailCode() {
  if (!emailForm.newEmail) {
    ElMessage.warning(t('warningEnterEmail'))
    return
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailForm.newEmail)) {
    ElMessage.warning(t('warningInvalidEmail'))
    return
  }

  if (isSendingEmailCode.value) return
  isSendingEmailCode.value = true

  try {
    const requestData = {
      email: emailForm.newEmail,
      locale: 'zh_CN'
    }
    
    // 生成签名
    const signedData = apiSignature.sign(requestData)

    const response = await fetch('/shop/api/account-security/send-email-code', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })

    const result = await response.json()

    if (result.success) {
      ElMessage.success(currentLang.value.startsWith('en') ? (result.messageEn || result.message) : result.message || t('successEmailCodeSent'))
      // 开始倒计时
      startEmailCountdown()
    } else {
      const errorMsg = currentLang.value.startsWith('en') 
        ? (result.messageEn || result.message || t('errorEmailCodeFailed'))
        : (result.message || t('errorEmailCodeFailed'))
      ElMessage.error(errorMsg)
    }
  } catch (error) {
    ElMessage.error(t('errorEmailCodeFailed'))
  } finally {
    isSendingEmailCode.value = false
  }
}

// 更改邮箱
async function handleChangeEmail() {
  if (!emailForm.newEmail) {
    ElMessage.warning(t('warningEnterNewEmail'))
    return
  }
  if (!emailForm.code) {
    ElMessage.warning(t('warningEnterEmailCode'))
    return
  }
  if (emailForm.code.length !== 6) {
    ElMessage.warning(t('warningInvalidCodeLength'))
    return
  }

  if (isChangingEmail.value) return
  isChangingEmail.value = true

  try {
    // 准备数据并加密
    const requestData = {
      newEmail: emailForm.newEmail,
      code: emailForm.code
    }
    const encryptedData = encryptionService.prepareData(requestData, true)
    
    // 生成签名
    const signedData = apiSignature.sign(encryptedData)

    const response = await fetch('/shop/api/account-security/change-email', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })

    const result = await response.json()

    if (result.success) {
      const successMsg = currentLang.value.startsWith('en') 
        ? (result.messageEn || result.message || t('successEmailChanged'))
        : (result.message || t('successEmailChanged'))
      ElMessage.success(successMsg)
      // 更新store中的用户信息
      if (result.data && result.data.email) {
        store.commit('UPDATE_USER', { email: result.data.email })
      }
      // 清空表单
      emailForm.newEmail = ''
      emailForm.code = ''
    } else {
      const errorMsg = currentLang.value.startsWith('en') 
        ? (result.messageEn || result.message || t('errorEmailChangeFailed'))
        : (result.message || t('errorEmailChangeFailed'))
      ElMessage.error(errorMsg)
    }
  } catch (error) {
    ElMessage.error(t('errorOperationFailed'))
  } finally {
    isChangingEmail.value = false
  }
}

// 发送手机验证码
async function handleSendMobileCode() {
  if (!mobileForm.newMobile) {
    ElMessage.warning(t('warningEnterMobile'))
    return
  }
  if (!/^1[3-9]\d{9}$/.test(mobileForm.newMobile)) {
    ElMessage.warning(t('warningInvalidMobile'))
    return
  }

  if (isSendingMobileCode.value) return
  isSendingMobileCode.value = true

  try {
    const requestData = {
      mobile: mobileForm.newMobile
    }
    
    // 生成签名
    const signedData = apiSignature.sign(requestData)

    const response = await fetch('/shop/api/account-security/send-mobile-code', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })

    const result = await response.json()

    if (result.success) {
      const successMsg = currentLang.value.startsWith('en') 
        ? (result.messageEn || result.message || t('successMobileCodeSent'))
        : (result.message || t('successMobileCodeSent'))
      ElMessage.success(successMsg)
      // 开始倒计时
      startMobileCountdown()
    } else {
      const errorMsg = currentLang.value.startsWith('en') 
        ? (result.messageEn || result.message || t('errorMobileCodeFailed'))
        : (result.message || t('errorMobileCodeFailed'))
      ElMessage.error(errorMsg)
    }
  } catch (error) {
    ElMessage.error(t('errorMobileCodeFailed'))
  } finally {
    isSendingMobileCode.value = false
  }
}

// 更改手机号
async function handleChangeMobile() {
  if (!mobileForm.newMobile) {
    ElMessage.warning(t('warningEnterNewMobile'))
    return
  }
  if (!mobileForm.code) {
    ElMessage.warning(t('warningEnterMobileCode'))
    return
  }
  if (mobileForm.code.length !== 6) {
    ElMessage.warning(t('warningInvalidCodeLength'))
    return
  }

  if (isChangingMobile.value) return
  isChangingMobile.value = true

  try {
    // 准备数据并加密
    const requestData = {
      newMobile: mobileForm.newMobile,
      code: mobileForm.code
    }
    const encryptedData = encryptionService.prepareData(requestData, true)
    
    // 生成签名
    const signedData = apiSignature.sign(encryptedData)

    const response = await fetch('/shop/api/account-security/change-mobile', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })

    const result = await response.json()

    if (result.success) {
      const successMsg = currentLang.value.startsWith('en') 
        ? (result.messageEn || result.message || t('successMobileChanged'))
        : (result.message || t('successMobileChanged'))
      ElMessage.success(successMsg)
      // 更新store中的用户信息
      if (result.data && result.data.mobile) {
        store.commit('UPDATE_USER', { mobile: result.data.mobile })
      }
      // 清空表单
      mobileForm.newMobile = ''
      mobileForm.code = ''
    } else {
      const errorMsg = currentLang.value.startsWith('en') 
        ? (result.messageEn || result.message || t('errorMobileChangeFailed'))
        : (result.message || t('errorMobileChangeFailed'))
      ElMessage.error(errorMsg)
    }
  } catch (error) {
    ElMessage.error(t('errorOperationFailed'))
  } finally {
    isChangingMobile.value = false
  }
}

// 开始邮箱验证码倒计时
function startEmailCountdown() {
  emailCodeCountdown.value = 60
  if (emailCountdownTimer) {
    clearInterval(emailCountdownTimer)
  }
  emailCountdownTimer = setInterval(() => {
    emailCodeCountdown.value--
    if (emailCodeCountdown.value <= 0) {
      clearInterval(emailCountdownTimer)
      emailCountdownTimer = null
    }
  }, 1000)
}

// 开始手机验证码倒计时
function startMobileCountdown() {
  mobileCodeCountdown.value = 60
  if (mobileCountdownTimer) {
    clearInterval(mobileCountdownTimer)
  }
  mobileCountdownTimer = setInterval(() => {
    mobileCodeCountdown.value--
    if (mobileCodeCountdown.value <= 0) {
      clearInterval(mobileCountdownTimer)
      mobileCountdownTimer = null
    }
  }, 1000)
}

onMounted(() => {
  // 初始加载翻译
  loadTranslations()
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

onBeforeUnmount(() => {
  // 清理定时器
  if (emailCountdownTimer) {
    clearInterval(emailCountdownTimer)
  }
  if (mobileCountdownTimer) {
    clearInterval(mobileCountdownTimer)
  }
  
  // 移除语言变化监听
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */

/* 页面容器 - space-y-6 替代 */
.security-page-container > *:not(:last-child) {
  margin-bottom: 1.5rem; /* 24px */
}

/* 密码表单 - space-y-6 替代 */
.password-form > *:not(:last-child) {
  margin-bottom: 1.5rem; /* 24px */
}

/* 密码要求列表 - space-y-1 替代 */
.password-requirements > *:not(:last-child) {
  margin-bottom: 0.25rem; /* 4px */
}

/* 邮箱表单 - space-y-6 替代 */
.email-form > *:not(:last-child) {
  margin-bottom: 1.5rem; /* 24px */
}

/* 邮箱验证码输入 - gap-2 替代 */
.email-code-input > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 手机表单 - space-y-6 替代 */
.mobile-form > *:not(:last-child) {
  margin-bottom: 1.5rem; /* 24px */
}

/* 手机验证码输入 - gap-2 替代 */
.mobile-code-input > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 安全提示列表 - space-y-2 替代 */
.security-tips-list > *:not(:last-child) {
  margin-bottom: 0.5rem; /* 8px */
}
</style>

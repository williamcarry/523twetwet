<template>
  <div class="bg-white">
    <main class="p-6">
      <!-- 页面标题和操作按钮区域 -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-3xl font-bold text-gray-900">{{ t('pageTitle') }}</h1>
          <p class="mt-2 text-sm text-gray-600">{{ t('pageDescription') }}</p>
        </div>
        
        <!-- 操作按钮组 -->
        <div class="flex items-center action-buttons">
          <button
            @click="handleDeleteSelected"
            :disabled="selectedIds.length === 0"
            class="px-4 py-2 text-red-600 border border-red-300 rounded-md hover:bg-red-50 disabled:opacity-50 disabled:cursor-not-allowed transition text-sm font-medium"
          >
            {{ t('buttonDelete') }}
          </button>
          <button
            @click="handleAddAddress"
            :disabled="addresses.length >= 10"
            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition text-sm font-medium flex items-center add-address-button"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            {{ t('buttonAddAddress') }}
          </button>
        </div>
      </div>

      <!-- 地址数量提示 -->
      <div class="mb-4">
        <p class="text-sm text-gray-600">
          {{ t('addressCount') }}
          <em class="font-semibold text-gray-900">{{ addresses.length }}</em>
          {{ t('addressCountUnit') }}
        </p>
      </div>

      <!-- 地址表格 -->
      <div class="overflow-x-auto border border-gray-200 rounded-lg">
        <table class="w-full border-collapse">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="px-4 py-3 text-left text-sm font-medium text-gray-700 w-12">
                <input
                  type="checkbox"
                  class="w-4 h-4 accent-red-600 rounded"
                  :checked="isAllSelected"
                  @change="toggleSelectAll"
                />
              </th>
              <th class="px-4 py-3 text-left text-sm font-medium text-gray-700 w-32">{{ t('tableHeaderRecipient') }}</th>
              <th class="px-4 py-3 text-left text-sm font-medium text-gray-700 w-40">{{ t('tableHeaderPhone') }}</th>
              <th class="px-4 py-3 text-left text-sm font-medium text-gray-700 flex-1">{{ t('tableHeaderAddress') }}</th>
              <th class="px-4 py-3 text-left text-sm font-medium text-gray-700 w-24">{{ t('tableHeaderZipcode') }}</th>
              <th class="px-4 py-3 text-left text-sm font-medium text-gray-700 w-24">{{ t('tableHeaderLabel') }}</th>
              <th class="px-4 py-3 text-center text-sm font-medium text-gray-700 w-24">{{ t('tableHeaderDefault') }}</th>
              <th class="px-4 py-3 text-center text-sm font-medium text-gray-700 w-32">{{ t('tableHeaderActions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr v-for="addr in addresses" :key="addr.id" class="hover:bg-gray-50">
              <td class="px-4 py-4">
                <input
                  type="checkbox"
                  class="w-4 h-4 accent-red-600 rounded"
                  :checked="selectedIds.includes(addr.id)"
                  @change="toggleAddress(addr.id)"
                />
              </td>
              <td class="px-4 py-4 text-sm text-gray-900">{{ addr.receiverName }}</td>
              <td class="px-4 py-4 text-sm text-gray-900">{{ addr.receiverPhone }}</td>
              <td class="px-4 py-4 text-sm text-gray-900">{{ addr.receiverAddress }}</td>
              <td class="px-4 py-4 text-sm text-gray-900">{{ addr.receiverZipcode }}</td>
              <td class="px-4 py-4 text-sm text-gray-900">{{ addr.addressLabel || '-' }}</td>
              <td class="px-4 py-4 text-center text-sm">
                <span v-if="addr.isDefault" class="text-red-600 font-semibold">{{ t('defaultYes') }}</span>
                <span v-else class="text-gray-500">{{ t('defaultNo') }}</span>
              </td>
              <td class="px-4 py-4 text-center">
                <button
                  @click="handleEditAddress(addr)"
                  class="text-red-600 hover:underline text-sm font-medium"
                >
                  {{ t('actionEdit') }}
                </button>
                <span class="text-gray-300 mx-2">|</span>
                <button
                  @click="handleDeleteAddress(addr.id)"
                  class="text-red-600 hover:underline text-sm font-medium"
                >
                  {{ t('actionDelete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </main>

    <!-- 新增/编辑地址对话框 -->
    <div v-if="dialogVisible" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <!-- 对话框头部 -->
        <div class="flex justify-between items-center p-6 border-b border-gray-200 bg-gray-50">
          <h3 class="text-lg font-semibold text-gray-900">
            {{ isEditing ? t('dialogTitleEdit') : t('dialogTitleAdd') }}
          </h3>
          <button
            @click="dialogVisible = false"
            class="text-gray-500 hover:text-gray-700 transition"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- 对话框内容 -->
        <div class="p-6 dialog-content">
          <!-- 收货人姓名（必填） -->
          <div>
            <label class="block text-sm font-medium text-gray-900 mb-2">
              <span class="text-red-500">*</span> {{ t('labelRecipientName') }}
            </label>
            <input
              v-model="formData.receiver_name"
              type="text"
              :placeholder="t('placeholderRecipientName')"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent"
            />
          </div>

          <!-- 收货人电话（必填） -->
          <div>
            <label class="block text-sm font-medium text-gray-900 mb-2">
              <span class="text-red-500">*</span> {{ t('labelRecipientPhone') }}
            </label>
            <input
              v-model="formData.receiver_phone"
              type="tel"
              :placeholder="t('placeholderRecipientPhone')"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent"
            />
          </div>

          <!-- 收货详细地址（必填） -->
          <div>
            <label class="block text-sm font-medium text-gray-900 mb-2">
              <span class="text-red-500">*</span> {{ t('labelDetailAddress') }}
            </label>
            <textarea
              v-model="formData.receiver_address"
              :placeholder="t('placeholderDetailAddress')"
              rows="3"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none"
            ></textarea>
          </div>

          <!-- 收货邮编（必填） -->
          <div>
            <label class="block text-sm font-medium text-gray-900 mb-2">
              <span class="text-red-500">*</span> {{ t('labelZipcode') }}
            </label>
            <input
              v-model="formData.receiver_zipcode"
              type="text"
              :placeholder="t('placeholderZipcode')"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent"
            />
          </div>

          <!-- 地址标签 -->
          <div>
            <label class="block text-sm font-medium text-gray-900 mb-2">{{ t('labelAddressTag') }}</label>
            <select
              v-model="formData.address_label"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent bg-white"
            >
              <option value="">{{ t('placeholderSelectLabel') }}</option>
              <option value="家">{{ t('labelHome') }}</option>
              <option value="公司">{{ t('labelCompany') }}</option>
              <option value="学校">{{ t('labelSchool') }}</option>
              <option value="其他">{{ t('labelOther') }}</option>
            </select>
          </div>

          <!-- 是否为默认地址 -->
          <div class="flex items-center default-checkbox">
            <label class="flex items-center cursor-pointer checkbox-label">
              <input
                type="checkbox"
                v-model="formData.is_default"
                :disabled="isEditingDefaultAddress"
                class="w-4 h-4 accent-red-600 rounded"
              />
              <span class="text-sm text-gray-700">{{ t('labelSetDefault') }}</span>
              <span v-if="isEditingDefaultAddress" class="text-xs text-gray-500">{{ t('labelKeepDefault') }}</span>
            </label>
          </div>
        </div>

        <!-- 对话框底部 -->
        <div class="flex justify-end p-6 border-t border-gray-200 bg-gray-50 dialog-footer">
          <button
            @click="dialogVisible = false"
            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-100 transition text-sm font-medium"
          >
            {{ t('buttonCancel') }}
          </button>
          <button
            @click="handleSaveAddress"
            class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm font-medium"
          >
            {{ t('buttonSave') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import apiSignature from '../services/apiSignature.js'

const dialogVisible = ref(false)
const isEditing = ref(false)
const selectedIds = ref([])
const editingId = ref(null)
const loading = ref(false)

// 页面翻译数据
const translations = ref({})

// 当前语言 - 使用ref以便能响应事件更新
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/AddressBookPage.json')
    const data = await response.json()
    translations.value = data
  } catch (error) {
    console.error('Failed to load translations:', error)
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

const formData = reactive({
  receiver_name: '',
  receiver_phone: '',
  receiver_address: '',
  receiver_zipcode: '',
  address_label: '',
  is_default: false,
})

// 地址数据
const addresses = ref([])

// 是否正在编辑默认地址
const isEditingDefaultAddress = computed(() => {
  if (!isEditing.value || !editingId.value) return false
  const editingAddress = addresses.value.find(addr => addr.id === editingId.value)
  return editingAddress?.isDefault === true
})

const isAllSelected = computed(() => {
  return addresses.value.length > 0 && selectedIds.value.length === addresses.value.length
})

function resetForm() {
  formData.receiver_name = ''
  formData.receiver_phone = ''
  formData.receiver_address = ''
  formData.receiver_zipcode = ''
  formData.address_label = ''
  formData.is_default = false
  editingId.value = null
  isEditing.value = false
}

function handleAddAddress() {
  if (addresses.value.length >= 10) {
    ElMessage.warning(t('warningMaxAddress'))
    return
  }
  resetForm()
  dialogVisible.value = true
}

function handleEditAddress(row) {
  formData.receiver_name = row.receiverName
  formData.receiver_phone = row.receiverPhone
  formData.receiver_address = row.receiverAddress
  formData.receiver_zipcode = row.receiverZipcode
  formData.address_label = row.addressLabel
  formData.is_default = row.isDefault
  editingId.value = row.id
  isEditing.value = true
  dialogVisible.value = true
}

async function handleSaveAddress() {
  if (!formData.receiver_name || !formData.receiver_phone || !formData.receiver_address || !formData.receiver_zipcode) {
    ElMessage.warning(t('warningFillRequired'))
    return
  }

  loading.value = true
  
  try {
    if (isEditing.value && editingId.value) {
      // 更新地址
      await updateAddress(editingId.value)
    } else {
      // 创建新地址
      await createAddress()
    }
    
    dialogVisible.value = false
    resetForm()
    // 刷新地址列表
    await fetchAddresses()
  } catch (error) {
    console.error('保存地址失败:', error)
  } finally {
    loading.value = false
  }
}

async function handleDeleteAddress(id) {
  // 检查是否是默认地址
  const address = addresses.value.find(addr => addr.id === id)
  const isDefault = address?.isDefault
  
  const message = isDefault 
    ? t('confirmDeleteDefault')
    : t('confirmDelete')
  
  try {
    await ElMessageBox.confirm(message, t('confirmDeleteTitle'), {
      confirmButtonText: t('confirmButtonText'),
      cancelButtonText: t('cancelButtonText'),
      type: 'warning',
    })
    
    await deleteAddress(id)
    await fetchAddresses()
  } catch (error) {
    if (error !== 'cancel') {
      console.error('删除地址失败:', error)
    }
  }
}

async function handleDeleteSelected() {
  if (selectedIds.value.length === 0) {
    ElMessage.warning(t('warningSelectFirst'))
    return
  }

  try {
    await ElMessageBox.confirm(t('confirmDeleteBatch', { count: selectedIds.value.length }), t('confirmDeleteTitle'), {
      confirmButtonText: t('confirmButtonText'),
      cancelButtonText: t('cancelButtonText'),
      type: 'warning',
    })
    
    // 批量删除
    for (const id of selectedIds.value) {
      await deleteAddress(id)
    }
    
    selectedIds.value = []
    await fetchAddresses()
  } catch (error) {
    if (error !== 'cancel') {
      console.error('批量删除失败:', error)
    }
  }
}

function toggleAddress(id) {
  const index = selectedIds.value.indexOf(id)
  if (index > -1) {
    selectedIds.value.splice(index, 1)
  } else {
    selectedIds.value.push(id)
  }
}

function toggleSelectAll() {
  if (isAllSelected.value) {
    selectedIds.value = []
  } else {
    selectedIds.value = addresses.value.map((addr) => addr.id)
  }
}

// ==================== API调用函数 ====================

// 获取地址列表
async function fetchAddresses() {
  try {
    const signedData = apiSignature.sign({})
    const queryParams = new URLSearchParams({
      timestamp: signedData.timestamp.toString(),
      nonce: signedData.nonce,
      signature: signedData.signature
    })
    
    const response = await fetch(`/shop/api/customer/address/list?${queryParams.toString()}`, {
      method: 'GET',
      credentials: 'include'
    })
    
    const data = await response.json()
    
    if (data.success) {
      addresses.value = data.data
    } else {
      // 根据当前语言显示错误信息
      const errorMsg = currentLang.value.startsWith('en') 
        ? (data.messageEn || data.message || t('errorFetchFailed'))
        : (data.message || t('errorFetchFailed'))
      ElMessage.error(errorMsg)
    }
  } catch (error) {
    console.error('获取地址列表失败:', error)
    ElMessage.error(t('errorFetchFailed'))
  }
}

// 创建地址
async function createAddress() {
  try {
    const requestData = {
      receiverName: formData.receiver_name,
      receiverPhone: formData.receiver_phone,
      receiverAddress: formData.receiver_address,
      receiverZipcode: formData.receiver_zipcode,
      addressLabel: formData.address_label || null,
      isDefault: formData.is_default
    }
    
    const signedData = apiSignature.sign(requestData)
    
    const response = await fetch('/shop/api/customer/address/create', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      // 根据当前语言显示成功信息
      const successMsg = currentLang.value.startsWith('en') 
        ? (data.messageEn || data.message || t('successAddressAdded'))
        : (data.message || t('successAddressAdded'))
      ElMessage.success(successMsg)
    } else {
      // 根据当前语言显示错误信息
      const errorMsg = currentLang.value.startsWith('en') 
        ? (data.messageEn || data.message || t('errorAddFailed'))
        : (data.message || t('errorAddFailed'))
      ElMessage.error(errorMsg)
      throw new Error(data.message)
    }
  } catch (error) {
    console.error('创建地址失败:', error)
    throw error
  }
}

// 更新地址
async function updateAddress(id) {
  try {
    const requestData = {
      receiverName: formData.receiver_name,
      receiverPhone: formData.receiver_phone,
      receiverAddress: formData.receiver_address,
      receiverZipcode: formData.receiver_zipcode,
      addressLabel: formData.address_label || null,
      isDefault: formData.is_default
    }
    
    const signedData = apiSignature.sign(requestData)
    
    const response = await fetch(`/shop/api/customer/address/${id}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      // 根据当前语言显示成功信息
      const successMsg = currentLang.value.startsWith('en') 
        ? (data.messageEn || data.message || t('successAddressUpdated'))
        : (data.message || t('successAddressUpdated'))
      ElMessage.success(successMsg)
    } else {
      // 根据当前语言显示错误信息
      const errorMsg = currentLang.value.startsWith('en') 
        ? (data.messageEn || data.message || t('errorUpdateFailed'))
        : (data.message || t('errorUpdateFailed'))
      ElMessage.error(errorMsg)
      throw new Error(data.message)
    }
  } catch (error) {
    console.error('更新地址失败:', error)
    throw error
  }
}

// 删除地址
async function deleteAddress(id) {
  try {
    const signedData = apiSignature.sign({})
    const queryParams = new URLSearchParams({
      timestamp: signedData.timestamp.toString(),
      nonce: signedData.nonce,
      signature: signedData.signature
    })
    
    const response = await fetch(`/shop/api/customer/address/${id}?${queryParams.toString()}`, {
      method: 'DELETE',
      credentials: 'include'
    })
    
    const data = await response.json()
    
    if (!data.success) {
      // 只在失败时显示错误信息
      const errorMsg = currentLang.value.startsWith('en') 
        ? (data.messageEn || data.message || t('errorDeleteFailed'))
        : (data.message || t('errorDeleteFailed'))
      ElMessage.error(errorMsg)
      throw new Error(data.message)
    }
    // 成功时不显示消息，由后端返回的消息处理
  } catch (error) {
    console.error('删除地址失败:', error)
    throw error
  }
}

// 组件挂载时加载地址列表
onMounted(() => {
  // 初始加载翻译
  loadTranslations()
  
  // 加载地址列表
  fetchAddresses()
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

// 组件卸载时移除事件监听器
onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})
</script>

<style scoped>
/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */

/* 操作按钮组 - gap-3 替代 */
.action-buttons > *:not(:last-child) {
  margin-right: 0.75rem; /* 12px */
}

/* 添加地址按钮 - gap-2 替代 */
.add-address-button > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 对话框内容 - space-y-6 替代 */
.dialog-content > *:not(:last-child) {
  margin-bottom: 1.5rem; /* 24px */
}

/* 默认地址复选框 - gap-3 替代 */
.default-checkbox > *:not(:last-child) {
  margin-right: 0.75rem; /* 12px */
}

/* 复选框标签 - gap-2 替代 */
.checkbox-label > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 对话框底部按钮 - gap-2 替代 */
.dialog-footer > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}
</style>

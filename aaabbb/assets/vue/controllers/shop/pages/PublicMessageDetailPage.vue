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
  <div class="bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
    <!-- 加载状态 -->
    <div v-if="loading" class="p-10 text-center text-slate-500">
      <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
      <p class="text-sm mt-4">加载中...</p>
    </div>

    <!-- 内容显示 -->
    <div v-else-if="message" class="p-6 md:p-8">
      <!-- 消息类型标签 -->
      <div class="mb-6">
        <span 
          class="inline-block px-4 py-1.5 rounded-full text-sm font-medium"
          :class="getTypeClass(message.messageType)"
        >
          {{ message.messageTypeText }}
        </span>
      </div>

      <!-- 标题和日期区域 -->
      <div class="border-b border-slate-200 pb-6 mb-8">
        <h1 class="text-2xl md:text-3xl font-bold text-slate-900 mb-4">{{ message.title }}</h1>
        <div class="flex items-center gap-4 text-sm text-slate-500">
          <span>发布时间: {{ formatDateTime(message.sendTime || message.createdAt) }}</span>
        </div>
      </div>

      <!-- 内容区域 -->
      <div class="prose prose-sm max-w-none mb-8">
        <div class="text-slate-700 leading-relaxed" v-html="message.content"></div>
      </div>

      <!-- 署名 -->
      <div class="border-t border-slate-200 pt-6 text-right space-y-2">
        <p class="text-slate-700 font-medium">赛盈分销平台</p>
        <p class="text-slate-600 text-sm">{{ formatDate(message.sendTime || message.createdAt) }}</p>
      </div>

      <!-- 返回按钮 -->
      <div class="flex justify-center mt-12">
        <button
          @click="goBack"
          class="px-8 py-2.5 border-2 border-slate-300 text-slate-600 rounded-lg hover:bg-slate-50 transition font-medium"
        >
          ← 返回列表
        </button>
      </div>
    </div>

    <!-- 错误状态 -->
    <div v-else class="p-10 text-center text-slate-500">
      <div class="text-slate-400 mb-3">
        <svg class="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <p class="text-base">消息不存在或已删除</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, defineEmits } from 'vue'

const props = defineProps({
  messageId: Number
})

const emit = defineEmits(['back'])

const loading = ref(false)
const message = ref(null)

// 加载消息详情
const loadMessageDetail = async () => {
  if (!props.messageId) return
  
  loading.value = true
  try {
    const response = await fetch(`/shop/api/public/messages/detail/${props.messageId}`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json'
      }
    })

    const result = await response.json()

    if (result.success) {
      message.value = result.data
    } else {
      console.error('加载详情失败:', result.error)
    }
  } catch (error) {
    console.error('请求失败:', error)
  } finally {
    loading.value = false
  }
}

const goBack = () => {
  emit('back')
}

// 获取消息类型样式
const getTypeClass = (messageType) => {
  const classes = {
    'mall_announcement': 'bg-blue-100 text-blue-700',
    'platform_message': 'bg-green-100 text-green-700',
  }
  return classes[messageType] || 'bg-slate-100 text-slate-700'
}

// 格式化日期（年/月/日）
const formatDate = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}/${month}/${day}`
}

// 格式化日期时间（年-月-日 时:分）
const formatDateTime = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day} ${hours}:${minutes}`
}

// 组件挂载时加载数据
onMounted(() => {
  loadMessageDetail()
})
</script>

<style scoped></style>

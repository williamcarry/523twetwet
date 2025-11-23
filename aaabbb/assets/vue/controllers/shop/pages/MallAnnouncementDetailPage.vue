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
  <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
    <!-- 加载状态 -->
    <div v-if="loading" class="p-10 text-center text-slate-500">
      加载中...
    </div>

    <!-- 内容显示 -->
    <div v-else-if="message" class="p-6 md:p-8">
      <!-- 标题和日期区域 -->
      <div class="border-b border-slate-200 pb-6 mb-8">
        <h1 class="text-2xl font-bold text-slate-900 text-center mb-4">{{ message.title }}</h1>
        <p class="text-center text-slate-500">{{ formatDate(message.sendTime || message.createdAt) }}</p>
      </div>

      <!-- 内容区域 -->
      <div class="prose prose-sm max-w-none mb-8">
        <div class="text-slate-700 leading-relaxed" v-html="message.content"></div>
      </div>

      <!-- 署名 -->
      <div class="text-right space-y-2">
        <p class="text-slate-700 font-medium">赛盈分销平台</p>
        <p class="text-slate-600">{{ formatDate(message.sendTime || message.createdAt) }}</p>
      </div>

      <!-- 返回按钮 -->
      <div class="flex justify-center mt-12">
        <button
          @click="goBack"
          class="px-8 py-2 border border-slate-400 text-slate-500 rounded hover:bg-slate-50 transition font-medium"
        >
          返回
        </button>
      </div>
    </div>

    <!-- 错误状态 -->
    <div v-else class="p-10 text-center text-slate-500">
      消息不存在或已删除
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, defineEmits } from 'vue'

// 获取store实例
const store = window.vueStore

const props = defineProps({
  announcementId: Number
})

const emit = defineEmits(['back'])

const loading = ref(false)
const message = ref(null)

// 加载消息详情
const loadMessageDetail = async () => {
  if (!props.announcementId) return
  
  loading.value = true
  try {
    const response = await fetch(`/shop/api/message-center/detail/${props.announcementId}`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include'
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

const formatDate = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}/${month}/${day}`
}

// 组件挂载时加载数据
onMounted(() => {
  loadMessageDetail()
})
</script>

<style scoped></style>

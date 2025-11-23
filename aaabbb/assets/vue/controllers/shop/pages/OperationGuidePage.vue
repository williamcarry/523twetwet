<template>
  <HelpLayout 
    page-title="操作指引"
    sidebar-title="操作指引"
    :nav-data="navData"
    :selected-sub-category="selectedSubCategory"
    :selected-sub-category-id="selectedSubCategoryId"
    :selected-category-name="selectedCategoryName"
    :paginated-questions="paginatedQuestions"
    :search-results="searchResults"
    :search-query="searchQuery"
    :current-page="currentPage"
    :total-pages="totalPages"
    :is-open="isOpen"
    :toggle="toggle"
    :on-select-sub-category="selectSubCategory"
    :get-faq-link="getFaqLink"
    :change-page="changePage"
    @search="handleSearch"
  >
    <template #content="{ selectedSubCategory, selectedCategoryName, paginatedQuestions, searchResults, searchQuery, getFaqLink, totalPages, currentPage, changePage }">
      <!-- 搜索加载动画（只在搜索时显示） -->
      <div v-if="searchLoading" class="search-loading">
        <div class="loading-spinner"></div>
        <p class="loading-text">搜索中...</p>
      </div>
      
      <!-- 问题详情区域 -->
      <div v-else-if="selectedQuestion">
        <!-- 面包屑导航 -->
        <div class="breadcrumb">
          <a href="/operation-guide" class="breadcrumb-link">操作指引</a>
          <span class="breadcrumb-separator">></span>
          <a href="javascript:void(0)" class="breadcrumb-link" @click="backToQuestionList">{{ selectedCategoryName }}</a>
          <span class="breadcrumb-separator">></span>
          <a href="javascript:void(0)" class="breadcrumb-link" @click="backToQuestionList" v-if="selectedSubCategory">{{ selectedSubCategory.name }}</a>
          <span class="breadcrumb-separator" v-if="selectedSubCategory">></span>
          <span class="breadcrumb-current">{{ selectedQuestion.question }}</span>
        </div>
        
        <!-- 问题详情内容 -->
        <div class="question-detail-container">
          <div class="question-header">
            <h1 class="question-title">{{ selectedQuestion.question }}</h1>
            
            <div class="question-meta">
              <span class="meta-item">发布时间：{{ selectedQuestion.createdAt }}</span>
              <span class="meta-item">浏览次数：{{ selectedQuestion.viewCount }}</span>
            </div>
          </div>
          
          <div class="question-content" v-html="selectedQuestion.content"></div>
          
          <!-- 问题相关图片 -->
          <div v-if="selectedQuestion.images && selectedQuestion.images.length > 0" class="question-images">
            <div 
              v-for="(image, index) in selectedQuestion.images" 
              :key="index" 
              class="question-image-item"
            >
              <img :src="image" :alt="`问题图片${index + 1}`" class="question-image" @click="showImagePreview(image)" />
            </div>
          </div>
          
          <!-- 反馈区域 -->
          <div class="question-feedback">
            <p class="help-count">已帮助 {{ selectedQuestion.solvedCount }} 位用户解决问题</p>
            <div class="feedback-buttons">
              <button class="feedback-btn helpful" @click="markAsHelpful">
                <i class="feedback-icon">👍</i>
                有帮助
              </button>
              <button class="feedback-btn not-helpful" @click="markAsNotHelpful">
                <i class="feedback-icon">❌</i>
                未解决
              </button>
            </div>
          </div>
        </div>
      </div>
      
      <div v-else-if="selectedSubCategory && selectedSubCategory.questions && selectedSubCategory.questions.length > 0">
        <h5 class="content-title">{{ selectedCategoryName }} {{ selectedSubCategory.name }}</h5>
        <div class="questions-container">
          <ul class="questions-list">
            <li v-for="(question, idx) in paginatedQuestions" :key="idx" class="question-item" :id="slug(question.question)">
              <a href="javascript:void(0)" class="question-link" @click="showQuestionDetail(question)">{{ question.question }}</a>
            </li>
          </ul>
          <!-- 分页组件（分页时不显示动画） -->
          <Pagination 
            :current-page="currentPage"
            :total-pages="totalPages"
            :change-page="changePage"
          />
        </div>
      </div>
      <div v-else-if="searchResults.length > 0">
        <h5 class="content-title">搜索结果</h5>
        <div class="questions-container">
          <ul class="questions-list">
            <li v-for="(result, idx) in searchResults" :key="idx" class="question-item">
              <a href="javascript:void(0)" class="question-link" @click="showQuestionDetail(result)">{{ result.question }}</a>
            </li>
          </ul>
          <!-- 搜索结果分页（分页时不显示动画） -->
          <Pagination 
            :current-page="currentPage"
            :total-pages="totalPages"
            :change-page="changeSearchPage"
          />
        </div>
      </div>
      <div v-else class="questions-container">
        <p class="no-data">{{ searchQuery ? '未找到相关问题' : '请选择一个分类查看问题' }}</p>
      </div>
    </template>
  </HelpLayout>
  
  <!-- 图片预览模态框 -->
  <div v-if="imagePreviewVisible" class="image-preview-modal" @click="closeImagePreview">
    <div class="image-preview-content" @click.stop>
      <span class="close-btn" @click="closeImagePreview">&times;</span>
      <img :src="previewImageUrl" class="preview-image" />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'

import HelpLayout from '../layouts/HelpLayout.vue'
import Pagination from '../components/Pagination.vue'

// 多语言配置
const translations = ref({})
const currentLang = ref('zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/OperationGuidePage.json')
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
  loadTranslations()
  updatePageTitle()
}

// 新增：从API获取的数据
const guideCategories = ref([])
const loading = ref(false)
const error = ref(null)

// 选中的二级分类
const selectedSubCategory = ref(null)
const selectedSubCategoryId = ref(null)

// 选中的问题
const selectedQuestion = ref(null)

// 搜索相关
const searchQuery = ref('')
const searchResults = ref([])
const searchLoading = ref(false) // 只在搜索时为true，分页时不为true

// 分页相关
const currentPage = ref(1)
const totalPages = ref(0)
const totalQuestions = ref(0)
const questionsPerPage = ref(10)
const paginatedQuestions = ref([])

// 图片预览相关
const imagePreviewVisible = ref(false)
const previewImageUrl = ref('')

// 获取一级分类名称
const selectedCategoryName = computed(() => {
  if (!selectedSubCategory.value) return ''
  const categoryId = selectedSubCategory.value.categoryId || selectedSubCategory.value.parentId
  const category = guideCategories.value.find(c => c.id == categoryId)
  return category ? category.name : ''
})

// 获取操作指引分类数据
const fetchGuideCategories = async () => {
  try {
    loading.value = true
    error.value = null
    const response = await fetch('/shop/api/help/guide-categories')
    const result = await response.json()
    
    if (result.success) {
      guideCategories.value = result.data
      
      // 默认选中第一个二级分类（如果有）
      if (result.data.length > 0 && result.data[0].children && result.data[0].children.length > 0) {
        selectSubCategory(result.data[0].children[0])
        // 默认展开第一个一级分类
        openKeys.value = new Set([result.data[0].id])
      }
    } else {
      error.value = result.message || '获取分类数据失败'
    }
  } catch (err) {
    error.value = '网络错误，请稍后重试'
    console.error('获取分类数据失败:', err)
  } finally {
    loading.value = false
  }
}

// 展开折叠状态 - 一级分类（一次只展开一个）
const openKeys = ref(new Set())
function isOpen(key) {
  return openKeys.value.has(key)
}
function toggle(key) {
  // 一次只展开一个一级分类
  if (openKeys.value.has(key)) {
    // 如果当前分类已展开，则关闭它
    openKeys.value = new Set()
  } else {
    // 如果当前分类未展开，则关闭其他所有分类，只展开当前分类
    openKeys.value = new Set([key])
  }
}

// 导航数据（来自API）
const navData = computed(() => {
  return guideCategories.value
})

// 选择二级分类
async function selectSubCategory(subCategory) {
  selectedSubCategory.value = subCategory
  selectedSubCategoryId.value = subCategory.id
  searchQuery.value = ''
  searchResults.value = []
  selectedQuestion.value = null // 清除选中的问题
  
  // 重置分页
  currentPage.value = 1
  
  // 获取分页数据
  await fetchQuestionsWithPagination(subCategory.id)
  
  // 滚动到列表顶部
  if (typeof window !== 'undefined') {
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

// 获取分页问题数据（分页时不显示加载动画）
async function fetchQuestionsWithPagination(subCategoryId) {
  try {
    loading.value = true
    const response = await fetch(`/shop/api/help/guide-questions/${subCategoryId}?page=${currentPage.value}&limit=${questionsPerPage.value}`)
    const result = await response.json()
    
    if (result.success) {
      paginatedQuestions.value = result.data.questions
      totalQuestions.value = result.data.total
      totalPages.value = result.data.pages
    }
  } catch (err) {
    error.value = '获取问题列表失败，请稍后重试'
    console.error('获取问题列表失败:', err)
  } finally {
    loading.value = false
  }
}

// 切换页面（分页时不显示加载动画）
function changePage(page) {
  if (page < 1 || page > totalPages.value || page === currentPage.value) return
  currentPage.value = page
  fetchQuestionsWithPagination(selectedSubCategoryId.value)
}

// 搜索（只在搜索时显示加载动画）
async function handleSearch(query) {
  searchQuery.value = query
  if (!query.trim()) return
  
  try {
    searchLoading.value = true // 只在搜索时设置为true
    searchResults.value = []
    selectedQuestion.value = null // 清除选中的问题
    currentPage.value = 1
    
    const response = await fetch(`/shop/api/help/search?keyword=${encodeURIComponent(query)}&page=1&limit=${questionsPerPage.value}`)
    const result = await response.json()
    
    if (result.success) {
      // 处理搜索结果
      searchResults.value = result.data.results
      totalPages.value = result.data.pages
      selectedSubCategory.value = null
      selectedSubCategoryId.value = null
    }
  } catch (err) {
    error.value = '搜索失败，请稍后重试'
    console.error('搜索失败:', err)
  } finally {
    searchLoading.value = false // 搜索完成后设置为false
  }
}

// 搜索结果分页（分页时不显示加载动画）
async function changeSearchPage(page) {
  if (page < 1 || page > totalPages.value || page === currentPage.value) return
  currentPage.value = page
  
  try {
    // 分页时不显示搜索加载动画，使用普通加载状态
    loading.value = true
    const response = await fetch(`/shop/api/help/search?keyword=${encodeURIComponent(searchQuery.value)}&page=${page}&limit=${questionsPerPage.value}`)
    const result = await response.json()
    
    if (result.success) {
      searchResults.value = result.data.results
      totalPages.value = result.data.pages
    }
  } catch (err) {
    error.value = '搜索失败，请稍后重试'
    console.error('搜索失败:', err)
  } finally {
    loading.value = false
  }
}

// 显示问题详情
async function showQuestionDetail(question) {
  try {
    const response = await fetch(`/shop/api/help/question/${question.id}`)
    const result = await response.json()
    
    if (result.success) {
      selectedQuestion.value = result.data
      // 滚动到顶部
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } else {
      error.value = result.message || '获取问题详情失败'
    }
  } catch (err) {
    error.value = '网络错误，请稍后重试'
    console.error('获取问题详情失败:', err)
  }
}

// 返回问题列表
function backToQuestionList() {
  selectedQuestion.value = null
}

// 标记为有帮助
async function markAsHelpful() {
  try {
    const response = await fetch(`/shop/api/help/question/${selectedQuestion.value.id}/solved`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      }
    });
    const result = await response.json();
    
    if (result.success) {
      selectedQuestion.value.solvedCount += 1;
      // 显示提示信息
      alert('感谢您的反馈！');
    } else {
      alert('反馈提交失败，请稍后重试');
    }
  } catch (err) {
    console.error('标记为有帮助失败:', err);
    alert('网络错误，请稍后重试');
  }
}

// 标记为未解决
function markAsNotHelpful() {
  // 显示提示信息
  alert('感谢你反馈')
  // 不执行其他操作，停留在当前页面
}

// 显示图片预览
function showImagePreview(url) {
  previewImageUrl.value = url
  imagePreviewVisible.value = true
}

// 关闭图片预览
function closeImagePreview() {
  imagePreviewVisible.value = false
  previewImageUrl.value = ''
}

function slug(input) {
  return input
    .toLowerCase()
    .replace(/[^a-z0-9\u4e00-\u9fa5]+/g, '-')
    .replace(/(^-|-$)/g, '')
}



// 组件挂载时获取数据
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
  
  fetchGuideCategories()
})
</script>

<style scoped>
.content-title {
  color: #333;
  font-size: 16px;
  font-weight: 700;
  margin-bottom: 16px;
}

.questions-container {
  margin-bottom: 40px;
}

.no-data {
  color: #333;
  font-size: 16px;
  margin-bottom: 4px;
}

.questions-list {
  margin-left: 30px;
  padding: 0;
}

.question-item {
  list-style: disc;
  color: #999;
}

.question-link {
  color: #333;
  text-decoration: none;
  line-height: 36px;
}

/* 搜索加载动画样式 */
.search-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
}

.loading-spinner {
  width: 40px;
  height: 40px;
  border: 4px solid #f3f3f3;
  border-top: 4px solid #cb261c;
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin-bottom: 16px;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.loading-text {
  color: #666;
  font-size: 16px;
  margin: 0;
}

/* 面包屑导航样式 */
.breadcrumb {
  padding: 15px 0;
  margin-bottom: 20px;
  border-bottom: 1px solid #eee;
  font-size: 14px;
  background-color: #f8f9fa;
  padding-left: 20px;
  border-radius: 4px;
}

.breadcrumb-link {
  color: #007bff;
  text-decoration: none;
  transition: color 0.3s;
  font-weight: 500;
}

.breadcrumb-link:hover {
  color: #0056b3;
  text-decoration: underline;
}

.breadcrumb-separator {
  margin: 0 8px;
  color: #6c757d;
}

.breadcrumb-current {
  color: #495057;
  font-weight: 500;
}

/* 问题详情容器 */
.question-detail-container {
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  padding: 30px;
  margin-bottom: 30px;
}

.question-header {
  margin-bottom: 30px;
  padding-bottom: 20px;
  border-bottom: 1px solid #eee;
}

.question-title {
  font-size: 28px;
  font-weight: 700;
  color: #212529;
  margin-bottom: 20px;
  line-height: 1.3;
  text-align: center;
}

.question-meta {
  text-align: center;
  color: #6c757d;
  font-size: 14px;
}

.meta-item {
  margin: 0 15px;
}

.question-content {
  font-size: 16px;
  line-height: 1.8;
  color: #333;
  margin-bottom: 40px;
}

.question-content :deep(img) {
  max-width: 100%;
  height: auto;
  border-radius: 4px;
  margin: 15px 0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
}

.question-content :deep(p) {
  margin-bottom: 16px;
  text-align: justify;
}

.question-content :deep(h1),
.question-content :deep(h2),
.question-content :deep(h3) {
  margin-top: 24px;
  margin-bottom: 16px;
  font-weight: 700;
  color: #212529;
}

.question-content :deep(h1) {
  font-size: 24px;
  border-bottom: 2px solid #007bff;
  padding-bottom: 10px;
}

.question-content :deep(h2) {
  font-size: 20px;
  border-bottom: 1px solid #dee2e6;
  padding-bottom: 8px;
}

.question-content :deep(h3) {
  font-size: 18px;
}

.question-content :deep(ul),
.question-content :deep(ol) {
  margin-left: 20px;
  margin-bottom: 16px;
}

.question-content :deep(li) {
  margin-bottom: 8px;
}

.question-content :deep(code) {
  background-color: #f8f9fa;
  padding: 2px 6px;
  border-radius: 3px;
  font-family: monospace;
  font-size: 14px;
}

.question-content :deep(pre) {
  background-color: #f8f9fa;
  padding: 15px;
  border-radius: 5px;
  overflow-x: auto;
  margin: 20px 0;
}

.question-content :deep(blockquote) {
  border-left: 4px solid #007bff;
  padding: 10px 20px;
  margin: 20px 0;
  background-color: #f8f9fa;
  color: #6c757d;
}

/* 问题图片样式 */
.question-images {
  margin-top: 30px;
  margin-bottom: 30px;
  width: 100%;
}

.question-image-item {
  display: flex;
  justify-content: center;
  align-items: center;
  width: 100%;
  margin-bottom: 20px;
}

.question-image {
  max-width: 100%;
  height: auto;
  border-radius: 4px;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  transition: transform 0.3s ease;
}

.question-image:hover {
  transform: scale(1.02);
}

/* 图片预览模态框样式 */
.image-preview-modal {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.8);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 1000;
}

.image-preview-content {
  position: relative;
  max-width: 90%;
  max-height: 90%;
}

.close-btn {
  position: absolute;
  top: -40px;
  right: 0;
  font-size: 30px;
  color: white;
  cursor: pointer;
  background: none;
  border: none;
}

.preview-image {
  max-width: 100%;
  max-height: 80vh;
  border-radius: 4px;
}

/* 反馈区域样式 */
.question-feedback {
  text-align: center;
  padding: 30px 0 20px;
  border-top: 1px solid #eee;
  margin-top: 30px;
}

.help-count {
  color: #6c757d;
  font-size: 14px;
  margin-bottom: 20px;
}

.feedback-buttons {
  display: flex;
  justify-content: center;
  margin-top: 10px;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.feedback-buttons > *:not(:last-child) {
  margin-right: 30px;
}


.feedback-btn {
  display: flex;
  align-items: center;
  padding: 12px 24px;
  border: 1px solid #ddd;
  background: #fff;
  border-radius: 30px;
  cursor: pointer;
  font-size: 16px;
  font-weight: 500;
  transition: all 0.3s;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */
.feedback-btn > *:not(:last-child) {
  margin-right: 8px;
}


.feedback-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.feedback-icon {
  font-size: 18px;
}

.helpful:hover {
  border-color: #28a745;
  color: #28a745;
}

.not-helpful:hover {
  border-color: #dc3545;
  color: #dc3545;
}
</style>

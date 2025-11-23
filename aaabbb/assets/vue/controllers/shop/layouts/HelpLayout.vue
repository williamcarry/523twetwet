<template>
  <div class="help-center-page">
    <HelpHeader :page-title="pageTitle" :on-search="handleSearch" />
    
    <main>
      <section class="hc-container" style="padding: 20px 0;">
        <div class="help-content-wrapper">
          <!-- 左侧分类 -->
          <div class="sidebar-container">
            <p class="sidebar-title">{{ sidebarTitle }}</p>
            <ul class="category-list">
              <li v-for="(item, index) in navData" :key="item.id" class="category-item">
                <span
                  v-if="item.children && item.children.length > 0"
                  :title="item.name"
                  :class="['category-header', index > 0 ? `category-header-${index}` : '']"
                  role="button"
                  aria-label="切换展开"
                  :aria-expanded="isOpen(item.id)"
                  @click.prevent.stop="toggle(item.id)"
                >
                  <a href="javascript:void(0)" class="category-link">{{ item.name }}</a>
                  <i :class="['toggle-icon', index > 0 ? `toggle-icon-${index}` : '']">{{ isOpen(item.id) ? '−' : '+' }}</i>
                </span>
                <span
                  v-else
                  :title="item.name"
                  :class="['category-header', index > 0 ? `category-header-${index}` : '']"
                  role="button"
                  aria-label="打开链接"
                >
                  <a href="javascript:void(0)" class="category-link">{{ item.name }}</a>
                </span>
                <ul
                  v-if="item.children && item.children.length > 0"
                  :class="['subcategory-list', `subcategory-list-${index + 2}`]"
                  v-show="isOpen(item.id)"
                >
                  <li v-for="child in item.children" :key="child.id" class="subcategory-item">
                    <a
                      :title="child.name"
                      href="javascript:void(0)"
                      :class="['subcategory-link', selectedSubCategoryId === child.id ? 'active' : '']"
                      @click.prevent="onSelectSubCategory(child)"
                    >
                      {{ child.name }}
                    </a>
                  </li>
                </ul>
              </li>
            </ul>
          </div>

          <!-- 右侧内容（动态显示） -->
          <div class="content-container">
            <div class="content-wrapper">
              <slot name="content" 
                    :selected-sub-category="selectedSubCategory"
                    :selected-category-name="selectedCategoryName"
                    :paginated-questions="paginatedQuestions"
                    :search-results="searchResults"
                    :search-query="searchQuery"
                    :get-faq-link="getFaqLink"
                    :total-pages="totalPages"
                    :current-page="currentPage"
                    :visible-pages="visiblePages"
                    :change-page="changePage">
              </slot>
            </div>
          </div>

          <div class="clearfix"></div>
        </div>
      </section>
    </main>

    <SiteFooter />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import HelpHeader from '../components/HelpHeader.vue'
import SiteFooter from '../components/SiteFooter.vue'

const props = defineProps({
  pageTitle: {
    type: String,
    default: '帮助中心'
  },
  sidebarTitle: {
    type: String,
    default: '常见问题'
  },
  navData: {
    type: Array,
    default: () => []
  },
  selectedSubCategory: {
    type: Object,
    default: null
  },
  selectedSubCategoryId: {
    type: [String, Number],
    default: null
  },
  selectedCategoryName: {
    type: String,
    default: ''
  },
  paginatedQuestions: {
    type: Array,
    default: () => []
  },
  searchResults: {
    type: Array,
    default: () => []
  },
  searchQuery: {
    type: String,
    default: ''
  },
  currentPage: {
    type: Number,
    default: 1
  },
  totalPages: {
    type: Number,
    default: 0
  },
  isOpen: {
    type: Function,
    default: () => false
  },
  toggle: {
    type: Function,
    default: () => {}
  },
  onSelectSubCategory: {
    type: Function,
    default: () => {}
  },
  getFaqLink: {
    type: Function,
    default: () => '#'
  },
  changePage: {
    type: Function,
    default: () => {}
  }
})

const emit = defineEmits(['search'])

// 计算可见页码
const visiblePages = computed(() => {
  const pagerCount = 11
  const halfPagerCount = Math.floor(pagerCount / 2)
  const currentPageNum = props.currentPage
  const allPages = props.totalPages
  
  let showPages = []
  
  if (allPages <= pagerCount) {
    // 总页数小于等于11页时，显示所有页码
    for (let i = 1; i <= allPages; i++) {
      showPages.push(i)
    }
  } else {
    // 总页数大于11页时，使用省略号折叠
    if (currentPageNum <= halfPagerCount + 1) {
      // 在第1页附近
      for (let i = 1; i <= pagerCount - 2; i++) {
        showPages.push(i)
      }
      showPages.push('...')
      showPages.push(allPages)
    } else if (currentPageNum >= allPages - halfPagerCount) {
      // 在最后一页附近
      showPages.push(1)
      showPages.push('...')
      for (let i = allPages - pagerCount + 3; i <= allPages; i++) {
        showPages.push(i)
      }
    } else {
      // 在中间位置
      showPages.push(1)
      showPages.push('...')
      for (let i = currentPageNum - halfPagerCount + 2; i <= currentPageNum + halfPagerCount - 2; i++) {
        showPages.push(i)
      }
      showPages.push('...')
      showPages.push(allPages)
    }
  }
  
  return showPages
})

function handleSearch(query) {
  emit('search', query)
}
</script>

<style scoped>
/* 页面背景色设置 */
.help-center-page {
  background-color: #f7f9fb; /* 确保整个页面背景是灰色 */
  min-height: 100vh; /* 确保背景色覆盖整个视口高度 */
}

/* 复用 HelpCenterPage 样式 */
.hc-container { 
  width: 100%; 
  max-width: 1500px; 
  min-width: 1150px; 
  margin: 0 auto; 
  padding: 0 16px; 
}

/* 新的布局样式 */
.help-content-wrapper {
  clear: both;
  display: flex;
}

.sidebar-container {
  background-color: #fff;
  width: 240px;
  height: 550px; /* 与右侧内容区高度一致 */
  overflow-y: auto; /* 垂直滚动 */
  flex-shrink: 0;
  margin-right: 20px;
}

.sidebar-title {
  color: #333;
  font-size: 16px;
  font-weight: 700;
  padding: 20px 20px 5px;
}

.category-list {
  padding: 10px 0;
  margin: 0;
}

.category-item {
  list-style: none;
}

.category-header {
  background-color: #fbeae5;
  color: #333;
  height: 46px;
  line-height: 46px;
  margin-bottom: 1px;
  padding: 0 40px 0 20px;
  position: relative;
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  cursor: pointer;
}

.category-link {
  color: #333;
  display: inline-block;
  line-height: 46px;
  width: 100%;
  text-decoration: none;
}

.toggle-icon {
  position: absolute;
  right: 12px;
  top: 0;
  line-height: 46px;
  font-size: 18px;
  color: currentColor;
  user-select: none;
}

.subcategory-list {
  padding: 0 20px;
  margin: 0;
}

.subcategory-link {
  display: inline-block;
  width: 100%;
  line-height: 46px;
  color: #333;
  text-decoration: none;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.subcategory-link.active {
  color: #cb261c;
}

.content-container {
  flex: 1;
  min-width: 0;
}

.content-wrapper {
  background: #fff;
  margin-bottom: 20px;
  min-height: 550px;
  padding: 20px;
  width: 100%;
  position: relative;
}

.clearfix {
  clear: both;
}

/* 自定义滚动条样式 */
.sidebar-container::-webkit-scrollbar {
  width: 1px; /* 滚动条宽度为1px */
}

.sidebar-container::-webkit-scrollbar-track {
  background: #f1f1f1; /* 滚动条轨道颜色 */
}

.sidebar-container::-webkit-scrollbar-thumb {
  background: #cb261c; /* 滚动条滑块颜色 */
  border-radius: 2px; /* 滚动条滑块圆角 */
}

.sidebar-container::-webkit-scrollbar-thumb:hover {
  background: #a91e14; /* 滚动条滑块悬停颜色 */
}

@media (max-width: 1149px) {
  .hc-container { max-width: 960px; min-width: auto; }
}
@media (max-width: 991px) {
  .hc-container { max-width: 720px; }
}
@media (max-width: 767px) {
  .hc-container { max-width: 540px; padding: 0 12px; }
  .help-content-wrapper {
    flex-direction: column;
  }
  .sidebar-container {
    width: 100%;
    height: auto;
  }
  .content-container {
    width: 100%;
  }
}
@media (max-width: 575px) {
  .sidebar-container {
    width: 100%;
  }
}
</style>

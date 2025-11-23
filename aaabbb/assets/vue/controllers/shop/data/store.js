import { createStore } from 'vuex';

// 导入现有的数据文件

// 从localStorage加载用户信息
function loadUserFromStorage() {
  try {
    const storedUser = localStorage.getItem('user');
    if (storedUser) {
      return JSON.parse(storedUser);
    }
  } catch (error) {
    console.error('[Store] 从localStorage加载用户信息时出错:', error);
  }
  return null;
}

// 保存用户信息到localStorage
function saveUserToStorage(user) {
  try {
    if (user) {
      localStorage.setItem('user', JSON.stringify(user));
    } else {
      localStorage.removeItem('user');
    }
  } catch (error) {
    console.error('[Store] 保存用户信息到localStorage时出错:', error);
  }
}

// 从localStorage加载公共资源数据
function loadPublicResourcesFromStorage() {
  try {
    const storedData = localStorage.getItem('publicResources');
    if (storedData) {
      const parsedData = JSON.parse(storedData);
      // 检查数据是否过期（5分钟内认为是有效的）
      if (parsedData.lastUpdated) {
        const lastUpdated = new Date(parsedData.lastUpdated);
        const now = new Date();
        const diffMinutes = (now - lastUpdated) / (1000 * 60);
        
        if (diffMinutes < 5) {
          console.log('[Store] 从localStorage加载公共资源数据');
          return parsedData;
        } else {
          console.log('[Store] localStorage中的公共资源数据已过期');
        }
      }
    }
  } catch (error) {
    console.error('[Store] 从localStorage加载数据时出错:', error);
  }
  
  // 返回默认状态
  return {
    header: [],
    footer: [],
    supplierIntro: [],
    copyright: [],
    webicp: [],
    privacyPolicy: [],
    termsOfService: [],
    lastUpdated: null
  };
}

// 保存公共资源数据到localStorage
function savePublicResourcesToStorage(publicResources) {
  try {
    localStorage.setItem('publicResources', JSON.stringify(publicResources));
  } catch (error) {
    console.error('[Store] 保存数据到localStorage时出错:', error);
  }
}

const store = createStore({
  state() {
    return {

      // 搜索相关状态
      searchQuery: '',
      searchResults: [],
      isSearching: false,
      
      // 用户相关状态
      user: loadUserFromStorage(),
      isLoggedIn: !!loadUserFromStorage(),
      
      // 购物车相关状态
      cartItems: [],
      cartTotal: 0,
      
      // 产品相关状态
      selectedCategory: null,
      selectedSubCategory: null,
      products: [],
      
      // UI状态
      isLoading: false,
      sidebarOpen: false,
      modalOpen: false,
      modalContent: null,
      
      // 帮助中心特定状态
      selectedFaq: null,
      selectedGuide: null,
      breadcrumb: [],
      
      // 网站公共资源状态
      publicResources: loadPublicResourcesFromStorage(),
      
      // 图片签名URL缓存
      imageSignedUrls: new Map()
    };
  },
  
  mutations: {
    // 网站公共资源mutations
    SET_PUBLIC_RESOURCES(state, resourcesData) {
      // 更新所有position的资源
      state.publicResources.header = resourcesData.header || [];
      state.publicResources.footer = resourcesData.footer || [];
      state.publicResources.supplierIntro = resourcesData.supplierIntro || [];
      state.publicResources.copyright = resourcesData.copyright || [];
      state.publicResources.webicp = resourcesData.webicp || [];
      state.publicResources.privacyPolicy = resourcesData.privacyPolicy || [];
      state.publicResources.termsOfService = resourcesData.termsOfService || [];
      state.publicResources.lastUpdated = new Date().toISOString();
      // 保存到localStorage
      savePublicResourcesToStorage(state.publicResources);
    },
    
    SET_HEADER_RESOURCES(state, resources) {
      state.publicResources.header = resources;
      state.publicResources.lastUpdated = new Date().toISOString();
      // 保存到localStorage
      savePublicResourcesToStorage(state.publicResources);
    },
    
    SET_FOOTER_RESOURCES(state, resources) {
      state.publicResources.footer = resources;
      state.publicResources.lastUpdated = new Date().toISOString();
      // 保存到localStorage
      savePublicResourcesToStorage(state.publicResources);
    },
    
    SET_SUPPLIER_INTRO_RESOURCES(state, resources) {
      state.publicResources.supplierIntro = resources;
      state.publicResources.lastUpdated = new Date().toISOString();
      // 保存到localStorage
      savePublicResourcesToStorage(state.publicResources);
    },
    
    SET_COPYRIGHT_RESOURCES(state, resources) {
      state.publicResources.copyright = resources;
      state.publicResources.lastUpdated = new Date().toISOString();
      // 保存到localStorage
      savePublicResourcesToStorage(state.publicResources);
    },
    
    SET_WEBICP_RESOURCES(state, resources) {
      state.publicResources.webicp = resources;
      state.publicResources.lastUpdated = new Date().toISOString();
      // 保存到localStorage
      savePublicResourcesToStorage(state.publicResources);
    },
    
    // 图片签名URL mutations
    SET_IMAGE_SIGNED_URL(state, { key, url }) {
      state.imageSignedUrls.set(key, url);
    },
    
    CLEAR_IMAGE_SIGNED_URLS(state) {
      state.imageSignedUrls.clear();
    },
    
    // 搜索相关mutations
    SET_SEARCH_QUERY(state, query) {
      state.searchQuery = query;
    },
    
    SET_SEARCH_RESULTS(state, results) {
      state.searchResults = results;
    },
    
    SET_SEARCHING_STATUS(state, status) {
      state.isSearching = status;
    },
    
    // 用户相关mutations
    SET_USER(state, user) {
      state.user = user;
      state.isLoggedIn = !!user;
      saveUserToStorage(user);
    },
    
    UPDATE_USER(state, updates) {
      if (state.user) {
        state.user = { ...state.user, ...updates };
        saveUserToStorage(state.user);
      }
    },
    
    LOGOUT(state) {
      state.user = null;
      state.isLoggedIn = false;
      saveUserToStorage(null);
      
      // 清除Cookie中的accessToken和refreshToken
      try {
        document.cookie = 'accessToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        document.cookie = 'refreshToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
      } catch (error) {
        console.error('清除Cookie失败:', error);
      }
      
      // 清除API签名密钥
      try {
        // 导入并清除apiSignature的密钥
        import('../services/apiSignature.js').then(module => {
          module.default.clearKey();
        });
        // 清除encryptionService不需要，因为它使用的是公共RSA密钥
      } catch (error) {
        console.error('清除签名密钥失败:', error);
      }
    },
    
    // 购物车相关mutations
    ADD_TO_CART(state, item) {
      const existingItem = state.cartItems.find(cartItem => cartItem.id === item.id);
      if (existingItem) {
        existingItem.quantity += item.quantity || 1;
      } else {
        state.cartItems.push({ ...item, quantity: item.quantity || 1 });
      }
      this.commit('UPDATE_CART_TOTAL');
    },
    
    REMOVE_FROM_CART(state, itemId) {
      state.cartItems = state.cartItems.filter(item => item.id !== itemId);
      this.commit('UPDATE_CART_TOTAL');
    },
    
    UPDATE_CART_QUANTITY(state, { itemId, quantity }) {
      const item = state.cartItems.find(item => item.id === itemId);
      if (item) {
        item.quantity = quantity;
        if (item.quantity <= 0) {
          this.commit('REMOVE_FROM_CART', itemId);
        } else {
          this.commit('UPDATE_CART_TOTAL');
        }
      }
    },
    
    UPDATE_CART_TOTAL(state) {
      state.cartTotal = state.cartItems.reduce((total, item) => {
        return total + (item.price * item.quantity);
      }, 0);
    },
    
    CLEAR_CART(state) {
      state.cartItems = [];
      state.cartTotal = 0;
    },
    
    // 产品相关mutations
    SET_SELECTED_CATEGORY(state, category) {
      state.selectedCategory = category;
    },
    
    SET_SELECTED_SUB_CATEGORY(state, subCategory) {
      state.selectedSubCategory = subCategory;
    },
    
    SET_PRODUCTS(state, products) {
      state.products = products;
    },
    
    // UI状态mutations
    SET_LOADING(state, loading) {
      state.isLoading = loading;
    },
    
    TOGGLE_SIDEBAR(state) {
      state.sidebarOpen = !state.sidebarOpen;
    },
    
    SET_SIDEBAR(state, open) {
      state.sidebarOpen = open;
    },
    
    OPEN_MODAL(state, content) {
      state.modalOpen = true;
      state.modalContent = content;
    },
    
    CLOSE_MODAL(state) {
      state.modalOpen = false;
      state.modalContent = null;
    },
    
    // 帮助中心特定mutations
    SET_SELECTED_FAQ(state, faq) {
      state.selectedFaq = faq;
    },
    
    SET_SELECTED_GUIDE(state, guide) {
      state.selectedGuide = guide;
    },
    
    SET_BREADCRUMB(state, breadcrumb) {
      state.breadcrumb = breadcrumb;
    },
    
    ADD_BREADCRUMB_ITEM(state, item) {
      state.breadcrumb.push(item);
    },
    
    CLEAR_BREADCRUMB(state) {
      state.breadcrumb = [];
    }
  },
  
  actions: {
    // 网站公共资源actions
    async loadPublicResources({ commit, state }) {
      // 如果已经有数据且更新时间在5分钟内，则不重新加载
      if (state.publicResources.lastUpdated) {
        const lastUpdated = new Date(state.publicResources.lastUpdated);
        const now = new Date();
        const diffMinutes = (now - lastUpdated) / (1000 * 60);
        
        if (diffMinutes < 5) {
          console.log('[Store] 公共资源数据5分钟内已更新，跳过重新加载');
          return;
        }
      }
      
      try {
        console.log('[Store] 正在加载公共资源数据...');
        const response = await fetch('/shop/api/public-resource/list');
        const result = await response.json();
        
        if (result.success) {
          commit('SET_PUBLIC_RESOURCES', result.data);
          console.log('[Store] 公共资源数据加载成功');
        } else {
          console.error('[Store] 加载公共资源数据失败:', result.message);
        }
      } catch (error) {
        console.error('[Store] 加载公共资源数据时发生错误:', error);
      }
    },
    
    // 已废弃：按位置加载资源的API，现统一使用loadPublicResources一次性加载所有资源
    /*
    async loadHeaderResources({ commit, state }) {
      // 如果已经有数据且更新时间在5分钟内，则不重新加载
      if (state.publicResources.lastUpdated) {
        const lastUpdated = new Date(state.publicResources.lastUpdated);
        const now = new Date();
        const diffMinutes = (now - lastUpdated) / (1000 * 60);
        
        if (diffMinutes < 5) {
          return;
        }
      }
      
      try {
        // 使用by-position API作为备用，但推荐使用loadPublicResources
        const response = await fetch('/shop/api/public-resource/by-position/header');
        const result = await response.json();
        
        if (result.success) {
          commit('SET_HEADER_RESOURCES', result.data);
        }
      } catch (error) {
        console.error('[Store] 加载页头资源时发生错误:', error);
      }
    },
    
    async loadFooterResources({ commit, state }) {
      // 如果已经有数据且更新时间在5分钟内，则不重新加载
      if (state.publicResources.lastUpdated) {
        const lastUpdated = new Date(state.publicResources.lastUpdated);
        const now = new Date();
        const diffMinutes = (now - lastUpdated) / (1000 * 60);
        
        if (diffMinutes < 5) {
          return;
        }
      }
      
      try {
        // 使用by-position API作为备用，但推荐使用loadPublicResources
        const response = await fetch('/shop/api/public-resource/by-position/footer');
        const result = await response.json();
        
        if (result.success) {
          commit('SET_FOOTER_RESOURCES', result.data);
        }
      } catch (error) {
        console.error('[Store] 加载页脚资源时发生错误:', error);
      }
    },
    
    async loadSupplierIntroResources({ commit, state }) {
      // 如果已经有数据且更新时间在5分钟内，则不重新加载
      if (state.publicResources.lastUpdated) {
        const lastUpdated = new Date(state.publicResources.lastUpdated);
        const now = new Date();
        const diffMinutes = (now - lastUpdated) / (1000 * 60);
        
        if (diffMinutes < 5) {
          return;
        }
      }
      
      try {
        // 使用by-position API作为备用，但推荐使用loadPublicResources
        const response = await fetch('/shop/api/public-resource/by-position/supplierIntro');
        const result = await response.json();
        
        if (result.success) {
          commit('SET_SUPPLIER_INTRO_RESOURCES', result.data);
        }
      } catch (error) {
        console.error('[Store] 加载供应商介绍资源时发生错误:', error);
      }
    },
    */
    
    // 获取图片签名URL
    async getImageSignedUrl({ commit, state }, key) {
      // 如果缓存中已有签名URL，直接返回
      if (state.imageSignedUrls.has(key)) {
        return state.imageSignedUrls.get(key);
      }
      
      try {
        const response = await fetch('/shop/api/public-resource/image/signed-url', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ key })
        });
        
        const result = await response.json();
        
        if (result.success) {
          // 缓存签名URL
          commit('SET_IMAGE_SIGNED_URL', { key, url: result.url });
          return result.url;
        } else {
          console.error('[Store] 获取图片签名URL失败:', result.message);
          return key; // 返回原始key
        }
      } catch (error) {
        console.error('[Store] 获取图片签名URL时发生错误:', error);
        return key; // 返回原始key
      }
    },
    
    // 清除图片签名URL缓存
    clearImageSignedUrls({ commit }) {
      commit('CLEAR_IMAGE_SIGNED_URLS');
    },
    
    // 搜索相关actions
    updateSearchQuery({ commit }, query) {
      commit('SET_SEARCH_QUERY', query);
    },
    
    updateSearchResults({ commit }, results) {
      commit('SET_SEARCH_RESULTS', results);
    },
    
    setSearchStatus({ commit }, status) {
      commit('SET_SEARCHING_STATUS', status);
    },
    
    // 用户相关actions
    login({ commit }, user) {
      commit('SET_USER', user);
    },
    
    logout({ commit }) {
      commit('LOGOUT');
    },
    
    // 购物车相关actions
    addToCart({ commit }, item) {
      commit('ADD_TO_CART', item);
    },
    
    removeFromCart({ commit }, itemId) {
      commit('REMOVE_FROM_CART', itemId);
    },
    
    updateCartQuantity({ commit }, payload) {
      commit('UPDATE_CART_QUANTITY', payload);
    },
    
    clearCart({ commit }) {
      commit('CLEAR_CART');
    },
    
    // 产品相关actions
    selectCategory({ commit }, category) {
      commit('SET_SELECTED_CATEGORY', category);
    },
    
    selectSubCategory({ commit }, subCategory) {
      commit('SET_SELECTED_SUB_CATEGORY', subCategory);
    },
    
    setProducts({ commit }, products) {
      commit('SET_PRODUCTS', products);
    },
    
    // UI状态actions
    setLoading({ commit }, loading) {
      commit('SET_LOADING', loading);
    },
    
    toggleSidebar({ commit }) {
      commit('TOGGLE_SIDEBAR');
    },
    
    setSidebar({ commit }, open) {
      commit('SET_SIDEBAR', open);
    },
    
    openModal({ commit }, content) {
      commit('OPEN_MODAL', content);
    },
    
    closeModal({ commit }) {
      commit('CLOSE_MODAL');
    },
    
    // 帮助中心特定actions
    selectFaq({ commit }, faq) {
      commit('SET_SELECTED_FAQ', faq);
    },
    
    selectGuide({ commit }, guide) {
      commit('SET_SELECTED_GUIDE', guide);
    },
    
    setBreadcrumb({ commit }, breadcrumb) {
      commit('SET_BREADCRUMB', breadcrumb);
    },
    
    addBreadcrumbItem({ commit }, item) {
      commit('ADD_BREADCRUMB_ITEM', item);
    },
    
    clearBreadcrumb({ commit }) {
      commit('CLEAR_BREADCRUMB');
    }
  },
  
  getters: {
    // 网站公共资源getters
    publicResources: (state) => state.publicResources,
    headerResources: (state) => state.publicResources.header,
    footerResources: (state) => state.publicResources.footer,
    supplierIntroResources: (state) => state.publicResources.supplierIntro,
    copyrightResources: (state) => state.publicResources.copyright,
    webicpResources: (state) => state.publicResources.webicp,
    privacyPolicyResources: (state) => state.publicResources.privacyPolicy,
    termsOfServiceResources: (state) => state.publicResources.termsOfService,
    lastUpdated: (state) => state.publicResources.lastUpdated,
    
    // 通用的按position获取资源的getter
    getResourcesByPosition: (state) => (position) => {
      return state.publicResources[position] || [];
    },
    
    // 图片签名URL getters
    getImageSignedUrl: (state) => (key) => {
      return state.imageSignedUrls.get(key) || key;
    },
    
    // 搜索相关getters
    searchQuery: (state) => state.searchQuery,
    searchResults: (state) => state.searchResults,
    isSearching: (state) => state.isSearching,
    
    // 用户相关getters
    user: (state) => state.user,
    isLoggedIn: (state) => state.isLoggedIn,
    
    // 购物车相关getters
    cartItems: (state) => state.cartItems,
    cartTotal: (state) => state.cartTotal,
    cartItemCount: (state) => state.cartItems.reduce((count, item) => count + item.quantity, 0),
    
    // 产品相关getters
    selectedCategory: (state) => state.selectedCategory,
    selectedSubCategory: (state) => state.selectedSubCategory,
    products: (state) => state.products,
    
    // UI状态getters
    isLoading: (state) => state.isLoading,
    isSidebarOpen: (state) => state.sidebarOpen,
    isModalOpen: (state) => state.modalOpen,
    modalContent: (state) => state.modalContent,
    
    // 帮助中心相关getters
    allFaqData: (state) => state.faqData,
    allOperationGuideFaqData: (state) => state.operationGuideFaqData,
    helpCenterNavigation: (state) => state.helpCenterNav,
    operationGuideNavigation: (state) => state.operationGuideNav,
    selectedFaq: (state) => state.selectedFaq,
    selectedGuide: (state) => state.selectedGuide,
    breadcrumb: (state) => state.breadcrumb
  }
});

export default store;
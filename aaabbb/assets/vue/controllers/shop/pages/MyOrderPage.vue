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
  <div class="bg-white">
    <main class="p-6">
      <!-- 页面标题和筛选区域 -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-3xl font-bold text-gray-900">{{ t('pageTitle') }}</h1>
          <p class="mt-2 text-sm text-gray-600">{{ t('pageDescription') }}</p>
        </div>
        
        <!-- 业务类型、状态筛选和刷新按钮 -->
        <div class="flex items-center filter-actions">
          <label class="text-sm font-medium text-gray-700">{{ t('businessTypeLabel') }}:</label>
          <select 
            v-model="businessType" 
            @change="switchBusinessType(businessType)"
            class="px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent outline-none"
          >
            <option value="dropship">{{ t('businessTypeDropship') }}</option>
            <option value="wholesale">{{ t('businessTypeWholesale') }}</option>
          </select>
          
          <label class="text-sm font-medium text-gray-700">{{ t('statusLabel') }}:</label>
          <select 
            v-model="activeStatusFilter" 
            @change="handleFilterChange"
            class="px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent outline-none"
          >
            <option value="all">{{ t('statusAll') }}</option>
            <option value="pending_payment">{{ t('statusPendingPayment') }} ({{ statistics.pending_payment }})</option>
            <option value="paid">{{ t('statusPaid') }} ({{ statistics.paid }})</option>
            <option value="shipped">{{ t('statusShipped') }} ({{ statistics.shipped }})</option>
            <option value="completed">{{ t('statusCompleted') }}</option>
            <option value="cancelled">{{ t('statusCancelled') }}</option>
          </select>
          
          <!-- 刷新按钮 -->
          <button 
            @click="handleRefresh"
            :disabled="loading"
            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center refresh-btn"
          >
            <svg 
              :class="{ 'animate-spin': loading }" 
              class="w-4 h-4" 
              fill="none" 
              stroke="currentColor" 
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ t('refreshButton') }}
          </button>
        </div>
      </div>

      <!-- 搜索区 -->
      <div class="mb-6">
        <div class="flex items-end search-area">
          <div class="flex-1">
            <label class="block text-sm text-gray-600 font-medium mb-1">{{ t('searchLabel') }}</label>
            <input
              v-model="searchKeyword"
              type="text"
              :placeholder="t('searchPlaceholder')"
              class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent"
              @keyup.enter="searchOrders"
            />
          </div>
          <button
            @click="searchOrders"
            class="px-8 py-2 bg-red-600 text-white rounded font-medium text-sm hover:bg-red-700 transition h-10"
          >
            {{ t('searchButton') }}
          </button>
        </div>
      </div>

      <!-- 加载中状态 -->
      <div v-if="loading" class="flex justify-center items-center py-12">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-red-600"></div>
      </div>

      <!-- 订单列表 -->
      <div v-else-if="orders.length > 0" class="space-y-3">
        <div 
          v-for="order in orders" 
          :key="order.id"
          class="bg-white rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 border border-red-600"
        >
          <div class="p-4">
            <!-- 顶部信息：订单号和状态 -->
            <div class="flex items-center justify-between mb-3 pb-3 border-b border-gray-200">
              <div class="flex items-center order-header-info">
                <div class="flex items-center order-info-item">
                  <span class="text-sm text-gray-500">{{ t('orderNo') }}:</span>
                  <span class="font-mono text-sm font-medium text-gray-900">{{ order.orderNo }}</span>
                </div>
                <div class="flex items-center order-info-item">
                  <span class="text-sm text-gray-500">{{ t('orderTime') }}:</span>
                  <span class="text-sm text-gray-900">{{ order.createdAt }}</span>
                </div>
              </div>
              <span 
                :class="getStatusClass(order.aggregatedOrderStatus)"
                class="px-2 py-1 rounded-full text-sm font-medium"
              >
                {{ getStatusText(order.aggregatedOrderStatus) }}
                <span v-if="order.aggregatedOrderStatus !== 'pending_payment' && order.paymentMethod" class="ml-1">
                  ({{ getPaymentMethodText(order.paymentMethod) }})
                </span>
              </span>
            </div>

            <!-- 商品信息网格 -->
            <div v-if="order.firstItem" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 product-grid mb-3">
              <!-- 商品信息 -->
              <div class="lg:col-span-2">
                <div class="flex items-start product-info-row">
                  <img 
                    :src="order.firstItem.productThumbnail || '/images/placeholder.png'" 
                    :alt="order.firstItem.productTitle" 
                    class="w-16 h-16 object-cover rounded border border-gray-200 flex-shrink-0"
                  />
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900 line-clamp-2">{{ order.firstItem.productTitle }}</div>
                    <div v-if="order.firstItem.productTitleEn" class="text-xs text-gray-400 mt-0.5 line-clamp-1">{{ order.firstItem.productTitleEn }}</div>
                    <div class="text-xs text-gray-500 mt-1">SKU: {{ order.firstItem.productSku || 'N/A' }}</div>
                  </div>
                </div>
              </div>

              <!-- 数量和单价 -->
              <div>
                <div class="text-sm text-gray-500 mb-0.5">{{ t('quantityAndPrice') }}</div>
                <div class="text-sm text-gray-900">
                  {{ t('quantity') }} {{ order.firstItem.quantity }}
                </div>
                <div class="text-sm text-gray-900">
                  {{ t('unitPrice') }} {{ order.currencySymbol || '$' }}{{ order.firstItem.actualUnitPrice || order.firstItem.unitPrice || 0 }}
                </div>
              </div>

              <!-- 订单金额和操作按钮 -->
              <div>
                <div class="text-sm text-gray-500 mb-0.5">{{ t('orderAmount') }}</div>
                <div class="text-lg font-bold text-red-600">{{ order.currencySymbol || '$' }}{{ order.totalAmount || 0 }}</div>
                <div v-if="order.discountAmount && parseFloat(order.discountAmount) > 0" class="flex items-center justify-between discount-row mt-1">
                  <div class="text-xs text-red-600">
                    {{ t('discount') }} -{{ order.currencySymbol || '$' }}{{ order.discountAmount }}
                  </div>
                  <div class="flex flex-col action-buttons">
                    <button 
                      v-if="order.aggregatedOrderStatus === 'shipped'"
                      @click="confirmReceipt(order.orderNo)" 
                      class="px-4 py-1.5 bg-red-600 text-white rounded hover:bg-red-700 transition whitespace-nowrap text-xs"
                    >
                      {{ t('confirmReceipt') }}
                    </button>
                    <button 
                      @click="viewDetail(order.orderNo)" 
                      :disabled="detailLoadingMap[order.orderNo]"
                      class="px-4 py-1.5 border border-red-600 text-red-600 rounded hover:bg-red-50 transition whitespace-nowrap text-xs"
                      :class="{ 'opacity-70 cursor-not-allowed': detailLoadingMap[order.orderNo] }"
                      style="background-color: ButtonFace !important;"
                    >
                      <span v-if="!detailLoadingMap[order.orderNo]">{{ t('viewDetail') }}</span>
                      <span v-else class="flex items-center justify-center loading-spinner">
                        <svg class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24">
                          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 74 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        {{ t('loadingDetail') }}
                      </span>
                    </button>
                  </div>
                </div>
                <div v-else class="flex justify-end action-buttons-row mt-1">
                  <button 
                    v-if="order.aggregatedOrderStatus === 'shipped'"
                    @click="confirmReceipt(order.orderNo)" 
                    class="px-4 py-1.5 bg-red-600 text-white rounded hover:bg-red-700 transition whitespace-nowrap text-xs"
                  >
                    {{ t('confirmReceipt') }}
                  </button>
                  <button 
                    @click="viewDetail(order.orderNo)" 
                    :disabled="detailLoadingMap[order.orderNo]"
                    class="px-4 py-1.5 border border-red-600 text-red-600 rounded hover:bg-red-50 transition whitespace-nowrap text-xs"
                    :class="{ 'opacity-70 cursor-not-allowed': detailLoadingMap[order.orderNo] }"
                    style="background-color: ButtonFace !important;"
                  >
                    <span v-if="!detailLoadingMap[order.orderNo]">{{ t('viewDetail') }}</span>
                    <span v-else class="flex items-center justify-center loading-spinner">
                      <svg class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 74 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      {{ t('loadingDetail') }}
                    </span>
                  </button>
                </div>
              </div>
            </div>

            <!-- 额外商品提示 -->
            <div v-if="order.additionalItemsCount">
              <span class="text-sm text-gray-500">{{ t('moreItems', { count: order.additionalItemsCount }) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- 空状态 -->
      <div v-else class="bg-white rounded-lg shadow-sm p-12 text-center">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">{{ t('noOrders') }}</h3>
        <p class="mt-1 text-sm text-gray-500">{{ t('noOrdersDescription') }}</p>
      </div>

      <!-- 分页组件 -->
      <Pagination 
        v-if="totalPages > 1"
        :current-page="currentPage" 
        :total-pages="totalPages" 
        :change-page="handlePageChange"
      />
    </main>

    <!-- 订单详情弹窗 -->
    <div v-if="showDetailModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-lg max-w-4xl w-full max-h-[90vh] overflow-y-auto">
        <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
          <h3 class="text-lg font-semibold">{{ t('orderDetailTitle') }} <span class="text-gray-600 font-normal">{{ selectedOrder.orderNo }}</span></h3>
          <div class="flex items-center modal-header-actions">
            <span class="text-sm text-gray-600">{{ t('orderTime') }}: {{ selectedOrder.createdAt }}</span>
            <button @click="closeDetailModal" class="text-gray-500 hover:text-gray-700">
              <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>
        
        <div v-if="selectedOrder" class="p-6 space-y-6">
          <!-- 订单信息 -->
          <div class="border border-gray-200 rounded-lg p-4">
            <h4 class="text-md font-semibold text-gray-900 mb-3">{{ t('orderInfo') }}</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 order-info-grid">
              <div class="md:col-span-2">
                <div class="flex justify-between items-center order-status-row">
                  <div class="flex flex-col items-center">
                    <label class="text-sm font-medium text-gray-600 mb-2">{{ t('businessType') }}</label>
                    <div class="text-sm font-normal text-orange-700">
                      {{ selectedOrder.businessType === 'dropship' ? t('businessTypeDropship') : t('businessTypeWholesale') }}
                    </div>
                  </div>
                  <div v-if="selectedOrder.shippingMethod" class="flex flex-col items-center">
                    <label class="text-sm font-medium text-gray-600 mb-2">{{ t('shippingMethod') }}</label>
                    <div class="text-sm font-normal text-orange-700">
                      {{ selectedOrder.shippingMethod === 'STANDARD_SHIPPING' ? t('shippingMethodStandard') : t('shippingMethodSelfPickup') }}
                    </div>
                  </div>
                  <div class="flex flex-col items-center">
                    <label class="text-sm font-medium text-gray-600 mb-2">{{ t('paymentStatus') }}</label>
                    <div class="text-sm font-normal text-orange-700">
                      {{ getPaymentStatusText(selectedOrder.paymentStatus) }}
                    </div>
                  </div>
                  <div class="flex flex-col items-center">
                    <label class="text-sm font-medium text-gray-600 mb-2">{{ t('paymentMethod') }}</label>
                    <div class="text-sm font-normal text-orange-700">
                      {{ getPaymentMethodText(selectedOrder.paymentMethod) }}
                    </div>
                  </div>
                  <div v-if="selectedOrder.paymentTime" class="flex flex-col items-center">
                    <label class="text-sm font-medium text-gray-600 mb-2">{{ t('paymentTime') }}</label>
                    <div class="text-sm font-normal text-orange-700">
                      {{ selectedOrder.paymentTime }}
                    </div>
                  </div>
                  <div class="flex flex-col items-center">
                    <label class="text-sm font-medium text-gray-600 mb-2">{{ t('orderStatus') }}</label>
                    <div class="mt-0.5">
                      <span class="px-2 py-1 text-sm font-bold rounded" :class="getStatusClass(selectedOrder.aggregatedOrderStatus)">
                        {{ getStatusText(selectedOrder.aggregatedOrderStatus) }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
              <div v-if="selectedOrder.completedTime" class="text-sm text-gray-900">
                <span class="font-medium text-gray-600">{{ t('completedTime') }}:</span> {{ selectedOrder.completedTime }}
              </div>
              <div v-if="selectedOrder.cancelledTime" class="text-sm text-gray-900">
                <span class="font-medium text-gray-600">{{ t('cancelledTime') }}:</span> {{ selectedOrder.cancelledTime }}
              </div>
              <div v-if="selectedOrder.cancelReason" class="md:col-span-2 text-sm text-gray-900">
                <span class="font-medium text-gray-600">{{ t('cancelReason') }}:</span> {{ selectedOrder.cancelReason }}
              </div>
            </div>
          </div>

          <!-- 收货信息 -->
          <div v-if="selectedOrder.receiverInfo" class="border border-gray-200 rounded-lg p-4">
            <h4 class="text-md font-semibold text-gray-900 mb-3">{{ t('receiverInfo') }}</h4>
            <div class="text-sm text-gray-900">
              {{ selectedOrder.receiverInfo.receiverAddress }}
              <span class="mx-2 text-gray-400">|</span>
              {{ selectedOrder.receiverInfo.receiverName }}
              <span class="mx-2 text-gray-400">|</span>
              {{ selectedOrder.receiverInfo.receiverPhone }}
              <span v-if="selectedOrder.receiverInfo.receiverZipcode" class="mx-2 text-gray-400">|</span>
              <span v-if="selectedOrder.receiverInfo.receiverZipcode">{{ t('zipcode') }}: {{ selectedOrder.receiverInfo.receiverZipcode }}</span>
            </div>
          </div>
          
          <!-- 买家留言 -->
          <div v-if="selectedOrder.buyerMessage" class="border border-gray-200 rounded-lg p-4">
            <h4 class="text-md font-semibold text-gray-900 mb-3">{{ t('buyerMessage') }}</h4>
            <div class="text-sm text-gray-700">{{ selectedOrder.buyerMessage }}</div>
          </div>

          <!-- 商品列表 -->
          <div class="border border-gray-200 rounded-lg p-4">
            <div class="flex justify-between items-center mb-3">
              <h4 class="text-md font-semibold text-gray-900">{{ t('productList') }}</h4>
              <div v-if="selectedOrder.items && selectedOrder.items.length > 0 && selectedOrder.items[0].shippingRegion" class="text-xs text-gray-500">
                {{ t('shippingRegion') }}: {{ selectedOrder.items[0].shippingRegion }}
              </div>
            </div>
            <div class="space-y-4">
              <div v-for="item in selectedOrder.items" :key="item.id" class="pb-4 border-b border-gray-200 last:border-b-0 last:pb-0">
                <div class="flex items-start product-detail-row">
                  <img 
                    :src="item.productThumbnail || '/images/placeholder.png'" 
                    :alt="item.productTitle" 
                    class="w-16 h-16 object-cover rounded border border-gray-200 flex-shrink-0"
                  />
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900">{{ item.productTitle }}</div>
                    <div v-if="item.productTitleEn" class="text-xs text-gray-400 mt-0.5">{{ item.productTitleEn }}</div>
                    <div class="text-xs text-gray-500 mt-1">SKU: {{ item.productSku }}</div>
                    <div v-if="item.productSpecs" class="text-xs text-gray-500 mt-0.5">
                      {{ t('specs') }}: {{ formatSpecs(item.productSpecs) }}
                    </div>
                  </div>
                  <div class="text-right flex-shrink-0">
                    <div class="text-sm font-semibold text-red-600">{{ selectedOrder.currencySymbol || '$' }}{{ item.subtotalAmount || 0 }}</div>
                    <span class="text-xs px-2 py-1 rounded mt-1 inline-block" :class="getStatusClass(item.orderStatus)">
                      {{ getStatusText(item.orderStatus) }}
                    </span>
                  </div>
                </div>
                
                <!-- 商品价格明细 -->
                <div class="mt-3 ml-20 space-y-1 text-xs">
                  <div class="flex justify-between text-gray-600">
                    <span>{{ t('subtotal') }}</span>
                    <span>{{ selectedOrder.currencySymbol || '$' }}{{ item.actualUnitPrice || item.unitPrice || 0 }}({{ t('unitPrice') }}) × {{ item.quantity }}({{ t('quantity') }}) + {{ selectedOrder.currencySymbol || '$' }}{{ item.shippingFee || 0 }}({{ t('shippingFee') }}) = {{ selectedOrder.currencySymbol || '$' }}{{ item.subtotalAmount || 0 }}</span>
                  </div>
                </div>
                
                <!-- 取消订单按钮 -->
                <div v-if="canCancelItem(item)" class="mt-2 ml-20 flex justify-end">
                  <button 
                    @click="cancelItemInModal(selectedOrder.orderNo, item.id)" 
                    class="px-3 py-1 text-xs border border-gray-300 text-gray-700 rounded hover:bg-gray-50 transition"
                  >
                    {{ t('cancelOrder') }}
                  </button>
                </div>
                
                <!-- 确认收货按钮（已发货状态） -->
                <div v-if="item.orderStatus === 'shipped'" class="mt-2 ml-20 flex justify-end">
                  <button 
                    @click="confirmItemReceiptInModal(selectedOrder.orderNo, item.id)" 
                    class="px-3 py-1 text-xs bg-red-600 text-white rounded hover:bg-red-700 transition"
                  >
                    {{ t('confirmReceipt') }}
                  </button>
                </div>
                
                <!-- 申请退货按钮（已完成状态，在退货有效期内，且无退款） -->
                <div v-if="canApplyItemRefund(item)" class="mt-2 ml-20 flex justify-end">
                  <button 
                    @click="applyItemRefundInModal(selectedOrder.orderNo, item.id)" 
                    class="px-3 py-1 text-xs bg-orange-500 text-white rounded hover:bg-orange-600 transition border-0 outline-none focus:outline-none"
                  >
                    {{ t('applyReturn') }}
                  </button>
                </div>
                
                <!-- 退货发货按钮（商家已同意退款，等待买家退货） -->
                <div v-if="canShipRefund(item)" class="mt-2 ml-20">
                  <!-- 退货地址显示 -->
                  <div v-if="item.returnAddress" class="mb-2 p-3 bg-blue-50 border border-blue-200 rounded text-xs">
                    <div class="flex items-start return-address-info">
                      <svg class="w-4 h-4 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                      <div class="flex-1">
                        <div class="font-semibold text-blue-900 mb-1">{{ t('returnAddress') }}</div>
                        <div class="text-blue-800">{{ item.returnAddress }}</div>
                      </div>
                    </div>
                  </div>
                  
                  <!-- 退货发货按钮 -->
                  <div class="flex justify-end">
                    <button 
                      @click="shipRefundInModal(selectedOrder.orderNo, item.id)" 
                      class="px-3 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600 transition border-0 outline-none focus:outline-none"
                    >
                      {{ t('shipRefund') }}
                    </button>
                  </div>
                </div>
                
                <!-- 物流信息 -->
                <div v-if="item.logisticsCompany || item.logisticsNo || item.refundLogisticsCompany || item.refundLogisticsNo" class="mt-3 ml-20 space-y-3">
                  <!-- 发货物流 -->
                  <div v-if="item.logisticsCompany || item.logisticsNo" class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center logistics-header mb-2">
                      <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                      </svg>
                      <div class="flex-1">
                        <div class="flex items-center logistics-info-row flex-wrap">
                          <span class="text-xs font-semibold text-blue-900">{{ t('shippingLogistics') }}:</span>
                          <span class="text-xs text-blue-800">{{ getLogisticsCompanyName(item.logisticsCompany) }}</span>
                          <button 
                            v-if="item.logisticsNo"
                            @click="openExpressTracking(item.logisticsNo)"
                            class="text-xs text-blue-600 hover:text-blue-800 font-semibold underline cursor-pointer hover:bg-blue-100 px-2 py-0.5 rounded transition"
                          >
                            {{ item.logisticsNo }}
                            <svg class="w-3 h-3 inline-block ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                          </button>
                          <span v-if="item.shippedTime" class="text-xs text-blue-600">{{ t('shippedTime') }}: {{ item.shippedTime }}</span>
                        </div>
                      </div>
                    </div>
                    <!-- 最新物流消息 -->
                    <div v-if="expressLatestMessages[item.logisticsNo]" class="mt-2 pl-6 text-xs text-green-700 bg-green-50 border border-green-200 rounded p-2">
                      <div class="flex items-start express-message-row">
                        <svg class="w-3.5 h-3.5 text-green-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div class="flex-1">
                          <span class="font-semibold">{{ expressLatestMessages[item.logisticsNo].time }}</span>
                          <span class="ml-2">{{ expressLatestMessages[item.logisticsNo].description }}</span>
                        </div>
                      </div>
                    </div>
                  </div>
                  
                  <!-- 退货物流 -->
                  <div v-if="item.refundLogisticsCompany || item.refundLogisticsNo" class="p-3 bg-orange-50 border border-orange-200 rounded-lg">
                    <div class="flex items-center logistics-header mb-2">
                      <svg class="w-4 h-4 text-orange-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                      </svg>
                      <div class="flex-1">
                        <div class="flex items-center logistics-info-row flex-wrap">
                          <span class="text-xs font-semibold text-orange-900">{{ t('returnLogistics') }}:</span>
                          <span class="text-xs text-orange-800">{{ getLogisticsCompanyName(item.refundLogisticsCompany) }}</span>
                          <button 
                            v-if="item.refundLogisticsNo"
                            @click="openExpressTracking(item.refundLogisticsNo)"
                            class="text-xs text-orange-600 hover:text-orange-800 font-semibold underline cursor-pointer hover:bg-orange-100 px-2 py-0.5 rounded transition"
                          >
                            {{ item.refundLogisticsNo }}
                            <svg class="w-3 h-3 inline-block ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                          </button>
                          <span v-if="item.refundBuyerShippedTime" class="text-xs text-orange-600">{{ t('refundBuyerShippedTime') }}: {{ item.refundBuyerShippedTime }}</span>
                        </div>
                      </div>
                    </div>
                    <!-- 最新物流消息 -->
                    <div v-if="expressLatestMessages[item.refundLogisticsNo]" class="mt-2 pl-6 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
                      <div class="flex items-start express-message-row">
                        <svg class="w-3.5 h-3.5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div class="flex-1">
                          <span class="font-semibold">{{ expressLatestMessages[item.refundLogisticsNo].time }}</span>
                          <span class="ml-2">{{ expressLatestMessages[item.refundLogisticsNo].description }}</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                
                <!-- 退款信息 -->
                <div v-if="item.refundStatus !== 'none'" class="mt-3 ml-20 p-2 bg-red-50 rounded text-xs">
                  <div class="flex justify-between items-center">
                    <span class="text-red-700 font-medium">{{ t('refundStatus') }}</span>
                    <span class="px-2 py-0.5 bg-red-100 text-red-800 rounded">
                      {{ getRefundStatusText(item.refundStatus) }}
                    </span>
                  </div>
                  <div v-if="item.refundAmount && parseFloat(item.refundAmount) > 0" class="text-red-700 mt-1">
                    {{ t('refundAmount') }}: {{ selectedOrder.currencySymbol || '$' }}{{ item.refundAmount }}
                  </div>
                  <div v-if="item.refundReason" class="text-red-600 mt-1">
                    {{ t('refundReason') }}: {{ item.refundReason }}
                  </div>
                  <div v-if="item.refundRejectReason" class="text-red-600 mt-1">
                    {{ t('refundRejectReason') }}: {{ item.refundRejectReason }}
                  </div>
                  <div v-if="item.refundTime" class="text-red-500 mt-1">
                    {{ t('refundTime') }}: {{ item.refundTime }}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 费用明细 -->
          <div class="border border-gray-200 rounded-lg p-4">
            <div class="flex justify-between items-center">
              <h4 class="text-md font-semibold text-gray-900">{{ t('total') }}</h4>
              <div class="text-sm">
                <span class="text-gray-900">{{ selectedOrder.currencySymbol || '$' }}{{ selectedOrder.pureProductAmount || 0 }}({{ t('productAmount') }}) + {{ selectedOrder.currencySymbol || '$' }}{{ selectedOrder.shippingFee || 0 }}({{ t('shippingFeeTotal') }}) = </span>
                <span class="text-red-600 font-semibold">{{ selectedOrder.currencySymbol || '$' }}{{ selectedOrder.totalAmount || 0 }}({{ t('totalAmount') }})</span>
              </div>
            </div>
          </div>

          <!-- 操作按钮区域 -->
          <div class="border-t border-gray-200 pt-4 mt-4">
            <div class="flex justify-end modal-footer-actions">
              <!-- 取消订单按钮 (待支付和待发货状态) -->
              <button 
                v-if="selectedOrder.aggregatedOrderStatus === 'pending_payment' || selectedOrder.aggregatedOrderStatus === 'paid'"
                @click="cancelOrderInModal(selectedOrder.orderNo)" 
                class="px-6 py-2 border border-gray-300 text-gray-700 rounded hover:bg-gray-50 transition"
              >
                {{ t('cancelOrder') }}
              </button>
              
              <!-- 确认收货按钮 (待收货状态) -->
              <button 
                v-if="selectedOrder.aggregatedOrderStatus === 'shipped'"
                @click="confirmReceiptInModal(selectedOrder.orderNo)" 
                class="px-6 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition"
              >
                {{ t('confirmReceipt') }}
              </button>
              
              <!-- 申请退款按钮 (已完成状态，且无退款) -->
              <button 
                v-if="selectedOrder.aggregatedOrderStatus === 'completed' && canApplyRefund(selectedOrder)"
                @click="applyRefundInModal(selectedOrder.orderNo)" 
                class="px-6 py-2 bg-orange-500 text-white rounded hover:bg-orange-600 transition border-0 outline-none focus:outline-none"
              >
                {{ t('applyRefund') }}
              </button>
              
              <!-- 退款中提示 -->
              <div v-if="selectedOrder.aggregatedOrderStatus === 'refunding'" class="px-6 py-2 bg-orange-50 text-orange-700 rounded border border-orange-200">
                {{ t('refunding') }}
              </div>
              
              <!-- 已退款提示 -->
              <div v-if="selectedOrder.aggregatedOrderStatus === 'refunded'" class="px-6 py-2 bg-green-50 text-green-700 rounded border border-green-200">
                {{ t('refunded') }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- 快递查询弹窗 -->
    <ExpressTrackingModal 
      :visible="showExpressModal" 
      :express-no="currentExpressNo"
      @update:visible="showExpressModal = $event"
      @close="showExpressModal = false"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, inject } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import Pagination from '../components/Pagination.vue'
import ExpressTrackingModal from '../components/ExpressTrackingModal.vue'
import encryptionService from '../data/encryption-service.js'
import apiSignature from '../services/apiSignature.js'

// 注入store
const store = inject('store', null)

// 页面翻译数据
const translations = ref({})

// 当前语言 - 使用ref以便能响应事件更新
const currentLang = ref(localStorage.getItem('app.lang') || 'zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/MyOrderPage.json')
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

// 响应式数据
const currentPage = ref(1)
const pageSize = ref(20)
const totalPages = ref(0)
const showDetailModal = ref(false)
const selectedOrder = ref(null)
const activeStatusFilter = ref('all')
const searchKeyword = ref('')
const businessType = ref('dropship')
const loading = ref(false)
const orders = ref([])
const statistics = ref({
  all: 0,
  pending_payment: 0,
  paid: 0,
  shipped: 0,
  completed: 0,
  cancelled: 0
})

// 快递查询弹窗状态
const showExpressModal = ref(false)
const currentExpressNo = ref('')

// 物流最新消息（缓存）
const expressLatestMessages = ref({})

// 详情加载状态
const detailLoadingMap = ref({})

// 加载订单统计
const loadStatistics = async () => {
  try {
    const requestData = {
      businessType: businessType.value // 添加业务类型参数
    }
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/statistics', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      statistics.value = data.data
    }
  } catch (error) {
    console.error('加载订单统计失败:', error)
  }
}

// 加载订单列表
const loadOrders = async () => {
  loading.value = true
  try {
    const requestData = {
      page: currentPage.value,
      pageSize: pageSize.value,
      businessType: businessType.value
    }
    
    if (searchKeyword.value) {
      requestData.orderNo = searchKeyword.value
    }
    
    if (activeStatusFilter.value !== 'all') {
      requestData.status = activeStatusFilter.value
    }
    
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/list', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      orders.value = data.data.list
      totalPages.value = data.data.pagination.totalPages
      
      // 调试日志：检查返回的订单数据
      if (data.data.list.length > 0) {
        console.log('订单数据示例:', data.data.list[0])
      }
    } else {
      ElMessage.error(data.message || t('errorLoadOrders'))
    }
  } catch (error) {
    console.error('加载订单失败:', error)
    ElMessage.error(t('errorNetwork'))
  } finally {
    loading.value = false
  }
}

// 查看订单详情
const viewDetail = async (orderNo) => {
  // 设置加载状态
  detailLoadingMap.value[orderNo] = true
  
  try {
    const requestData = { orderNo }
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/detail', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      selectedOrder.value = data.data
      // 调试日志：检查订单详情数据
      console.log('订单详情数据:', data.data)
      console.log('商品金额:', data.data.productAmount)
      console.log('运费:', data.data.shippingFee)
      console.log('优惠金额:', data.data.discountAmount)
      console.log('订单总额:', data.data.totalAmount)
      
      // 查询所有订单项的物流最新消息
      if (data.data.items && data.data.items.length > 0) {
        for (const item of data.data.items) {
          // 查询发货物流最新消息
          if (item.logisticsNo) {
            await fetchLatestExpressInfo(item.logisticsNo)
          }
          // 查询退货物流最新消息
          if (item.refundLogisticsNo) {
            await fetchLatestExpressInfo(item.refundLogisticsNo)
          }
        }
      }
      
      showDetailModal.value = true
    } else {
      ElMessage.error(data.message || t('errorLoadDetail'))
    }
  } catch (error) {
    console.error('加载订单详情失败:', error)
    ElMessage.error(t('errorNetwork'))
  } finally {
    // 清除加载状态
    detailLoadingMap.value[orderNo] = false
  }
}

/**
 * 查询物流最新消息
 */
const fetchLatestExpressInfo = async (expressNo) => {
  if (!expressNo || expressLatestMessages.value[expressNo]) {
    return // 已经有缓存了
  }
  
  try {
    const response = await fetch(`/api/common/express/track?expressNo=${encodeURIComponent(expressNo)}`, {
      method: 'GET',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    
    const result = await response.json()
    
    if (result.success && result.data && result.data.traces && result.data.traces.length > 0) {
      // 保存最新一条物流信息
      const latest = result.data.traces[0]
      expressLatestMessages.value[expressNo] = {
        time: latest.time,
        description: latest.description
      }
    }
  } catch (error) {
    console.error('查询物流信息失败:', error)
  }
}

/**
 * 打开快递查询弹窗
 */
const openExpressTracking = (expressNo) => {
  if (!expressNo) {
    ElMessage.warning(t('warningNoExpressNo'))
    return
  }
  currentExpressNo.value = expressNo
  showExpressModal.value = true
}

// 确认收货
const confirmReceipt = async (orderNo) => {
  try {
    const requestData = { orderNo }
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/confirm-receipt', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      ElMessage.success('确认收货成功')
      loadOrders()
      loadStatistics()
    } else {
      ElMessage.error(data.message || '确认收货失败')
    }
  } catch (error) {
    console.error('确认收货失败:', error)
    ElMessage.error('网络错误，请重试')
  }
}

// 在详情弹窗中确认收货
const confirmReceiptInModal = async (orderNo) => {
  await confirmReceipt(orderNo)
  // 刷新详情数据
  if (showDetailModal.value) {
    await viewDetail(orderNo)
  }
}

// 确认收货单个商品
const confirmItemReceipt = async (orderNo, itemId) => {
  try {
    const requestData = { 
      orderNo,
      itemId 
    }
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/confirm-receipt', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      ElMessage.success(t('successConfirmReceipt'))
      loadOrders()
      loadStatistics()
    } else {
      ElMessage.error(data.message || t('errorConfirmReceipt'))
    }
  } catch (error) {
    console.error('确认收货失败:', error)
    ElMessage.error(t('errorNetwork'))
  }
}

// 在详情弹窗中确认收货单个商品
const confirmItemReceiptInModal = async (orderNo, itemId) => {
  await confirmItemReceipt(orderNo, itemId)
  // 刷新详情数据
  if (showDetailModal.value) {
    await viewDetail(orderNo)
  }
}

// 取消订单
const cancelOrder = async (orderNo) => {
  if (!confirm(t('cancelOrderConfirm'))) {
    return
  }
  
  try {
    const requestData = { orderNo }
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/cancel', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      ElMessage.success(t('successCancelOrder'))
      loadOrders()
      loadStatistics()
    } else {
      ElMessage.error(data.message || t('errorCancelOrder'))
    }
  } catch (error) {
    console.error('取消订单失败:', error)
    ElMessage.error(t('errorNetwork'))
  }
}

// 在详情弹窗中取消订单
const cancelOrderInModal = async (orderNo) => {
  await cancelOrder(orderNo)
  // 关闭弹窗并刷新列表
  if (showDetailModal.value) {
    closeDetailModal()
  }
}

// 申请退款
const applyRefund = async (orderNo) => {
  // 这里可以弹出退款申请表单，暂时使用简单的确认
  const reason = prompt(t('refundReasonPrompt'))
  if (!reason) {
    return
  }
  
  try {
    const requestData = { 
      orderNo,
      refundReason: reason
    }
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/apply-refund', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      ElMessage.success(t('successRefundApplied'))
      loadOrders()
      loadStatistics()
    } else {
      ElMessage.error(data.message || t('errorRefundApply'))
    }
  } catch (error) {
    console.error('申请退款失败:', error)
    ElMessage.error(t('errorNetwork'))
  }
}

// 在详情弹窗中申请退款
const applyRefundInModal = async (orderNo) => {
  await applyRefund(orderNo)
  // 刷新详情数据
  if (showDetailModal.value) {
    await viewDetail(orderNo)
  }
}

// 判断是否可以申请退款
const canApplyRefund = (order) => {
  // 检查订单是否已完成
  if (order.aggregatedOrderStatus !== 'completed') {
    return false
  }
  
  // 检查所有订单项是否都没有退款
  const hasNoRefund = order.items.every(item => item.refundStatus === 'none')
  if (!hasNoRefund) {
    return false
  }
  
  // 检查是否在7天内（如果有完成时间）
  if (order.completedTime) {
    const completedDate = new Date(order.completedTime)
    const now = new Date()
    const diffDays = Math.floor((now - completedDate) / (1000 * 60 * 60 * 24))
    return diffDays <= 7
  }
  
  // 如果没有完成时间，默认允许申请
  return true
}

// 判断单个商品是否可以申请退货
const canApplyItemRefund = (item) => {
  // 只有已完成状态可以申请退货
  if (item.orderStatus !== 'completed') {
    return false
  }
  
  // 检查是否已经有退款申请
  if (item.refundStatus !== 'none') {
    return false
  }
  
  // 检查是否在7天退货有效期内（使用receivedTime）
  if (item.receivedTime) {
    const receivedDate = new Date(item.receivedTime)
    const now = new Date()
    const diffDays = Math.floor((now - receivedDate) / (1000 * 60 * 60 * 24))
    return diffDays <= 7
  }
  
  // 如果没有收货时间，默认允许申请
  return true
}

// 判断单个商品是否可以退货发货（商家已同意退款）
const canShipRefund = (item) => {
  // 订单状态必须是 refunding（退款中）
  if (item.orderStatus !== 'refunding') {
    return false
  }
  
  // 退款状态必须是 approved（商家已同意）
  if (item.refundStatus !== 'approved') {
    return false
  }
  
  // 检查是否已经填写了退货物流信息
  if (item.refundLogisticsNo) {
    return false // 已经发货了，不再显示按钮
  }
  
  return true
}

// 判断是否可以取消商品
const canCancelItem = (item) => {
  // 只有pending_payment和paid状态可以取消
  return ['pending_payment', 'paid'].includes(item.orderStatus)
}

// 取消商品
const cancelItem = async (orderNo, itemId) => {
  // 显示确认对话框，让用户输入取消原因
  const reason = await ElMessageBox.prompt(t('cancelReasonPrompt'), t('cancelReasonTitle'), {
    confirmButtonText: t('confirmButton'),
    cancelButtonText: t('cancelButton'),
    inputPlaceholder: t('cancelReasonPrompt'),
    inputPattern: /.+/,
    inputErrorMessage: t('cancelReasonRequired')
  }).catch(() => null)
  
  if (!reason || !reason.value) return
  
  try {
    const requestData = { 
      orderNo,
      itemId,
      reason: reason.value
    }
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/cancel-item', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      ElMessage.success(t('successCancelItem'))
      loadOrders()
      loadStatistics()
    } else {
      ElMessage.error(data.message || t('errorCancelItem'))
    }
  } catch (error) {
    console.error('取消商品失败:', error)
    ElMessage.error(t('errorNetwork'))
  }
}

// 在详情弹窗中取消商品
const cancelItemInModal = async (orderNo, itemId) => {
  await cancelItem(orderNo, itemId)
  // 刷新详情数据
  if (showDetailModal.value) {
    await viewDetail(orderNo)
  }
}

// 申请单个商品退货
const applyItemRefund = async (orderNo, itemId) => {
  // 显示确认对话框，让用户输入退货原因
  const reason = await ElMessageBox.prompt(t('refundReasonPrompt'), t('applyReturn'), {
    confirmButtonText: t('confirmButton'),
    cancelButtonText: t('cancelButton'),
    inputPlaceholder: t('refundReasonPrompt'),
    inputPattern: /.+/,
    inputErrorMessage: t('cancelReasonRequired')
  }).catch(() => null)
  
  if (!reason || !reason.value) return
  
  try {
    const requestData = { 
      orderNo,
      itemId,
      refundReason: reason.value
    }
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/apply-refund', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (data.success) {
      ElMessage.success(t('successReturnApplied'))
      loadOrders()
      loadStatistics()
    } else {
      ElMessage.error(data.message || t('errorReturnApply'))
    }
  } catch (error) {
    console.error('申请退货失败:', error)
    ElMessage.error(t('errorNetwork'))
  }
}

// 在详情弹窗中申请单个商品退货
const applyItemRefundInModal = async (orderNo, itemId) => {
  await applyItemRefund(orderNo, itemId)
  // 刷新详情数据
  if (showDetailModal.value) {
    await viewDetail(orderNo)
  }
}

// 退货发货（填写退货物流信息）
const shipRefund = async (orderNo, itemId) => {
  // 使用原生 HTML + 立即初始化方式创建搜索框
  const messageBoxPromise = ElMessageBox({
      title: t('refundShippingTitle'),
      message: `
        <div id="refund-shipping-form" style="padding: 10px; min-width: 500px;">
          <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">${t('logisticsCompanyLabel')}:<span style="color: red;">*</span></label>
            <div style="position: relative;">
              <input 
                id="logistics-company-input"
                type="text" 
                placeholder="${t('logisticsCompanyPlaceholder')}" 
                style="width: 100%; padding: 10px 12px; border: 1px solid #dcdfe6; border-radius: 4px; box-sizing: border-box; outline: none; font-size: 14px;"
                autocomplete="off"
              />
            </div>
          </div>
          <div>
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">${t('logisticsNoLabel')}:<span style="color: red;">*</span></label>
            <input 
              id="logistics-no" 
              type="text" 
              placeholder="${t('logisticsNoPlaceholder')}" 
              style="width: 100%; padding: 10px 12px; border: 1px solid #dcdfe6; border-radius: 4px; box-sizing: border-box; outline: none; font-size: 14px;"
            />
          </div>
        </div>
      `,
      dangerouslyUseHTMLString: true,
      confirmButtonText: t('confirmButton'),
      cancelButtonText: t('cancelButton'),
      customClass: 'refund-shipping-dialog',
      beforeClose: (action, instance, done) => {
        if (action === 'confirm') {
          const selectedCompany = window._refundShippingData?.selectedCompany
          const no = document.getElementById('logistics-no')?.value.trim()
          
          if (!selectedCompany) {
            ElMessage.warning(t('warningSelectLogistics'))
            return
          }
          if (!no) {
            ElMessage.warning(t('warningInputLogisticsNo'))
            return
          }
          
          instance.confirmButtonLoading = true
          
          // 提交退货物流信息
          submitRefundShipping(orderNo, itemId, selectedCompany, no)
            .then(() => {
              done()
              ElMessage.success(t('successShipRefund'))
              loadOrders()
              loadStatistics()
              // 清理全局数据
              delete window._refundShippingData
              // 清理下拉框
              const dropdown = document.getElementById('logistics-dropdown')
              if (dropdown) {
                dropdown.remove()
              }
            })
            .catch((error) => {
              instance.confirmButtonLoading = false
              ElMessage.error(error.message || t('errorShipRefund'))
            })
        } else {
          // 清理全局数据
          delete window._refundShippingData
          // 清理下拉框
          const dropdown = document.getElementById('logistics-dropdown')
          if (dropdown) {
            dropdown.remove()
          }
          done()
        }
      }
    })
    
    // 弹窗打开后，使用 setTimeout 确保 DOM 已渲染
    setTimeout(() => {
      const input = document.getElementById('logistics-company-input')
      const formContainer = document.getElementById('refund-shipping-form')
      
      if (!input || !formContainer) {
        console.error('输入框或表单容器未找到')
        return
      }
      
      console.log('开始初始化物流公司搜索框')
      
      // 创建下拉框并插入到 body 中（避免被 MessageBox 遮挡）
      let dropdown = document.getElementById('logistics-dropdown')
      if (dropdown) {
        dropdown.remove() // 先移除已存在的下拉框
      }
      dropdown = document.createElement('div')
      dropdown.id = 'logistics-dropdown'
      dropdown.style.cssText = 'display: none; position: fixed; max-height: 200px; overflow-y: auto; background: white; border: 1px solid #dcdfe6; border-radius: 4px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); z-index: 9999; margin-top: 2px;'
      document.body.appendChild(dropdown)
      
      // 定位下拉框
      const positionDropdown = () => {
        const rect = input.getBoundingClientRect()
        dropdown.style.left = rect.left + 'px'
        dropdown.style.top = (rect.bottom + 2) + 'px'
        dropdown.style.width = rect.width + 'px'
      }
      
      // 存储选中的物流公司数据
      let selectedCompany = null
      window._refundShippingData = { selectedCompany: null }
      
      // 输入时搜索
      input.addEventListener('input', async (e) => {
        const keyword = e.target.value.trim()
        console.log('输入关键词:', keyword)
        
        if (keyword.length < 1) {
          dropdown.style.display = 'none'
          selectedCompany = null
          window._refundShippingData.selectedCompany = null
          return
        }
        
        try {
          console.log('开始搜索物流公司...')
          const requestData = { keyword }
          const encryptedData = encryptionService.prepareData(requestData, true)
          const signedData = apiSignature.sign(encryptedData)
          
          const response = await fetch('/shop/api/order/search-logistics', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include',
            body: JSON.stringify(signedData)
          })
          
          const data = await response.json()
          console.log('搜索结果:', data)
          
          if (data.success && data.data && data.data.length > 0) {
            console.log('找到', data.data.length, '个物流公司')
            // 显示搜索结果
            dropdown.innerHTML = data.data.map(item => 
              `<div class="logistics-item" data-id="${item.id}" data-name="${item.name}" data-name-en="${item.nameEn}" style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; background-color: white;">${item.value}</div>`
            ).join('')
            
            // 定位并显示下拉框
            positionDropdown()
            dropdown.style.display = 'block'
            console.log('下拉列表已显示, display:', dropdown.style.display)
            
            // 绑定点击事件
            dropdown.querySelectorAll('.logistics-item').forEach(item => {
              item.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f5f7fa'
              })
              item.addEventListener('mouseleave', function() {
                this.style.backgroundColor = 'white'
              })
              item.addEventListener('click', function(e) {
                e.stopPropagation() // 阻止事件冒泡
                selectedCompany = {
                  id: this.dataset.id,
                  name: this.dataset.name,
                  nameEn: this.dataset.nameEn
                }
                window._refundShippingData.selectedCompany = selectedCompany
                input.value = `${selectedCompany.name} / ${selectedCompany.nameEn}`
                input.setAttribute('readonly', 'readonly') // 选择后设置为只读
                dropdown.style.display = 'none'
                console.log('已选择物流公司:', selectedCompany)
              })
            })
          } else {
            dropdown.innerHTML = `<div style="padding: 8px 12px; color: #909399;">${t('noLogisticsFound')}</div>`
            positionDropdown()
            dropdown.style.display = 'block'
            selectedCompany = null
            window._refundShippingData.selectedCompany = null
          }
        } catch (error) {
          console.error('搜索物流公司失败:', error)
          dropdown.innerHTML = `<div style="padding: 8px 12px; color: #f56c6c;">${t('searchLogisticsFailed')}：${error.message}</div>`
          positionDropdown()
          dropdown.style.display = 'block'
          selectedCompany = null
          window._refundShippingData.selectedCompany = null
        }
      })
      
      // 点击输入框时，如果有值且是只读的，则清空并允许编辑
      input.addEventListener('focus', async (e) => {
        console.log('输入框获得焦点')
        if (input.hasAttribute('readonly')) {
          input.removeAttribute('readonly')
          input.value = ''
          selectedCompany = null
          window._refundShippingData.selectedCompany = null
        }
        // 定位下拉框（窗口大小可能改变）
        positionDropdown()
      })
      
      // 监听滚动和窗口大小改变，重新定位下拉框
      const updatePosition = () => {
        if (dropdown.style.display === 'block') {
          positionDropdown()
        }
      }
      window.addEventListener('scroll', updatePosition, true)
      window.addEventListener('resize', updatePosition)
      
      // 点击其他区域隐藏下拉列表
      document.addEventListener('click', (e) => {
        if (input && dropdown && !input.contains(e.target) && !dropdown.contains(e.target)) {
          dropdown.style.display = 'none'
        }
      })
    }, 100) // 延迟100ms确保DOM已渲染
  
  // 等待用户操作
  try {
    await messageBoxPromise
  } catch (error) {
    // 用户取消
    console.log('用户取消操作')
    delete window._refundShippingData
    // 清理下拉框
    const dropdown = document.getElementById('logistics-dropdown')
    if (dropdown) {
      dropdown.remove()
    }
  }
}

// 提交退货物流信息
const submitRefundShipping = async (orderNo, itemId, logisticsCompany, logisticsNo) => {
  try {
    const requestData = { 
      orderNo,
      itemId,
      logisticsCompany: {
        id: logisticsCompany.id,
        name: logisticsCompany.name,
        nameEn: logisticsCompany.nameEn
      },
      logisticsNo
    }
    const encryptedData = encryptionService.prepareData(requestData, true)
    const signedData = apiSignature.sign(encryptedData)
    
    const response = await fetch('/shop/api/order/ship-refund', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'include',
      body: JSON.stringify(signedData)
    })
    
    const data = await response.json()
    
    if (!data.success) {
      throw new Error(data.message || '提交失败')
    }
    
    return data
  } catch (error) {
    console.error('提交退货物流信息失败:', error)
    throw error
  }
}

// 在详情弹窗中退货发货
const shipRefundInModal = async (orderNo, itemId) => {
  await shipRefund(orderNo, itemId)
  // 刷新详情数据
  if (showDetailModal.value) {
    await viewDetail(orderNo)
  }
}

// 切换业务类型
const switchBusinessType = (type) => {
  businessType.value = type
  currentPage.value = 1
  activeStatusFilter.value = 'all'
  searchKeyword.value = ''
  loadOrders()
  loadStatistics()
}

// 筛选变化处理
const handleFilterChange = () => {
  currentPage.value = 1
  loadOrders()
}

// 刷新数据
const handleRefresh = () => {
  loadOrders()
  loadStatistics()
}

// 按状态筛选
const filterByStatus = (status) => {
  activeStatusFilter.value = status
  currentPage.value = 1
  loadOrders()
  loadStatistics() // 重新加载统计数据
}

// 搜索订单
const searchOrders = () => {
  currentPage.value = 1
  loadOrders()
}

// 分页切换
const handlePageChange = (page) => {
  currentPage.value = page
  loadOrders()
}

// 关闭详情弹窗
const closeDetailModal = () => {
  showDetailModal.value = false
  selectedOrder.value = null
}

// 获取状态样式
const getStatusClass = (status) => {
  const statusClasses = {
    'pending_payment': 'bg-yellow-100 text-yellow-800',
    'paid': 'bg-blue-100 text-blue-800',
    'shipped': 'bg-purple-100 text-purple-800',
    'completed': 'bg-green-100 text-green-800',
    'cancelled': 'bg-red-100 text-red-800',
    'refunded': 'bg-red-100 text-red-800',
    'refunding': 'bg-orange-100 text-orange-800',
    'partial_shipped': 'bg-indigo-100 text-indigo-800',
    'mixed': 'bg-slate-100 text-slate-800'
  }
  return statusClasses[status] || 'bg-gray-100 text-gray-800'
}

// 获取状态文本
const getStatusText = (status) => {
  const statusMap = {
    'pending_payment': t('statusPendingPayment'),
    'paid': t('statusPaid'),
    'shipped': t('statusShipped'),
    'completed': t('statusCompleted'),
    'cancelled': t('statusCancelled'),
    'refunding': t('statusRefunding'),
    'refunded': t('statusRefunded'),
    'partial_shipped': t('statusPartialShipped'),
    'mixed': t('statusMixed')
  }
  return statusMap[status] || status
}

// 获取支付状态文本
const getPaymentStatusText = (status) => {
  const statusMap = {
    'unpaid': t('paymentStatusUnpaid'),
    'paid': t('paymentStatusPaid'),
    'refunded': t('paymentStatusRefunded'),
    'cancelled': t('paymentStatusCancelled')
  }
  return statusMap[status] || status
}

// 获取支付方式文本
const getPaymentMethodText = (method) => {
  if (!method) return '-'
  const methodMap = {
    'balance': t('paymentMethodBalance'),
    'alipay': t('paymentMethodAlipay'),
    'wechat': t('paymentMethodWechat'),
    'paypal': t('paymentMethodPaypal')
  }
  return methodMap[method] || method
}

// 获取退款状态文本
const getRefundStatusText = (status) => {
  const statusMap = {
    'none': t('refundStatusNone'),
    'applying': t('refundStatusApplying'),
    'approved': t('refundStatusApproved'),
    'buyer_shipped': t('refundStatusBuyerShipped'),
    'completed': t('refundStatusCompleted'),
    'rejected': t('refundStatusRejected')
  }
  return statusMap[status] || status
}

// 格式化商品规格
const formatSpecs = (specs) => {
  if (!specs || typeof specs !== 'object') return ''
  return Object.entries(specs).map(([key, value]) => `${key}: ${value}`).join(', ')
}

// 获取物流公司名称（根据当前语言）
const getLogisticsCompanyName = (logisticsCompany) => {
  if (!logisticsCompany) return '-'
  
  // 获取当前语言
  const lang = localStorage.getItem('app.lang') || currentLang.value
  
  // 如果是中文，优先显示中文名称
  if (lang === 'zh-CN') {
    return logisticsCompany.name_zh || logisticsCompany.name_en || '-'
  }
  // 如果是英文，优先显示英文名称
  return logisticsCompany.name_en || logisticsCompany.name_zh || '-'
}

// 组件挂载时加载数据
onMounted(() => {
  // 初始加载翻译
  loadTranslations()
  
  loadStatistics()
  loadOrders()
  
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

/* 筛选区 - gap-3 替代 */
.filter-actions > *:not(:last-child) {
  margin-right: 0.75rem; /* 12px */
}

/* 刷新按钮 - gap-2 替代 */
.refresh-btn > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 搜索区 - gap-3 替代 */
.search-area > *:not(:last-child) {
  margin-right: 0.75rem; /* 12px */
}

/* 订单头部信息 - gap-4 替代 */
.order-header-info > *:not(:last-child) {
  margin-right: 1rem; /* 16px */
}

/* 订单信息项 - gap-2 替代 */
.order-info-item > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 商品网格 - gap-3 替代 */
.product-grid {
  gap: 0.75rem; /* 12px */
}

@supports not (gap: 0.75rem) {
  .product-grid {
    margin-right: -0.75rem;
    margin-bottom: -0.75rem;
  }
  
  .product-grid > * {
    margin-right: 0.75rem;
    margin-bottom: 0.75rem;
  }
}

/* 商品信息行 - gap-3 替代 */
.product-info-row > *:not(:last-child) {
  margin-right: 0.75rem; /* 12px */
}

/* 折扣行 - gap-3 替代 */
.discount-row > *:not(:last-child) {
  margin-right: 0.75rem; /* 12px */
}

/* 操作按钮 - gap-2 替代 */
.action-buttons > *:not(:last-child) {
  margin-bottom: 0.5rem; /* 8px */
}

/* 加载动画 - gap-1 替代 */
.loading-spinner > *:not(:last-child) {
  margin-right: 0.25rem; /* 4px */
}

/* 操作按钮行 - gap-2 替代 */
.action-buttons-row > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 弹窗头部操作 - gap-4 替代 */
.modal-header-actions > *:not(:last-child) {
  margin-right: 1rem; /* 16px */
}

/* 订单信息网格 - gap-4 替代 */
.order-info-grid {
  gap: 1rem; /* 16px */
}

@supports not (gap: 1rem) {
  .order-info-grid {
    margin-right: -1rem;
    margin-bottom: -1rem;
  }
  
  .order-info-grid > * {
    margin-right: 1rem;
    margin-bottom: 1rem;
  }
}

/* 订单状态行 - gap-6 替代 */
.order-status-row > *:not(:last-child) {
  margin-right: 1.5rem; /* 24px */
}

/* 商品详情行 - gap-4 替代 */
.product-detail-row > *:not(:last-child) {
  margin-right: 1rem; /* 16px */
}

/* 退货地址信息 - gap-2 替代 */
.return-address-info > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 物流头部 - gap-2 替代 */
.logistics-header > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 物流信息行 - gap-2 替代 */
.logistics-info-row > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 快递消息行 - gap-2 替代 */
.express-message-row > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 快递消息行 - gap-2 替代 */
.express-message-row > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 弹窗底部操作 - gap-3 替代 */
.modal-footer-actions > *:not(:last-child) {
  margin-right: 0.75rem; /* 12px */
}

/* 自定义样式 */
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>

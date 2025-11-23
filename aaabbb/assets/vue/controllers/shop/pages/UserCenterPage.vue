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
  <div class="min-h-screen flex flex-col">
    <SiteHeader />
    <div class="flex-1 border-t" style="border-color: rgb(203, 38, 28);">
      <div class="mx-auto w-full max-w-[1500px] md:min-w-[1150px] px-4 md:px-0">
        <div class="flex main-content-wrapper">
          <!-- 左侧导航菜单 -->
          <div class="hidden md:block shrink-0 border-r border-slate-200 bg-white sidebar-menu" :style="{ width: 'var(--category-width)', paddingBottom: '0px', paddingTop: '0px' }">
            <!-- 个人中心组 -->
            <div>
              <button
                @click="toggleGroup('personal')"
                class="w-full flex items-center border-b border-slate-200 bg-white hover:bg-red-100 transition-colors cursor-pointer menu-group-header"
                style="color: rgb(51, 51, 51); height: 44px; line-height: 44px; padding-left: 15px; padding-right: 15px; text-decoration-color: rgb(51, 51, 51); outline-color: rgb(51, 51, 51); font-size: 14px; font-weight: 500;"
              >
                <User class="h-5 w-5" style="color: rgb(203, 38, 28); flex-shrink: 0;" />
                <span class="text-sm">{{ t('personalCenterGroup') }}</span>
                <ChevronDown
                  class="h-4 w-4 ml-auto transition-transform duration-300"
                  :style="{ transform: expandedGroups.personal ? 'rotate(0deg)' : 'rotate(-90deg)', color: 'rgb(156, 163, 175)' }"
                />
              </button>
              <div v-if="expandedGroups.personal" class="overflow-hidden">
                <SidebarItem
                  :label="t('personalCenter')"
                  :active="activeMenu === 'info'"
                  @click="activeMenu = 'info'"
                />
                <SidebarItem
                  :label="t('basicInfo')"
                  :active="activeMenu === 'baseinfo'"
                  @click="activeMenu = 'baseinfo'"
                />
                <SidebarItem
                  :label="t('addressBook')"
                  :active="activeMenu === 'address'"
                  @click="activeMenu = 'address'"
                />
                <SidebarItem
                  :label="t('accountSecurity')"
                  :active="activeMenu === 'security'"
                  @click="activeMenu = 'security'"
                />
              </div>
            </div>

            <!-- 消息中心组 -->
            <div>
              <button
                @click="toggleGroup('messages')"
                class="w-full flex items-center border-b border-slate-200 bg-white hover:bg-red-100 transition cursor-pointer menu-group-header"
                style="color: rgb(51, 51, 51); height: 44px; line-height: 44px; padding-left: 15px; padding-right: 15px; text-decoration-color: rgb(51, 51, 51); outline-color: rgb(51, 51, 51); font-size: 14px; font-weight: 500;"
              >
                <MessageSquare class="h-5 w-5" style="color: rgb(203, 38, 28); flex-shrink: 0;" />
                <span class="text-sm">{{ t('messageCenter') }}</span>
                <ChevronDown
                  class="h-4 w-4 ml-auto transition-transform duration-300"
                  :style="{ transform: expandedGroups.messages ? 'rotate(0deg)' : 'rotate(-90deg)', color: 'rgb(156, 163, 175)' }"
                />
              </button>
              <div v-if="expandedGroups.messages" class="overflow-hidden">
                <SidebarItem
                  :label="t('mallAnnouncement')"
                  :active="activeMenu === 'mall-announcement'"
                  @click="activeMenu = 'mall-announcement'"
                />
                <SidebarItem
                  :label="t('platformMessage')"
                  :active="activeMenu === 'platform-message'"
                  @click="activeMenu = 'platform-message'"
                />
              </div>
            </div>

            <!-- 商品管理组 -->
            <div>
              <button
                @click="toggleGroup('products')"
                class="w-full flex items-center border-b border-slate-200 bg-white hover:bg-red-100 transition cursor-pointer menu-group-header"
                style="color: rgb(51, 51, 51); height: 44px; line-height: 44px; padding-left: 15px; padding-right: 15px; text-decoration-color: rgb(51, 51, 51); outline-color: rgb(51, 51, 51); font-size: 14px; font-weight: 500;"
              >
                <Package class="h-5 w-5" style="color: rgb(203, 38, 28); flex-shrink: 0;" />
                <span class="text-sm">{{ t('productManagement') }}</span>
                <ChevronDown
                  class="h-4 w-4 ml-auto transition-transform duration-300"
                  :style="{ transform: expandedGroups.products ? 'rotate(0deg)' : 'rotate(-90deg)', color: 'rgb(156, 163, 175)' }"
                />
              </button>
              <div v-if="expandedGroups.products" class="overflow-hidden">
                <SidebarItem
                  :label="t('shoppingCart')"
                  :active="activeMenu === 'shopping-cart'"
                  @click="navigateToCart"
                />
                <SidebarItem
                  :label="t('favoriteProducts')"
                  :active="activeMenu === 'products'"
                  @click="activeMenu = 'products'"
                />
              </div>
            </div>

            <!-- 订单管理组 -->
            <div>
              <button
                @click="toggleGroup('orders')"
                class="w-full flex items-center border-b border-slate-200 bg-white hover:bg-red-100 transition cursor-pointer menu-group-header"
                style="color: rgb(51, 51, 51); height: 44px; line-height: 44px; padding-left: 15px; padding-right: 15px; text-decoration-color: rgb(51, 51, 51); outline-color: rgb(51, 51, 51); font-size: 14px; font-weight: 500;"
              >
                <ShoppingCart class="h-5 w-5" style="color: rgb(203, 38, 28); flex-shrink: 0;" />
                <span class="text-sm">{{ t('orderManagement') }}</span>
                <ChevronDown
                  class="h-4 w-4 ml-auto transition-transform duration-300"
                  :style="{ transform: expandedGroups.orders ? 'rotate(0deg)' : 'rotate(-90deg)', color: 'rgb(156, 163, 175)' }"
                />
              </button>
              <div v-if="expandedGroups.orders" class="overflow-hidden">
                <SidebarItem
                  :label="t('myOrders')"
                  :active="activeMenu === 'orders'"
                  @click="activeMenu = 'orders'"
                />
              </div>
            </div>

            <!-- 客户服务组 -->
            <div>
              <button
                @click="toggleGroup('customers')"
                class="w-full flex items-center border-b border-slate-200 bg-white hover:bg-red-100 transition cursor-pointer menu-group-header"
                style="color: rgb(51, 51, 51); height: 44px; line-height: 44px; padding-left: 15px; padding-right: 15px; text-decoration-color: rgb(51, 51, 51); outline-color: rgb(51, 51, 51); font-size: 14px; font-weight: 500;"
              >
                <Users class="h-5 w-5" style="color: rgb(203, 38, 28); flex-shrink: 0;" />
                <span class="text-sm">{{ t('customerService') }}</span>
                <ChevronDown
                  class="h-4 w-4 ml-auto transition-transform duration-300"
                  :style="{ transform: expandedGroups.customers ? 'rotate(0deg)' : 'rotate(-90deg)', color: 'rgb(156, 163, 175)' }"
                />
              </button>
              <div v-if="expandedGroups.customers" class="overflow-hidden">
                <SidebarItem
                  :label="t('downloadCenter')"
                  :active="activeMenu === 'download-center'"
                  @click="activeMenu = 'download-center'"
                />
                <SidebarItem
                  :label="t('factoryInquiry')"
                  :active="activeMenu === 'factory-inquiry'"
                  @click="activeMenu = 'factory-inquiry'"
                />
              </div>
            </div>

            <!-- 资产管理组 -->
            <div>
              <button
                @click="toggleGroup('feedback')"
                class="w-full flex items-center border-b border-slate-200 bg-white hover:bg-red-100 transition cursor-pointer menu-group-header"
                style="color: rgb(51, 51, 51); height: 44px; line-height: 44px; padding-left: 15px; padding-right: 15px; text-decoration-color: rgb(51, 51, 51); outline-color: rgb(51, 51, 51); font-size: 14px; font-weight: 500;"
              >
                <Wallet class="h-5 w-5" style="color: rgb(203, 38, 28); flex-shrink: 0;" />
                <span class="text-sm">{{ t('assetManagement') }}</span>
                <ChevronDown
                  class="h-4 w-4 ml-auto transition-transform duration-300"
                  :style="{ transform: expandedGroups.feedback ? 'rotate(0deg)' : 'rotate(-90deg)', color: 'rgb(156, 163, 175)' }"
                />
              </button>
              <div v-if="expandedGroups.feedback" class="overflow-hidden">
                <SidebarItem
                  :label="t('balanceHistory')"
                  :active="activeMenu === 'balance-history'"
                  @click="activeMenu = 'balance-history'"
                />
                <SidebarItem
                  :label="t('rechargeRecords')"
                  :active="activeMenu === 'recharge-records'"
                  @click="activeMenu = 'recharge-records'"
                />
                <SidebarItem
                  :label="t('withdrawalRecords')"
                  :active="activeMenu === 'withdrawal-records'"
                  @click="activeMenu = 'withdrawal-records'"
                />
              </div>
            </div>

          </div>

          <!-- 右侧主内容 -->
          <div class="flex-1 min-w-0">
            <!-- 个人中心面板 -->
            <div v-if="activeMenu === 'info'" class="bg-white">
              <!-- 顶部：用户信息 + VIP等级（30% + 70%） -->
              <div class="flex border-b border-slate-200 user-header-section" style="background-color: #f0f0f0;">
                <!-- 左侧30% - 用户信息 -->
                <div style="width: calc(25% - 5px); padding: 20px; background-color: white;">
                  <div style="display: flex; align-items: center;">
                    <h3 :title="username" style="color: rgb(17, 17, 17); font-size: 20px; margin: 0; padding: 0; font-weight: 600;">{{ username }}</h3>
                    <div style="margin-left: 10px; color: #cb261c; font-weight: 500; font-size: 14px;">
                      {{ vipLevelText }}
                    </div>
                  </div>

                  <div style="clear: both;"></div>
                  <div class="contact-icons" style="display: flex; margin-bottom: 20px;">
                    <!-- 手机号图标 -->
                    <div style="display: inline-block; position: relative;">
                      <Smartphone 
                        :size="19" 
                        :color="hasMobile ? '#cb261c' : '#9ca3af'" 
                        :stroke-width="2"
                        style="cursor: pointer;"
                      />
                      <p style="line-height: 30px; white-space: nowrap; display: none; background: white; box-shadow: #ccc 0 0 5px 0; padding: 3px 10px; position: absolute; top: 32px; left: 0;">
                        <template v-if="hasMobile">
                          {{ t('phoneNumber') }}<i style="display: inline; line-height: 30px;">{{ user.mobile }}</i> <a href="#" style="color: #0096ff; padding-left: 10px;">{{ t('change') }}</a>
                        </template>
                        <template v-else>
                          {{ t('notBoundPhone') }} <a href="#" style="color: #0096ff; padding-left: 10px;">{{ t('goBind') }}</a>
                        </template>
                      </p>
                    </div>

                    <!-- 邮箱图标 -->
                    <div style="display: inline-block; position: relative;">
                      <Mail 
                        :size="19" 
                        :color="hasEmail ? '#cb261c' : '#9ca3af'" 
                        :stroke-width="2"
                        style="cursor: pointer;"
                      />
                      <p style="line-height: 30px; white-space: nowrap; display: none; background: white; box-shadow: #ccc 0 0 5px 0; padding: 3px 10px; position: absolute; top: 32px; left: 0;">
                        <template v-if="hasEmail">
                          {{ t('email') }}<i style="display: inline; line-height: 30px;">{{ user.email }}</i> <a href="#" style="color: #0096ff; padding-left: 10px;">{{ t('change') }}</a>
                        </template>
                        <template v-else>
                          {{ t('notBoundEmail') }} <a href="#" style="color: #0096ff; padding-left: 10px;">{{ t('goBind') }}</a>
                        </template>
                      </p>
                    </div>

                    <!-- 完成认证图标 -->
                    <div style="display: inline-block; position: relative;">
                      <CheckCircle2 
                        :size="19" 
                        :color="isFullyVerified ? '#cb261c' : '#9ca3af'" 
                        :stroke-width="2"
                        style="cursor: pointer;"
                      />
                      <p style="line-height: 30px; white-space: nowrap; display: none; background: white; box-shadow: #ccc 0 0 5px 0; padding: 3px 10px; position: absolute; top: 32px; left: 6px; z-index: 99;">
                        <template v-if="isFullyVerified">
                          {{ t('profileCompleted') }}
                        </template>
                        <template v-else>
                          {{ t('pleaseCompleteProfile') }} <a href="#" style="color: #0096ff; padding-left: 10px;">{{ t('goComplete') }}</a>
                        </template>
                      </p>
                    </div>
                  </div>

                  <p style="line-height: 24px; margin: 0;">{{ t('customerId') }}<i style="display: inline; line-height: 24px;">{{ user.customerId || t('notGenerated') }}</i></p>
                </div>

                <!-- 右侧75% - VIP等级信息 -->
                <div style="width: calc(75% - 5px); padding: 20px; background-color: white;">
                  <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <p style="color: #333; line-height: 20px; margin: 0;">{{ t('memberLevel') }}</p>
                    <a href="/membership" style="color: #cb261c; line-height: 20px; transition: all 0.3s;">{{ t('learnMoreBenefits') }}</a>
                  </div>

                  <div style="margin-bottom: 10px; margin-top: 20px; position: relative;">
                    <ul class="vip-levels-list" style="cursor: pointer; display: flex; margin: 0; padding: 0; list-style: none;">
                      <!-- 普通 -->
                      <li 
                        style="margin-right: 2px; text-align: center; width: calc(16.6% - 2px);" 
                        :style="{ cursor: canHover('normal') ? 'pointer' : 'default' }"
                        @mouseenter="handleMouseEnter('normal')" 
                        @mouseleave="handleMouseLeave"
                      >
                        <b style="background: url('/frondend/images/UserCenterPage/4f10ca96fc4aa0f36fc878cce4c958f4.png') center/cover no-repeat; color: white; display: inline-block; height: 40px; line-height: 40px; margin-bottom: 5px; text-align: center; width: 40px;" :style="{ cursor: canHover('normal') ? 'pointer' : 'default' }">普通</b>
                        <span 
                          style="border-radius: 6px 0 0 6px; display: inline-block; height: 12px; text-align: center; width: 100%;" 
                          :style="{ 
                            backgroundColor: currentVipLevelKey === 'normal' ? '#cb261c' : '#cb261c',
                            opacity: currentVipLevelKey === 'normal' ? 1 : 0.3,
                            cursor: canHover('normal') ? 'pointer' : 'default'
                          }"
                        ></span>
                        <p :style="{ lineHeight: '20px', textAlign: 'center', margin: 0, color: currentVipLevelKey === 'normal' ? '#cb261c' : '#333', cursor: canHover('normal') ? 'pointer' : 'default' }">0.00 {{ currencySymbol }}</p>
                      </li>
                      <!-- VIP1 -->
                      <li 
                        style="display: list-item; margin-right: 2px; text-align: center; width: calc(16.6% - 2px);" 
                        :style="{ cursor: canHover('vip1') ? 'pointer' : 'default' }"
                        @mouseenter="handleMouseEnter('vip1')" 
                        @mouseleave="handleMouseLeave"
                      >
                        <b :style="{ borderColor: '#cb261c', color: currentVipLevelKey === 'vip1' ? '#cb261c' : '#999', display: 'inline-block', height: '40px', lineHeight: '40px', marginBottom: '5px', textAlign: 'center', width: '40px', cursor: canHover('vip1') ? 'pointer' : 'default' }">VIP1</b>
                        <span 
                          style="display: inline-block; height: 12px; text-align: center; width: 100%;" 
                          :style="{ 
                            backgroundColor: '#cb261c',
                            opacity: currentVipLevelKey === 'vip1' ? 1 : 0.3,
                            cursor: canHover('vip1') ? 'pointer' : 'default'
                          }"
                        ></span>
                        <p :style="{ lineHeight: '20px', textAlign: 'center', margin: 0, color: currentVipLevelKey === 'vip1' ? '#cb261c' : '#333', cursor: canHover('vip1') ? 'pointer' : 'default' }">2000.00 {{ currencySymbol }}</p>
                      </li>
                      <!-- VIP2 -->
                      <li 
                        style="display: list-item; margin-right: 2px; text-align: center; width: calc(16.6% - 2px);" 
                        :style="{ cursor: canHover('vip2') ? 'pointer' : 'default' }"
                        @mouseenter="handleMouseEnter('vip2')" 
                        @mouseleave="handleMouseLeave"
                      >
                        <b :style="{ borderColor: '#cb261c', color: currentVipLevelKey === 'vip2' ? '#cb261c' : '#999', display: 'inline-block', height: '40px', lineHeight: '40px', marginBottom: '5px', textAlign: 'center', width: '40px', cursor: canHover('vip2') ? 'pointer' : 'default' }">VIP2</b>
                        <span 
                          style="display: inline-block; height: 12px; text-align: center; width: 100%;" 
                          :style="{ 
                            backgroundColor: '#cb261c',
                            opacity: currentVipLevelKey === 'vip2' ? 1 : 0.3,
                            cursor: canHover('vip2') ? 'pointer' : 'default'
                          }"
                        ></span>
                        <p :style="{ lineHeight: '20px', textAlign: 'center', margin: 0, color: currentVipLevelKey === 'vip2' ? '#cb261c' : '#333', cursor: canHover('vip2') ? 'pointer' : 'default' }">10000.00 {{ currencySymbol }}</p>
                      </li>
                      <!-- VIP3 -->
                      <li 
                        style="display: list-item; margin-right: 2px; text-align: center; width: calc(16.6% - 2px);" 
                        :style="{ cursor: canHover('vip3') ? 'pointer' : 'default' }"
                        @mouseenter="handleMouseEnter('vip3')" 
                        @mouseleave="handleMouseLeave"
                      >
                        <b :style="{ borderColor: '#cb261c', color: currentVipLevelKey === 'vip3' ? '#cb261c' : '#999', display: 'inline-block', height: '40px', lineHeight: '40px', marginBottom: '5px', textAlign: 'center', width: '40px', cursor: canHover('vip3') ? 'pointer' : 'default' }">VIP3</b>
                        <span 
                          style="display: inline-block; height: 12px; text-align: center; width: 100%;" 
                          :style="{ 
                            backgroundColor: '#cb261c',
                            opacity: currentVipLevelKey === 'vip3' ? 1 : 0.3,
                            cursor: canHover('vip3') ? 'pointer' : 'default'
                          }"
                        ></span>
                        <p :style="{ lineHeight: '20px', textAlign: 'center', margin: 0, color: currentVipLevelKey === 'vip3' ? '#cb261c' : '#333', cursor: canHover('vip3') ? 'pointer' : 'default' }">50000.00 {{ currencySymbol }}</p>
                      </li>
                      <!-- VIP4 -->
                      <li 
                        style="display: list-item; margin-right: 2px; text-align: center; width: calc(16.6% - 2px);" 
                        :style="{ cursor: canHover('vip4') ? 'pointer' : 'default' }"
                        @mouseenter="handleMouseEnter('vip4')" 
                        @mouseleave="handleMouseLeave"
                      >
                        <b :style="{ borderColor: '#cb261c', color: currentVipLevelKey === 'vip4' ? '#cb261c' : '#999', display: 'inline-block', height: '40px', lineHeight: '40px', marginBottom: '5px', textAlign: 'center', width: '40px', cursor: canHover('vip4') ? 'pointer' : 'default' }">VIP4</b>
                        <span 
                          style="display: inline-block; height: 12px; text-align: center; width: 100%;" 
                          :style="{ 
                            backgroundColor: '#cb261c',
                            opacity: currentVipLevelKey === 'vip4' ? 1 : 0.3,
                            cursor: canHover('vip4') ? 'pointer' : 'default'
                          }"
                        ></span>
                        <p :style="{ lineHeight: '20px', textAlign: 'center', margin: 0, color: currentVipLevelKey === 'vip4' ? '#cb261c' : '#333', cursor: canHover('vip4') ? 'pointer' : 'default' }">200000.00 {{ currencySymbol }}</p>
                      </li>
                      <!-- VIP5 -->
                      <li 
                        style="display: list-item; text-align: center; width: calc(16.6% - 2px);" 
                        :style="{ cursor: canHover('vip5') ? 'pointer' : 'default' }"
                        @mouseenter="handleMouseEnter('vip5')" 
                        @mouseleave="handleMouseLeave"
                      >
                        <b :style="{ borderColor: '#cb261c', color: currentVipLevelKey === 'vip5' ? '#cb261c' : '#999', display: 'inline-block', height: '40px', lineHeight: '40px', marginBottom: '5px', textAlign: 'center', width: '40px', cursor: canHover('vip5') ? 'pointer' : 'default' }">VIP5</b>
                        <span 
                          style="border-radius: 0 6px 6px 0; display: inline-block; height: 12px; text-align: center; width: 100%;" 
                          :style="{ 
                            backgroundColor: '#cb261c',
                            opacity: currentVipLevelKey === 'vip5' ? 1 : 0.3,
                            cursor: canHover('vip5') ? 'pointer' : 'default'
                          }"
                        ></span>
                        <p :style="{ lineHeight: '20px', textAlign: 'center', margin: 0, color: currentVipLevelKey === 'vip5' ? '#cb261c' : '#333', cursor: canHover('vip5') ? 'pointer' : 'default' }">500000.00 {{ currencySymbol }}</p>
                      </li>
                    </ul>
                    <!-- VIP权益弹窗 -->
                    <div :style="{ display: hoveredVipLevel ? 'block' : 'none', backgroundColor: 'rgb(255, 255, 255)', boxShadow: 'rgba(0, 0, 0, 0.16) 0px 3px 6px 0px', position: 'absolute', top: '90px', width: '100%', zIndex: 9 }">
                      <p :style="{ borderBottom: '1px solid rgb(238, 238, 238)', fontWeight: '700', lineHeight: '20px', marginBottom: '30px', paddingBottom: '18px', paddingLeft: '20px', paddingRight: '20px', paddingTop: '18px', margin: 0 }">
                        {{ t('memberLevelPrefix') }}{{ hoveredVipLevelName }}
                      </p>
                      <ul :style="{ display: 'flex', flexWrap: 'wrap', margin: 0, padding: '0 20px 20px 20px', listStyle: 'none' }">
                        <li :style="{ paddingBottom: '30px', paddingLeft: '10px', paddingRight: '10px', textAlign: 'center', width: '16.6%' }">
                          <span :style="{ backgroundImage: 'url(/frondend/images/UserCenterPage/bd5826d04fdee6b8eb9b900f4a9ba8f5.png)', backgroundPosition: '-240px 0px', backgroundRepeat: 'no-repeat', display: 'inline-block', height: '40px', textAlign: 'center', width: '40px' }"></span>
                          <p :style="{ lineHeight: '20px', textAlign: 'center', margin: '5px 0 0 0' }">{{ t('monthlyDownloadSKU', { limit: hoveredVipDownloadLimit }) }}</p>
                          <p :style="{ lineHeight: '20px', textAlign: 'center', margin: '5px 0 0 0', fontSize: '12px', color: '#999' }">
                            {{ t('usedThisMonth', { used: monthlyStats.downloadUsed }) }}
                          </p>
                        </li>
                        <li :style="{ paddingBottom: '30px', paddingLeft: '10px', paddingRight: '10px', textAlign: 'center', width: '16.6%' }">
                          <span :style="{ backgroundImage: 'url(/frondend/images/UserCenterPage/bd5826d04fdee6b8eb9b900f4a9ba8f5.png)', backgroundPosition: '-280px 0px', backgroundRepeat: 'no-repeat', display: 'inline-block', height: '40px', textAlign: 'center', width: '40px' }"></span>
                          <p :style="{ lineHeight: '20px', textAlign: 'center', margin: '5px 0 0 0' }">{{ t('platformAuth') }}</p>
                        </li>
                        <li :style="{ paddingBottom: '30px', paddingLeft: '10px', paddingRight: '10px', textAlign: 'center', width: '16.6%' }">
                          <span :style="{ backgroundImage: 'url(/frondend/images/UserCenterPage/bd5826d04fdee6b8eb9b900f4a9ba8f5.png)', backgroundPosition: '-80px 0px', backgroundRepeat: 'no-repeat', display: 'inline-block', height: '40px', textAlign: 'center', width: '40px' }"></span>
                          <p :style="{ lineHeight: '20px', textAlign: 'center', margin: '5px 0 0 0' }">{{ t('apiIntegration') }}</p>
                        </li>
                        <li :style="{ paddingBottom: '30px', paddingLeft: '10px', paddingRight: '10px', textAlign: 'center', width: '16.6%' }">
                          <span :style="{ backgroundImage: 'url(/frondend/images/UserCenterPage/bd5826d04fdee6b8eb9b900f4a9ba8f5.png)', backgroundPosition: '-120px 0px', backgroundRepeat: 'no-repeat', display: 'inline-block', height: '40px', textAlign: 'center', width: '40px' }"></span>
                          <p :style="{ lineHeight: '20px', textAlign: 'center', margin: '5px 0 0 0' }">{{ t('reservation') }}</p>
                        </li>
                        <li :style="{ paddingBottom: '30px', paddingLeft: '10px', paddingRight: '10px', textAlign: 'center', width: '16.6%' }">
                          <span :style="{ backgroundImage: 'url(/frondend/images/UserCenterPage/bd5826d04fdee6b8eb9b900f4a9ba8f5.png)', backgroundPosition: '-160px 0px', backgroundRepeat: 'no-repeat', display: 'inline-block', height: '40px', textAlign: 'center', width: '40px' }"></span>
                          <p :style="{ lineHeight: '20px', textAlign: 'center', margin: '5px 0 0 0' }">{{ t('brandAuthService') }}</p>
                        </li>
                        <li :style="{ paddingBottom: '30px', paddingLeft: '10px', paddingRight: '10px', textAlign: 'center', width: '16.6%' }">
                          <span :style="{ backgroundImage: 'url(/frondend/images/UserCenterPage/bd5826d04fdee6b8eb9b900f4a9ba8f5.png)', backgroundPosition: '-440px 0px', backgroundRepeat: 'no-repeat', display: 'inline-block', height: '40px', textAlign: 'center', width: '40px' }"></span>
                          <p :style="{ lineHeight: '20px', textAlign: 'center', margin: '5px 0 0 0' }">{{ t('newProductPriority') }}</p>
                        </li>
                        <li :style="{ paddingBottom: '30px', paddingLeft: '10px', paddingRight: '10px', textAlign: 'center', width: '16.6%' }">
                          <span :style="{ backgroundImage: 'url(/frondend/images/UserCenterPage/bd5826d04fdee6b8eb9b900f4a9ba8f5.png)', backgroundPosition: '-240px -40px', backgroundRepeat: 'no-repeat', display: 'inline-block', height: '40px', textAlign: 'center', width: '40px' }"></span>
                          <p :style="{ lineHeight: '20px', textAlign: 'center', margin: '5px 0 0 0' }">{{ t('exclusiveSalesRights') }}</p>
                        </li>
                        <li :style="{ paddingBottom: '30px', paddingLeft: '10px', paddingRight: '10px', textAlign: 'center', width: '16.6%' }">
                          <span :style="{ backgroundImage: 'url(/frondend/images/UserCenterPage/bd5826d04fdee6b8eb9b900f4a9ba8f5.png)', backgroundPosition: '-360px -40px', backgroundRepeat: 'no-repeat', display: 'inline-block', height: '40px', textAlign: 'center', width: '40px' }"></span>
                          <p :style="{ lineHeight: '20px', textAlign: 'center', margin: '5px 0 0 0' }">{{ t('freeOrderProcessing') }}</p>
                        </li>
                        <li :style="{ paddingBottom: '30px', paddingLeft: '10px', paddingRight: '10px', textAlign: 'center', width: '16.6%' }">
                          <span :style="{ backgroundImage: 'url(/frondend/images/UserCenterPage/bd5826d04fdee6b8eb9b900f4a9ba8f5.png)', backgroundPosition: '-440px -40px', backgroundRepeat: 'no-repeat', display: 'inline-block', height: '40px', textAlign: 'center', width: '40px' }"></span>
                          <p :style="{ lineHeight: '20px', textAlign: 'center', margin: '5px 0 0 0' }">{{ t('freeMemberService') }}</p>
                        </li>
                      </ul>
                    </div>
                  </div>
                </div>
              </div>

              <!-- 下部：专属客户经理 + 待办事项（35% + 65%） -->
              <div style="height: 10px; background-color: #f0f0f0;"></div>

              <div class="manager-and-orders" style="display: flex; background-color: #f0f0f0;">
                <!-- 左侧35% - 专属客户经理 -->
                <div style="background-color: white; padding-bottom: 20px; padding-top: 20px; width: 28%;">
                  <h3 style="color: #111; font-size: 16px; height: 34px; line-height: 34px; margin-left: 20px; margin-right: 20px;">{{ t('exclusiveAccountManager') }}</h3>
                  <div style="position: relative;">
                    <p style="height: 34px; line-height: 34px; padding-left: 20px; padding-right: 20px; margin: 0;">{{ t('name') }}<b style="display: inline; line-height: 34px;">{{ contactPersonResource?.title || t('seeCustomer') }}</b></p>
                    <p style="height: 34px; line-height: 34px; padding-left: 20px; padding-right: 20px; margin: 0;">{{ t('contactPhone') }}<b style="display: inline; line-height: 34px;">{{ phoneResource?.title || '' }}</b></p>
                    <p style="height: 34px; line-height: 34px; padding-left: 20px; padding-right: 20px; margin: 0;">{{ t('contactEmail') }}<b style="display: inline; line-height: 34px;">{{ emailResource?.title || '' }}</b></p>
                  </div>
                </div>

                <!-- 右侧65% - 我的订单 -->
                <div style="background-color: white; padding-bottom: 20px; padding-top: 20px; flex: 1;">
                  <h3 style="color: #111; font-size: 16px; height: 34px; line-height: 34px; margin-left: 20px; margin-right: 20px;">{{ t('myOrdersTitle') }}</h3>
                  <ul style="display: flex; flex-wrap: wrap; padding-left: 20px; padding-right: 20px; margin: 0; list-style: none;">
                    <li style="height: 34px; line-height: 34px; width: 33.333%;">
                      <a href="#" style="color: #333; cursor: pointer; display: inline; line-height: 34px; transition: 0.3s;"><p style="cursor: pointer; display: inline-block; line-height: 34px; margin: 0;">{{ t('pendingPayment') }}</p><span style="color: #111; cursor: pointer; display: inline; line-height: 34px;">{{ orderStats.pending_payment }}</span></a>
                    </li>
                    <li style="height: 34px; line-height: 34px; width: 33.333%;">
                      <a href="#" style="color: #333; cursor: pointer; display: inline; line-height: 34px; transition: 0.3s;"><p style="cursor: pointer; display: inline-block; line-height: 34px; margin: 0;">{{ t('pendingShipment') }}</p><span style="color: #111; cursor: pointer; display: inline; line-height: 34px;">{{ orderStats.paid }}</span></a>
                    </li>
                    <li style="height: 34px; line-height: 34px; width: 33.333%;">
                      <a href="#" style="color: #333; cursor: pointer; display: inline; line-height: 34px; transition: 0.3s;"><p style="cursor: pointer; display: inline-block; line-height: 34px; margin: 0;">{{ t('pendingReceipt') }}</p><span style="color: #111; cursor: pointer; display: inline; line-height: 34px;">{{ orderStats.shipped }}</span></a>
                    </li>
                  </ul>
                </div>
              </div>

            </div>

            <!-- 基本信息面板 -->
            <BasicInfoPage v-else-if="activeMenu === 'baseinfo'" />

            <!-- 地址簿面板 -->
            <AddressBookPage v-else-if="activeMenu === 'address'" />

            <!-- 账户安全面板 -->
            <SecurityPage v-else-if="activeMenu === 'security'" />

            <!-- 商城公告面板 -->
            <MallAnnouncementPage v-else-if="activeMenu === 'mall-announcement'" />

            <!-- 平台消息面板 -->
            <PlatformMessagePage v-else-if="activeMenu === 'platform-message'" />

            <!-- 商品收藏面板 -->
            <MyFavoritesPage v-else-if="activeMenu === 'products'" />

            <!-- 我的订单面板 -->
            <MyOrderPage v-else-if="activeMenu === 'orders'" />

            <!-- 余额记录面板 -->
            <BalanceHistoryPage v-else-if="activeMenu === 'balance-history'" />

            <!-- 充值记录面板 -->
            <RechargeRecordsPage v-else-if="activeMenu === 'recharge-records'" />

            <!-- 提现记录面板 -->
            <WithdrawalRecordsPage v-else-if="activeMenu === 'withdrawal-records'" />

            <!-- 下载中心面板 -->
            <DownloadCenterPage v-else-if="activeMenu === 'download-center'" />
            
            <!-- 工厂直采面板 -->
            <FactoryInquiryPage v-else-if="activeMenu === 'factory-inquiry'" />
          </div>
        </div>
      </div>
    </div>
    <SiteFooter />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import {
  User,
  ChevronDown,
  MessageSquare,
  Package,
  ShoppingCart,
  Users,
  Wallet,
  Smartphone,
  Mail,
  CheckCircle2,
} from 'lucide-vue-next'
import SiteHeader from '@/components/SiteHeader.vue'
import SiteFooter from '@/components/SiteFooter.vue'
import SidebarItem from '@/components/SidebarItem.vue'
import BasicInfoPage from '@/pages/BasicInfoPage.vue'
import AddressBookPage from '@/pages/AddressBookPage.vue'
import SecurityPage from '@/pages/SecurityPage.vue'
import MallAnnouncementPage from '@/pages/MallAnnouncementPage.vue'
import PlatformMessagePage from '@/pages/PlatformMessagePage.vue'
import MyFavoritesPage from '@/pages/MyFavoritesPage.vue'
import MyOrderPage from '@/pages/MyOrderPage.vue'
import BalanceHistoryPage from '@/pages/BalanceHistoryPage.vue'
import RechargeRecordsPage from '@/pages/RechargeRecordsPage.vue'
import WithdrawalRecordsPage from '@/pages/WithdrawalRecordsPage.vue'
import DownloadCenterPage from '@/pages/DownloadCenterPage.vue'
import FactoryInquiryPage from '@/components/FactoryInquiryPage.vue'
import apiSignature from '../services/apiSignature.js'

// 页面翻译数据
const translations = ref({})

// 当前语言
const currentLang = ref('zh-CN')

// 加载翻译文件
const loadTranslations = async () => {
  try {
    const response = await fetch('/frondend/lang/UserCenterPage.json')
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
// 更新页面标题
const updatePageTitle = () => {
  const title = t('pageTitle')
  if (title && title !== 'pageTitle') {
    document.title = title
  }
}

const handleLangChange = (event) => {
  if (event.detail && event.detail.lang) {
    currentLang.value = event.detail.lang
  }
  // 重新加载翻译以确保语言切换时更新
  loadTranslations().then(() => {
    updatePageTitle()
  })
}

// 获取store实例
const store = window.vueStore

// 获取用户信息
const user = computed(() => store?.state?.user || {})
const username = computed(() => user.value.username || 'Guest')
const hasMobile = computed(() => !!user.value.mobile)
const hasEmail = computed(() => !!user.value.email && !user.value.email?.endsWith('@placeholder.com'))
const isFullyVerified = computed(() => hasMobile.value && hasEmail.value)

// VIP等级文本
const vipLevelText = computed(() => {
  const vipLevel = user.value.vipLevel || 0
  if (vipLevel === 0) {
    return t('normalUser')
  }
  return t('vipLevel', { level: vipLevel })
})

// VIP下载额度配置
const vipDownloadLimits = computed(() => {
  return user.value.vipDownloadLimits || {}
})

// 当前用户的下载额度
const currentDownloadLimit = computed(() => {
  const vipLevel = user.value.vipLevel || 0
  return vipDownloadLimits.value[vipLevel] || 0
})

// 剩余下载次数
const remainingDownloads = computed(() => {
  return Math.max(0, currentDownloadLimit.value - monthlyStats.value.downloadUsed)
})

// 根据鼠标悬停的VIP等级获取下载额度
const hoveredVipDownloadLimit = computed(() => {
  if (!hoveredVipLevel.value) return 0
  
  const levelMap = {
    'normal': 0,
    'vip1': 1,
    'vip2': 2,
    'vip3': 3,
    'vip4': 4,
    'vip5': 5
  }
  
  const level = levelMap[hoveredVipLevel.value]
  return vipDownloadLimits.value[level] || 0
})

// 根据悬停VIP等级获取名称
const hoveredVipLevelName = computed(() => {
  if (!hoveredVipLevel.value) return ''
  
  const levelMap = {
    'normal': 'normalMember',
    'vip1': 'vip1Member',
    'vip2': 'vip2Member',
    'vip3': 'vip3Member',
    'vip4': 'vip4Member',
    'vip5': 'vip5Member'
  }
  
  return t(levelMap[hoveredVipLevel.value] || 'normalMember')
})

// 当前用户VIP等级字符串（用于判断是否为当前等级）
const currentVipLevelKey = computed(() => {
  const vipLevel = user.value.vipLevel || 0
  if (vipLevel === 0) return 'normal'
  return `vip${vipLevel}`
})

// 检查是否可以悬停（只有当前等级可以悬停）
const canHover = (levelKey) => {
  return levelKey === currentVipLevelKey.value
}

// 处理鼠标进入事件
const handleMouseEnter = (levelKey) => {
  if (canHover(levelKey)) {
    hoveredVipLevel.value = levelKey
  }
}

// 处理鼠标离开事件
const handleMouseLeave = () => {
  hoveredVipLevel.value = null
}

// 获取供应商介绍资源
const supplierIntroResources = computed(() => {
  if (store && store.getters) {
    return store.getters.supplierIntroResources || []
  }
  return []
})

// 联系人信息
const contactPersonResource = computed(() => {
  if (Array.isArray(supplierIntroResources.value) && supplierIntroResources.value.length > 0) {
    const resource = supplierIntroResources.value.find(resource =>
      resource.position === 'supplierIntro' &&
      resource.positiontype === 'supplierIntroContract' &&
      resource.type === 'text'
    )
    return resource
  }
  return null
})

// 电话信息
const phoneResource = computed(() => {
  if (Array.isArray(supplierIntroResources.value) && supplierIntroResources.value.length > 0) {
    const resource = supplierIntroResources.value.find(resource =>
      resource.position === 'supplierIntro' &&
      resource.positiontype === 'supplierIntroPhone' &&
      resource.type === 'text'
    )
    return resource
  }
  return null
})

// 邮箱信息
const emailResource = computed(() => {
  if (Array.isArray(supplierIntroResources.value) && supplierIntroResources.value.length > 0) {
    const resource = supplierIntroResources.value.find(resource =>
      resource.position === 'supplierIntro' &&
      resource.positiontype === 'supplierIntroEmail' &&
      resource.type === 'text'
    )
    return resource
  }
  return null
})

// 月度下载统计
const monthlyStats = ref({
  year: 0,
  month: 0,
  downloadUsed: 0,
  totalOrderAmount: 0,
  totalOrders: 0
})

// 货币符号，默认$
const currencySymbol = ref('$')

// 订单统计数据
const orderStats = ref({
  pending_payment: 0,
  paid: 0,
  shipped: 0
})

// 获取用户信息和月度下载统计
const fetchUserProfile = async () => {
  try {
    // 生成签名
    const signedData = apiSignature.sign({})
    
    // 将签名参数添加到URL查询字符串
    const queryParams = new URLSearchParams({
      timestamp: signedData.timestamp,
      nonce: signedData.nonce,
      signature: signedData.signature
    })
    
    const response = await fetch(`/shop/api/customer/profile?${queryParams.toString()}`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include'
    })
    
    const data = await response.json()
    
    if (data.success) {
      // 更新用户信息到store（自动保存到localStorage）
      if (store && store.commit) {
        store.commit('SET_USER', data.data)
      }
      // 更新月度统计
      if (data.data.monthlyStats) {
        monthlyStats.value = data.data.monthlyStats
      }
      // 获取货币符号
      if (data.data.currencySymbol) {
        currencySymbol.value = data.data.currencySymbol
      }
    } else {
      console.error('获取用户信息失败:', data.message)
    }
  } catch (error) {
    console.error('获取用户信息异常:', error)
  }
}

// 获取订单统计数据（所有业务类型）
const fetchOrderStatistics = async () => {
  try {
    const response = await fetch('/shop/api/order/statistics/all', {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include'
    })
    
    const data = await response.json()
    
    if (data.success) {
      orderStats.value = data.data
    } else {
      console.error('获取订单统计失败:', data.message)
    }
  } catch (error) {
    console.error('获取订单统计异常:', error)
  }
}

// 组件挂载时获取数据
onMounted(() => {
  // 初始加载翻译
  loadTranslations().then(() => {
    updatePageTitle()
  })
  fetchUserProfile()
  fetchOrderStatistics()
  
  // 监听语言变化事件
  window.addEventListener('languagechange', handleLangChange)
})

onBeforeUnmount(() => {
  window.removeEventListener('languagechange', handleLangChange)
})

const activeMenu = ref('info')
const expandedGroups = ref({
  personal: true,
  messages: false,
  products: false,
  orders: false,
  customers: false,
  feedback: false,
})
const hoveredVipLevel = ref(null)

const menuLabels = {
  info: '个人中心',
  baseinfo: '基本信息',
  address: '地址簿',
  security: '账户安全',
  'mall-announcement': '商城公告',
  'platform-message': '平台消息',
  'shopping-cart': '购物车',
  products: '商品管理',
  orders: '我的订单',
  'download-center': '下载中心',
  'balance-history': '余额记录',
}

const activeMenuLabel = computed(() => menuLabels[activeMenu.value] || '个人中心')

const navigateToCart = () => {
  window.open('/cart', '_blank')
}

const toggleGroup = (groupName) => {
  // 关闭所有其他菜单组，只展开当前组
  Object.keys(expandedGroups.value).forEach(key => {
    if (key === groupName) {
      expandedGroups.value[key] = true
    } else {
      expandedGroups.value[key] = false
    }
  })
}
</script>

<style scoped>
/* 左侧导航栏高度自动伸缩 */
.sidebar-menu {
  height: auto;
}

/* 老浏览器（IE11、搜狗、360）兼容性修复：gap -> margin */

/* 主内容区域 - gap-[15px] 替代 */
.main-content-wrapper > *:not(:last-child) {
  margin-right: 15px;
}

/* 菜单组标题 - gap-2 替代 */
.menu-group-header > *:not(:last-child) {
  margin-right: 0.5rem; /* 8px */
}

/* 用户头部区域 - gap: 10px 替代 */
.user-header-section > *:not(:last-child) {
  margin-right: 10px;
}

/* 联系方式图标 - gap: 10px 替代 */
.contact-icons > *:not(:last-child) {
  margin-right: 10px;
}

/* VIP等级列表 - gap: 2px 替代 */
.vip-levels-list > *:not(:last-child) {
  margin-right: 2px;
}

/* 客户经理和订单区域 - gap: 10px 替代 */
.manager-and-orders > *:not(:last-child) {
  margin-right: 10px;
}
</style>

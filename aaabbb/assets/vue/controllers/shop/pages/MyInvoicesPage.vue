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
  <div class="bg-white rounded-lg border border-slate-200 p-6">
    <!-- 标题 -->
    <div class="h-[50px] pt-[10px] relative">
      <h6 class="text-[16px] font-bold leading-[40px] text-slate-900 float-left">我的账单</h6>
    </div>

    <!-- 提示条 -->
    <div class="bg-[#fcf8e3] border border-[#faebcc] rounded mt-3 p-3 flex items-start">
      <span class="text-[#a77200] leading-6 mr-2">!</span>
      <p class="text-[#a77200] leading-6">我的账单仅显示您使用第三方支付产生的“充值、提现、消费”记录，余额账户的交易记录请到“我的余额”模块查看；</p>
    </div>

    <!-- 筛选条件：两列布局 -->
    <div class="mt-3">
      <ul class="w-full clearfix">
        <li class="float-left w-[45%] mr-[5%] mt-[5px] mb-[5px] min-h-[34px]">
          <span class="inline-block w-[100px] text-right align-top leading-[34px] mr-[10px]">SaleYee单号：</span>
          <input v-model.trim="filters.orderNo" type="text" class="h-[34px] border border-slate-300 rounded px-2 w-[calc(100%-115px)]" />
        </li>
        <li class="float-left w-[45%] mr-[5%] mt-[5px] mb-[5px] min-h-[34px]">
          <span class="inline-block w-[100px] text-right align-top leading-[34px] mr-[10px]">交易类型：</span>
          <select v-model="filters.dealType" class="h-[34px] border border-slate-300 rounded px-2 w-[calc(100%-115px)]">
            <option value="">全部</option>
            <option value="充值">充值</option>
            <option value="消费">消费</option>
            <option value="提现">提现</option>
          </select>
        </li>
        <li class="float-left w-[45%] mr-[5%] mt-[5px] mb-[5px] min-h-[34px]">
          <div class="flex items-center">
            <span class="inline-block w-[100px] text-right leading-[34px] mr-[10px]">交易时间：</span>
            <div class="flex items-center gap-2 w-[calc(100%-115px)] whitespace-nowrap">
              <input v-model="filters.startDate" type="date" placeholder="开始日期" class="h-[34px] border border-slate-300 rounded px-2 flex-1 min-w-0" />
              <span class="mx-1">-</span>
              <input v-model="filters.endDate" type="date" placeholder="结束日期" class="h-[34px] border border-slate-300 rounded px-2 flex-1 min-w-0" />
            </div>
          </div>
        </li>
        <li class="float-left w-[45%] mr-[5%] mt-[5px] mb-[5px] min-h-[34px]">
          <span class="inline-block w-[100px] text-right align-top leading-[34px] mr-[10px]">币别：</span>
          <select v-model="filters.currency" class="h-[34px] border border-slate-300 rounded px-2 w-[calc(100%-115px)]">
            <option value="">全部</option>
            <option value="USD">USD</option>
            <option value="GBP">GBP</option>
            <option value="EUR">EUR</option>
            <option value="CAD">CAD</option>
          </select>
        </li>
      </ul>
      <div class="clear-both"></div>
      <div class="w-full text-center mt-2">
        <button type="button" @click="onSearch" class="inline-block bg-primary text-white rounded w-[100px] h-[38px] leading-[38px]">搜索</button>
      </div>
    </div>

    <!-- 表格 -->
    <div class="mt-4 overflow-x-auto">
      <table class="w-full border-collapse bg-white">
        <thead>
          <tr class="bg-[#f2f2f2]">
            <th class="th w-[42px]"><input type="checkbox" class="h-4 w-4" :checked="isAllChecked" @change="toggleAll($event)" /></th>
            <th class="th">SaleYee单号</th>
            <th class="th">原支付交易号</th>
            <th class="th">金额</th>
            <th class="th">手续费</th>
            <th class="th w-[70px]">币别</th>
            <th class="th">交易类型</th>
            <th class="th">交易方式</th>
            <th class="th">交易账户</th>
            <th class="th">交易时间</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="pagedRows.length === 0">
            <td :colspan="10" class="text-center text-slate-500 py-10">没有找到数据，您可以更换搜索条件</td>
          </tr>
          <tr v-for="r in pagedRows" :key="r.id">
            <td class="td"><input type="checkbox" class="h-4 w-4" v-model="r.selected" /></td>
            <td class="td">{{ r.orderNo }}</td>
            <td class="td">{{ r.payTradeNo }}</td>
            <td class="td">{{ r.amount.toFixed(2) }}</td>
            <td class="td">{{ r.fee.toFixed(2) }}</td>
            <td class="td">{{ r.currency }}</td>
            <td class="td">{{ r.dealType }}</td>
            <td class="td">{{ r.method }}</td>
            <td class="td">{{ r.account }}</td>
            <td class="td">{{ r.time }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 分页 -->
    <div class="mt-4 flex justify-end">
      <el-pagination
        background
        layout="prev, pager, next, jumper, sizes, total"
        :total="filteredRows.length"
        :current-page="currentPage"
        :page-size="pageSize"
        :page-sizes="[10, 20, 50]"
        @current-change="(p:number)=> currentPage = p"
        @size-change="(s:number)=> { pageSize = s; currentPage = 1 }"
      />
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'

const allRows = ref(generateDemoRows())

const filters = reactive({
  orderNo: '',
  dealType: '',
  startDate: '',
  endDate: '',
  currency: '',
})

const currentPage = ref(1)
const pageSize = ref(10)

const filteredRows = computed(() => {
  const start = filters.startDate ? new Date(filters.startDate).getTime() : -Infinity
  const end = filters.endDate ? new Date(filters.endDate).getTime() + 24*60*60*1000 - 1 : Infinity
  const orderNo = filters.orderNo.trim().toLowerCase()
  return allRows.value.filter(r =>
    (!orderNo || r.orderNo.toLowerCase().includes(orderNo)) &&
    (!filters.dealType || r.dealType === filters.dealType) &&
    (!filters.currency || r.currency === filters.currency) &&
    (new Date(r.time).getTime() >= start && new Date(r.time).getTime() <= end)
  )
})

const pagedRows = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredRows.value.slice(start, start + pageSize.value)
})

const isAllChecked = computed(() => pagedRows.value.length > 0 && pagedRows.value.every(r => r.selected))

function toggleAll(e) {
  const checked = e.target.checked
  pagedRows.value.forEach(r => (r.selected = checked))
}

function onSearch() {
  currentPage.value = 1
}

function generateDemoRows() {
  const currencies = ['USD','GBP','EUR','CAD']
  const types = ['充值','消费','提现']
  const methods = ['Payoneer','Airwallex','Bank','Paypal']
  const rows = []
  const now = new Date()
  for (let i=0;i<53;i++) {
    const d = new Date(now.getTime() - i*86400000)
    const currency = currencies[i % currencies.length]
    const dealType = types[i % types.length]
    const amount = Math.round((Math.random()*500+10)*100)/100
    const fee = Math.round((amount*0.01)*100)/100
    rows.push({
      id: i+1,
      selected: false,
      orderNo: `SY${String(100000+i)}`,
      payTradeNo: `PT${String(900000+i)}`,
      amount,
      fee,
      currency,
      dealType,
      method: methods[i % methods.length],
      account: `${currency}-ACC-${(i%7)+1}`,
      time: `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} 0${i%9}:1${i%6}:2${i%6}`,
    })
  }
  return rows
}
</script>

<style scoped>
.th { @apply border border-slate-300 px-4 py-2 text-sm text-center align-middle; }
.td { @apply border border-slate-300 px-4 py-2 text-sm text-left; }
.clear-both { clear: both; }
</style>

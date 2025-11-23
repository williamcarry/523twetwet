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
    <!-- 标题栏 -->
    <div class="h-[50px] pt-[10px] relative flex items-center justify-between">
      <h6 class="text-[16px] font-bold leading-[40px] text-slate-900">我的采购券</h6>
      <a target="_blank" href="https://www.saleyee.com/guide/hp319711.html" class="text-primary">采购券使用说明&gt;</a>
    </div>

    <!-- 顶部标签 + 领取更多 -->
    <div class="relative mt-2">
      <ul class="border-b border-slate-200 h-10 flex items-center gap-2 text-sm">
        <li :class="['px-5 h-10 flex items-center cursor-pointer', activeTab==='unused' ? 'text-primary' : 'text-slate-700']" @click="activeTab='unused'">未使用</li>
        <li :class="['px-5 h-10 flex items-center cursor-pointer', activeTab==='used' ? 'text-primary' : 'text-slate-700']" @click="activeTab='used'">已使用</li>
        <li :class="['px-5 h-10 flex items-center cursor-pointer', activeTab==='expired' ? 'text-primary' : 'text-slate-700']" @click="activeTab='expired'">已过期</li>
      </ul>
      <a target="_blank" href="https://www.saleyee.com/get-started.html" class="absolute right-0 top-0 h-10 leading-10 text-primary">领取更多采购券 &gt;</a>
    </div>

    <!-- 排序条 -->
    <div class="bg-slate-100 h-10 leading-10 px-5 mt-5 flex items-center gap-2 text-sm">
      <span>排序：</span>
      <div class="flex items-center gap-2">
        <a href="javascript:void(0);" :class="['px-2', sort==='new' ? 'text-primary' : 'text-slate-700']" @click="setSort('new')">新到账</a>
        <a href="javascript:void(0);" :class="['px-2', sort==='expiring' ? 'text-primary' : 'text-slate-700']" @click="setSort('expiring')">即将过期</a>
      </div>
    </div>

    <!-- 列表 -->
    <ul class="grid grid-cols-2 gap-5 mt-5">
      <li v-for="c in pagedCoupons" :key="c.id" class="flex w-full">
        <!-- 左侧金额票面 -->
        <div class="relative w-[200px] bg-[#fef1e8] flex flex-col items-center justify-center py-14 border-r border-dashed border-[rgba(203,38,38,0.2)]">
          <p class="text-[28px] font-extrabold text-primary leading-[38px]">{{ c.currency }} {{ c.amount.toFixed(2) }}</p>
          <span class="text-[13px] text-slate-700">满{{ c.currency }} {{ c.threshold.toFixed(2) }}可用</span>
          <em class="absolute left-0 top-0 bg-[#ffea9b] text-[#ff8000] text-[12px] leading-6 px-2">{{ c.tag }}</em>
          <div class="absolute right-[-1px] top-[-1px] w-[10px] h-[10px] bg-white rounded-b-[20px] border border-[#fef1e8] border-t-white border-r-white"></div>
          <div class="absolute right-[-1px] bottom-[-1px] w-[10px] h-[10px] bg-white rounded-tl-[20px] border border-[#fef1e8] border-b-white border-r-white"></div>
        </div>
        <!-- 右侧详情 -->
        <div class="relative flex-1 bg-[#fef1e8] p-5">
          <ul class="mb-4 text-sm text-slate-700 space-y-1">
            <li class="flex leading-6"><em class="text-slate-500 mr-2">使用期限：</em>{{ c.validFrom }} - {{ c.validTo }}</li>
            <li class="flex leading-6"><em class="text-slate-500 mr-2">使用要求：</em><span class="cursor-pointer">{{ c.scope }} &gt;</span></li>
            <li class="flex leading-6"><em class="text-slate-500 mr-2">采购券编号：</em>{{ c.code }}</li>
            <li class="flex leading-6"><em class="text-slate-500 mr-2">领取时间：</em>{{ c.receivedAt }}</li>
          </ul>
          <a v-if="activeTab==='unused'" href="javascript:void(0)" class="inline-block bg-primary text-white h-10 leading-10 px-7 rounded">立即使用</a>
          <div class="absolute left-[-1px] top-[-1px] w-[10px] h-[10px] bg-white border border-[#fef1e8] border-t-white border-l-white rounded-br-[20px]"></div>
          <div class="absolute left-[-1px] bottom-[-1px] w-[10px] h-[10px] bg-white border border-[#fef1e8] border-b-white border-l-white rounded-tr-[20px]"></div>
        </div>
      </li>
    </ul>

    <!-- 分页 -->
    <div class="mt-2 flex justify-end">
      <el-pagination
        background
        layout="prev, pager, next, jumper, sizes, total"
        :total="filteredCoupons.length"
        :current-page="page"
        :page-size="pageSize"
        :page-sizes="[6, 12, 24]"
        @current-change="(p:number)=> page = p"
        @size-change="(s:number)=> { pageSize = s; page = 1 }"
      />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'

const activeTab = ref('unused')
const sort = ref('new')
const page = ref(1)
const pageSize = ref(6)

const coupons = ref(generateDemo())

const filteredCoupons = computed(()=>{
  const list = coupons.value.filter(c=>c.status===activeTab.value)
  if (sort.value==='expiring') {
    return list.sort((a,b)=> new Date(a.validTo).getTime()-new Date(b.validTo).getTime())
  }
  return list.sort((a,b)=> new Date(b.receivedAt).getTime()-new Date(a.receivedAt).getTime())
})

const pagedCoupons = computed(()=>{
  const start = (page.value-1)*pageSize.value
  return filteredCoupons.value.slice(start, start+pageSize.value)
})

function setSort(s) { sort.value = s; page.value = 1 }

function generateDemo() {
  const currs = ['USD','GBP','EUR','CAD']
  const res = []
  const now = new Date()
  let id = 1
  for (let i=0;i<18;i++) {
    const currency = currs[i%currs.length]
    const amount = [1,3,5,10][i%4]
    const threshold = 25 + (i%4)*25
    const received = new Date(now.getTime()-i*86400000)
    const validTo = new Date(now.getTime() + (i%6+3)*86400000)
    res.push({
      id: id++,
      status: (i%3===0?'unused': i%3===1?'used':'expired'),
      currency: currency,
      amount,
      threshold,
      tag: '平台赠券',
      validFrom: `${received.getFullYear()}.${String(received.getMonth()+1).padStart(2,'0')}.${String(received.getDate()).padStart(2,'0')}`,
      validTo: `${validTo.getFullYear()}.${String(validTo.getMonth()+1).padStart(2,'0')}.${String(validTo.getDate()).padStart(2,'0')}`,
      scope: '全部商品可用',
      code: `CND${received.getFullYear().toString().slice(2)}${String(received.getMonth()+1).padStart(2,'0')}${String(received.getDate()).padStart(2,'0')}${String(200000+i)}`,
      receivedAt: `${received.getFullYear()}.${String(received.getMonth()+1).padStart(2,'0')}.${String(received.getDate()).padStart(2,'0')} 20:50:00`,
    })
  }
  return res
}
</script>

<style scoped>
.text-primary{ color:#CB261C; }
.bg-primary{ background-color:#CB261C; }
</style>

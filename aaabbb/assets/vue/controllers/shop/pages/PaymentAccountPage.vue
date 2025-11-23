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
      <h6 class="text-[16px] font-bold leading-[40px] text-slate-900 float-left">支付账号管理</h6>
    </div>

    <!-- 提示条 -->
    <div class="bg-[#fcf8e3] border border-[#faebcc] rounded mt-3 p-3 flex items-start">
      <span class="text-[#a77200] leading-6 mr-2">!</span>
      <p class="text-[#a77200] leading-6">Payoneer账号最多可添加5个，您可先删除不用账号，待客户经理审核通过后，再添��新账号；绑定账号请���写您的支付账户（邮箱/手机号）；空中云汇不支持授权绑定账号。</p>
    </div>

    <!-- 筛选条件：两列布局 -->
    <div class="mt-3">
      <ul class="w-full clearfix">
        <li class="float-left w-[45%] mr-[5%] mt-[5px] mb-[5px] min-h-[34px]">
          <span class="inline-block w-[100px] text-right align-top leading-[34px] mr-[10px]">支付方式：</span>
          <select v-model="filters.paymentType" class="h-[34px] border border-slate-300 rounded px-2 w-[calc(100%-115px)]">
            <option value="">全部</option>
            <option value="Payoneer">Payoneer</option>
            <option value="Airwallex">空中云汇</option>
          </select>
        </li>
        <li class="float-left w-[45%] mr-[5%] mt-[5px] mb-[5px] min-h-[34px]">
          <span class="inline-block w-[100px] text-right align-top leading-[34px] mr-[10px]">绑定状态：</span>
          <select v-model="filters.bindStatus" class="h-[34px] border border-slate-300 rounded px-2 w-[calc(100%-115px)]">
            <option value="">全部</option>
            <option value="未绑定">未绑定</option>
            <option value="已绑定">已绑定</option>
            <option value="未激活">未激活</option>
          </select>
        </li>
      </ul>
      <div class="clear-both"></div>
      <div class="w-full text-center mt-2">
        <button type="button" @click="onSearch" class="inline-block bg-primary text-white rounded w-[100px] h-[38px] leading-[38px]">搜索</button>
      </div>
    </div>

    <!-- 操作区 -->
    <div class="mt-4">
      <el-button type="primary" size="small" @click="openAdd">添加账号</el-button>
    </div>

    <!-- 表格 -->
    <div class="mt-3 overflow-x-auto">
      <table class="w-full border-collapse bg-white">
        <thead>
          <tr class="bg-[#f2f2f2]">
            <th class="th">支付方式</th>
            <th class="th">账户</th>
            <th class="th">绑定状态</th>
            <th class="th">创建时间</th>
            <th class="th">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="pagedRows.length === 0">
            <td :colspan="5" class="text-center text-slate-500 py-10">没有找到数据，您可以更换搜索���件</td>
          </tr>
          <tr v-for="r in pagedRows" :key="r.id">
            <td class="td">{{ r.type }}</td>
            <td class="td">{{ r.account }}</td>
            <td class="td">{{ r.status }}</td>
            <td class="td">{{ r.createdAt }}</td>
            <td class="td space-x-2">
              <el-button v-if="r.status!=='已绑定'" size="small" @click="bind(r)">绑定</el-button>
              <el-button v-else size="small" @click="unbind(r)">解除</el-button>
              <el-button size="small" type="danger" @click="removeRow(r.id)">删除</el-button>
            </td>
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
        :current-page="page"
        :page-size="pageSize"
        :page-sizes="[10, 20, 50]"
        @current-change="(p:number)=> page = p"
        @size-change="(s:number)=> { pageSize = s; page = 1 }"
      />
    </div>

    <!-- 添加账号弹窗 -->
    <el-dialog v-model="addVisible" title="添加账号" width="480">
      <div class="space-y-4">
        <div class="flex items-center">
          <span class="inline-block w-[90px] text-right mr-3">支付方式</span>
          <el-select v-model="form.type" placeholder="请选择">
            <el-option label="Payoneer" value="Payoneer" />
            <el-option label="空中云汇" value="Airwallex" />
          </el-select>
        </div>
        <div class="flex items-center">
          <span class="inline-block w-[90px] text-right mr-3">账��</span>
          <el-input v-model="form.account" placeholder="邮箱或手机号" />
        </div>
      </div>
      <template #footer>
        <el-button @click="addVisible=false">取消</el-button>
        <el-button type="primary" @click="confirmAdd">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'

const allRows = ref(generateDemo())

const filters = reactive({ paymentType:'', bindStatus:'' })

const page = ref(1)
const pageSize = ref(10)

const filteredRows = computed(()=> allRows.value.filter(r =>
  (!filters.paymentType || r.type === filters.paymentType) &&
  (!filters.bindStatus || r.status === filters.bindStatus)
))

const pagedRows = computed(()=>{
  const start = (page.value-1)*pageSize.value
  return filteredRows.value.slice(start, start+pageSize.value)
})

function onSearch(){ page.value = 1 }

function removeRow(id){ allRows.value = allRows.value.filter(r=>r.id!==id) }
function bind(r){ r.status = '已绑定' }
function unbind(r){ r.status = '未绑定' }

const addVisible = ref(false)
const form = reactive({ type:'', account:'' })
function openAdd(){ addVisible.value = true }
function confirmAdd(){
  if(!form.type || !form.account) return
  const d = new Date()
  allRows.value.unshift({ id: Date.now(), type: form.type, account: form.account, status:'未激活', createdAt: fmt(d) })
  addVisible.value = false
  form.type = ''
  form.account = ''
  page.value = 1
}

function generateDemo() {
  const list = []
  const types = ['Payoneer','Airwallex']
  const statuses = ['未绑定','已绑定','未激活']
  const now = new Date()
  for(let i=0;i<23;i++){
    const d = new Date(now.getTime() - i*86400000)
    list.push({ id:i+1, type: types[i%2], account: `${types[i%2]}-user${i}@mail.com`, status: statuses[i%3], createdAt: fmt(d) })
  }
  return list
}

function fmt(d){
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}:${String(d.getSeconds()).padStart(2,'0')}`
}
</script>

<style scoped>
.th { @apply border border-slate-300 px-4 py-2 text-sm text-center align-middle; }
.td { @apply border border-slate-300 px-4 py-2 text-sm text-left; }
.clear-both { clear: both; }
</style>

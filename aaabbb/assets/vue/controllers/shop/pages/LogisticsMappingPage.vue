<template>
  <div class="bg-white rounded-lg border border-slate-200 p-6">
    <!-- 标题 -->
    <div class="mb-6">
      <h6 class="text-[16px] font-bold text-slate-900">物流映射</h6>
    </div>

    <!-- 筛选表单 -->
    <div class="grid grid-cols-2 gap-6 mb-6">
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">平台账号</label>
        <input
          v-model.trim="filters.platformAccount"
          type="text"
          class="w-full h-10 border border-slate-300 rounded px-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">平台物流ID</label>
        <input
          v-model.trim="filters.platformShipId"
          type="text"
          class="w-full h-10 border border-slate-300 rounded px-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">平台物流名称</label>
        <input
          v-model.trim="filters.platformShipName"
          type="text"
          class="w-full h-10 border border-slate-300 rounded px-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">SaleYee物流ID</label>
        <input
          v-model.trim="filters.saleYeeShipId"
          type="text"
          class="w-full h-10 border border-slate-300 rounded px-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">SaleYee物流名称</label>
        <input
          v-model.trim="filters.saleYeeShipName"
          type="text"
          class="w-full h-10 border border-slate-300 rounded px-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">创建时间</label>
        <div class="flex gap-2">
          <input
            v-model="filters.createStartStr"
            type="date"
            class="flex-1 h-10 border border-slate-300 rounded px-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
          />
          <span class="flex items-center text-slate-400">-</span>
          <input
            v-model="filters.createEndStr"
            type="date"
            class="flex-1 h-10 border border-slate-300 rounded px-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
          />
        </div>
      </div>
    </div>

    <!-- 搜索按钮 -->
    <div class="text-center mb-6">
      <button
        @click="onSearch"
        type="button"
        class="px-8 py-2 bg-primary text-white rounded-lg hover:bg-red-700 transition font-medium text-sm"
      >
        搜索
      </button>
    </div>

    <!-- 操作按钮 -->
    <div class="flex gap-2 mb-6">
      <button
        @click="openAdd"
        type="button"
        class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-red-700 transition font-medium text-sm"
      >
        添加物流映射
      </button>
      <label class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-red-700 transition font-medium text-sm cursor-pointer">
        <span>批量导入物流映射</span>
        <input
          type="file"
          accept=".csv,.txt"
          class="hidden"
          @change="handleFileImport"
        />
      </label>
      <button
        @click="downloadTemplate"
        type="button"
        class="px-4 py-2 border border-primary text-primary rounded-lg hover:bg-red-50 transition font-medium text-sm"
      >
        导入模板下载
      </button>
    </div>

    <!-- 表格 -->
    <div class="border border-slate-200 rounded-lg overflow-x-auto mb-6">
      <table class="w-full border-collapse">
        <thead class="bg-slate-50 border-b border-slate-200">
          <tr>
            <th class="th">
              <input
                type="checkbox"
                class="w-4 h-4 accent-primary rounded"
                :checked="isAllSelected"
                @change="toggleSelectAll"
              />
            </th>
            <th class="th">平台类型</th>
            <th class="th">平台账号</th>
            <th class="th">平台物流ID</th>
            <th class="th">平台物流名称</th>
            <th class="th">SaleYee物流ID</th>
            <th class="th">SaleYee物流名称</th>
            <th class="th">SaleYee物流公司</th>
            <th class="th">创建时间</th>
            <th class="th">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in pagedData" :key="row.id" class="border-b border-slate-200 hover:bg-slate-50">
            <td class="td">
              <input
                type="checkbox"
                class="w-4 h-4 accent-primary rounded"
                :checked="selected.some(s => s.id === row.id)"
                @change="toggleRow(row)"
              />
            </td>
            <td class="td">{{ row.platformType }}</td>
            <td class="td">{{ row.platformAccount }}</td>
            <td class="td">{{ row.platformShipId }}</td>
            <td class="td">{{ row.platformShipName }}</td>
            <td class="td">{{ row.saleYeeShipId }}</td>
            <td class="td">{{ row.saleYeeShipName }}</td>
            <td class="td">{{ row.saleYeeCompany }}</td>
            <td class="td">{{ row.createdAt }}</td>
            <td class="td">
              <div class="flex gap-2">
                <button
                  @click="editRow(row)"
                  type="button"
                  class="text-primary hover:underline text-sm font-medium"
                >
                  编辑
                </button>
                <button
                  @click="removeRow(row)"
                  type="button"
                  class="text-red-600 hover:underline text-sm font-medium"
                >
                  删除
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 分页 -->
    <div class="flex items-center justify-end gap-2">
      <button
        @click="previousPage"
        :disabled="page === 1"
        class="px-3 py-2 text-sm border border-slate-300 rounded hover:bg-slate-100 disabled:opacity-50 disabled:cursor-not-allowed transition"
      >
        上一页
      </button>

      <div class="flex gap-1">
        <button
          v-for="p in paginationRange"
          :key="p"
          @click="goToPage(p)"
          :disabled="p === '...'"
          :class="[
            'px-3 py-2 text-sm rounded transition',
            page === p
              ? 'bg-primary text-white border border-primary'
              : 'border border-slate-300 hover:bg-slate-100 disabled:cursor-not-allowed'
          ]"
        >
          {{ p }}
        </button>
      </div>

      <button
        @click="nextPage"
        :disabled="page === totalPages"
        class="px-3 py-2 text-sm border border-slate-300 rounded hover:bg-slate-100 disabled:opacity-50 disabled:cursor-not-allowed transition"
      >
        下一页
      </button>

      <span class="text-sm text-slate-600 ml-4">
        共 {{ filtered.length }} 条
      </span>
    </div>

    <!-- 编辑/新增对话框 -->
    <div v-if="dialog.visible" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <!-- 对话框头部 -->
        <div class="flex justify-between items-center p-6 border-b border-slate-200 bg-slate-50">
          <h3 class="text-lg font-semibold text-slate-900">
            {{ dialog.editing ? '编辑物流映射' : '新增物流映射' }}
          </h3>
          <button
            @click="dialog.visible = false"
            class="text-slate-500 hover:text-slate-700 transition"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- 对话框内容 -->
        <div class="p-6 space-y-6">
          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">平台类型</label>
            <select
              v-model="dialog.form.platformType"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent bg-white"
            >
              <option v-for="t in platformTypes" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">平台账号</label>
            <input
              v-model.trim="dialog.form.platformAccount"
              type="text"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">平台物流ID</label>
            <input
              v-model.trim="dialog.form.platformShipId"
              type="text"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">平台物流名称</label>
            <input
              v-model.trim="dialog.form.platformShipName"
              type="text"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">SaleYee物流ID</label>
            <input
              v-model.trim="dialog.form.saleYeeShipId"
              type="text"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">SaleYee物流名称</label>
            <input
              v-model.trim="dialog.form.saleYeeShipName"
              type="text"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">SaleYee物流公司</label>
            <input
              v-model.trim="dialog.form.saleYeeCompany"
              type="text"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
          </div>
        </div>

        <!-- 对话框底部 -->
        <div class="flex justify-end gap-2 p-6 border-t border-slate-200 bg-slate-50">
          <button
            @click="dialog.visible = false"
            type="button"
            class="px-4 py-2 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-100 transition text-sm font-medium"
          >
            取消
          </button>
          <button
            @click="saveDialog"
            type="button"
            class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-red-700 transition text-sm font-medium"
          >
            保存
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'

const platformTypes = ['WISH', 'eBay', '美国亚马逊', '加拿大亚马逊', '墨西哥亚马逊', '英国亚马逊', '法国亚马逊', '德国亚马逊', '意大利亚马逊', '西班牙亚马逊', '赛盒', 'Shopify', 'EKM', 'Walmart']

const filters = reactive({
  platformAccount: '',
  platformShipId: '',
  platformShipName: '',
  saleYeeShipId: '',
  saleYeeShipName: '',
  createStartStr: '',
  createEndStr: '',
})

const data = ref([
  { id: 101, platformType: 'Shopify', platformAccount: 'shopify-store-1', platformShipId: 'shipsy_001', platformShipName: 'USPS Priority', saleYeeShipId: 'SY-EXP-01', saleYeeShipName: '赛盈美国专线(快)', saleYeeCompany: '赛盈物流', createdAt: '2025-01-05 10:00:00' },
  { id: 102, platformType: 'eBay', platformAccount: 'ebay_seller_cn', platformShipId: 'EB-STD', platformShipName: 'Standard Shipping', saleYeeShipId: 'SY-UK-01', saleYeeShipName: '英国本地递', saleYeeCompany: '赛盈物流UK', createdAt: '2025-01-04 11:22:00' },
  { id: 103, platformType: '美国亚马逊', platformAccount: 'amazon-us-01', platformShipId: 'AMZ-FBA', platformShipName: 'Amazon Logistics', saleYeeShipId: 'SY-US-EC-02', saleYeeShipName: '美国经济线', saleYeeCompany: '赛盈美国', createdAt: '2025-01-03 09:15:00' },
  { id: 104, platformType: '英国亚马逊', platformAccount: 'amazon-uk-main', platformShipId: 'AMZ-UK-STD', platformShipName: 'Amazon UK Standard', saleYeeShipId: 'SY-UK-EC-01', saleYeeShipName: '英国经济线', saleYeeCompany: '赛盈英国', createdAt: '2025-01-02 16:45:00' },
  { id: 105, platformType: 'Walmart', platformAccount: 'walmart-pro-1', platformShipId: 'WM-EX', platformShipName: 'Walmart Express', saleYeeShipId: 'SY-US-EX-01', saleYeeShipName: '美国特快线', saleYeeCompany: '赛盈美国', createdAt: '2025-01-01 08:30:00' },
])

const selected = ref([])
const page = ref(1)
const pageSize = ref(10)

const filtered = computed(() => {
  return data.value.filter((row) => {
    if (filters.platformAccount && !row.platformAccount.includes(filters.platformAccount)) return false
    if (filters.platformShipId && !row.platformShipId.includes(filters.platformShipId)) return false
    if (filters.platformShipName && !row.platformShipName.includes(filters.platformShipName)) return false
    if (filters.saleYeeShipId && !row.saleYeeShipId.includes(filters.saleYeeShipId)) return false
    if (filters.saleYeeShipName && !row.saleYeeShipName.includes(filters.saleYeeShipName)) return false
    return true
  })
})

const totalPages = computed(() => Math.ceil(filtered.value.length / pageSize.value))

const pagedData = computed(() => {
  const start = (page.value - 1) * pageSize.value
  return filtered.value.slice(start, start + pageSize.value)
})

const isAllSelected = computed(() => {
  return pagedData.value.length > 0 && pagedData.value.every(row => selected.value.some(s => s.id === row.id))
})

const paginationRange = computed(() => {
  const delta = 2
  const left = Math.max(1, page.value - delta)
  const right = Math.min(totalPages.value, page.value + delta)
  const range = []

  if (left > 1) {
    range.push(1)
    if (left > 2) {
      range.push('...')
    }
  }

  for (let i = left; i <= right; i++) {
    range.push(i)
  }

  if (right < totalPages.value) {
    if (right < totalPages.value - 1) {
      range.push('...')
    }
    range.push(totalPages.value)
  }

  return range
})

const onSearch = () => {
  page.value = 1
}

const toggleSelectAll = () => {
  if (isAllSelected.value) {
    selected.value = selected.value.filter(s => !pagedData.value.some(r => r.id === s.id))
  } else {
    selected.value = [...selected.value, ...pagedData.value.filter(r => !selected.value.some(s => s.id === r.id))]
  }
}

const toggleRow = (row) => {
  const idx = selected.value.findIndex(s => s.id === row.id)
  if (idx > -1) {
    selected.value.splice(idx, 1)
  } else {
    selected.value.push(row)
  }
}

const dialog = reactive({
  visible: false,
  editing: false,
  form: {
    id: 0,
    platformType: 'Shopify',
    platformAccount: '',
    platformShipId: '',
    platformShipName: '',
    saleYeeShipId: '',
    saleYeeShipName: '',
    saleYeeCompany: '',
  },
})

const openAdd = () => {
  dialog.visible = true
  dialog.editing = false
  dialog.form = { id: 0, platformType: 'Shopify', platformAccount: '', platformShipId: '', platformShipName: '', saleYeeShipId: '', saleYeeShipName: '', saleYeeCompany: '' }
}

const editRow = (row) => {
  dialog.visible = true
  dialog.editing = true
  dialog.form = { ...row }
}

const saveDialog = () => {
  if (dialog.editing) {
    const idx = data.value.findIndex((d) => d.id === dialog.form.id)
    if (idx !== -1) {
      data.value[idx] = { ...dialog.form, id: dialog.form.id }
    }
  } else {
    data.value.unshift({
      ...dialog.form,
      id: Date.now(),
      createdAt: new Date().toISOString().slice(0, 19).replace('T', ' '),
    })
  }
  dialog.visible = false
}

const removeRow = (row) => {
  data.value = data.value.filter((d) => d.id !== row.id)
}

const handleFileImport = async (e) => {
  const file = e.target.files?.[0]
  if (!file) return

  const text = await file.text()
  const rows = text.split(/\r?\n/).filter(Boolean)

  for (const line of rows.slice(1)) {
    const cols = line.split(',')
    if (cols.length < 8) continue

    data.value.push({
      id: Date.now() + Math.floor(Math.random() * 10000),
      platformType: cols[0],
      platformAccount: cols[1],
      platformShipId: cols[2],
      platformShipName: cols[3],
      saleYeeShipId: cols[4],
      saleYeeShipName: cols[5],
      saleYeeCompany: cols[6],
      createdAt: cols[7] || new Date().toISOString().slice(0, 19).replace('T', ' '),
    })
  }

  page.value = 1
  e.target.value = ''
}

const downloadTemplate = () => {
  const header = ['平台类型', '平台账号', '平台物流ID', '平台物流名称', 'SaleYee物流ID', 'SaleYee物流名称', 'SaleYee物流公司', '创建时间']
  const csv = header.join(',') + '\n'
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'logistics-mapping-template.csv'
  a.click()
  URL.revokeObjectURL(url)
}

function previousPage() {
  if (page.value > 1) {
    page.value--
  }
}

function nextPage() {
  if (page.value < totalPages.value) {
    page.value++
  }
}

function goToPage(p) {
  if (typeof p === 'number') {
    page.value = p
  }
}
</script>

<style scoped>
.th {
  @apply px-4 py-3 text-sm text-center align-middle font-medium text-slate-700;
}

.td {
  @apply px-4 py-3 text-sm text-left;
}
</style>

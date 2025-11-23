<template>
  <div class="bg-white rounded-lg border border-slate-200 p-6">
    <!-- 标题与说明 -->
    <div class="mb-6">
      <h6 class="text-[16px] font-bold text-slate-900 inline-block">SKU映射</h6>
      <p class="inline-block ml-2 text-slate-700 text-sm">
        （授权成功的账号可通过建立SKU映射的平台订单载入 SaleYee，需要提前维护好 SKU 映射关系。
        <a class="text-primary hover:underline" target="_blank" href="https://www.saleyee.com/guide/hp957996.html">如何映射</a>）
      </p>
    </div>

    <!-- 筛选表单 -->
    <div class="grid grid-cols-2 gap-6 mb-6">
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">平台类型</label>
        <select
          v-model="filters.platformType"
          class="w-full h-10 border border-slate-300 rounded px-3 bg-white focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
        >
          <option v-for="opt in platformOptions" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">平台SKU</label>
        <input
          v-model.trim="filters.platformSku"
          type="text"
          class="w-full h-10 border border-slate-300 rounded px-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">平台账号</label>
        <input
          v-model.trim="filters.platformAccount"
          type="text"
          class="w-full h-10 border border-slate-300 rounded px-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">SaleYee SKU</label>
        <input
          v-model.trim="filters.saleYeeSku"
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
      <div>
        <label class="block text-sm font-medium text-slate-900 mb-2">SKU状态</label>
        <select
          v-model="filters.skuStatus"
          class="w-full h-10 border border-slate-300 rounded px-3 bg-white focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
        >
          <option value="">全部</option>
          <option value="0">下架</option>
          <option value="1">在售</option>
        </select>
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
        添加SKU映射
      </button>
      <label class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-red-700 transition font-medium text-sm cursor-pointer">
        <span>批量导入SKU映射</span>
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

    <!-- 提示条 -->
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
      <div class="flex gap-3">
        <span class="text-yellow-600 flex-shrink-0">!</span>
        <p class="text-sm text-yellow-800">
          在飞刊刊登成功的SKU会自动创建赛盈SKU映射，支持载单。同步库存需要到飞刊"已刊登商品"列表设置同步规则并查看同步结果。
        </p>
      </div>
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
            <th class="th">平台SKU</th>
            <th class="th">SaleYee-SKU*数量*发货区域*发货物流</th>
            <th class="th">SaleYee-SKU状态</th>
            <th class="th">映射来源</th>
            <th class="th">最后更新时间</th>
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
            <td class="td">{{ row.platformLabel }}</td>
            <td class="td">{{ row.account }}</td>
            <td class="td text-slate-700">{{ row.platformSku }}</td>
            <td class="td">{{ row.saleYeeSkuInfo }}</td>
            <td class="td">{{ row.saleStatusLabel }}</td>
            <td class="td">{{ row.source }}</td>
            <td class="td">{{ row.updatedAt }}</td>
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
            {{ dialog.editing ? '编辑SKU映射' : '新增SKU映射' }}
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
              v-model="dialog.form.platform"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent bg-white"
            >
              <option value="">请选择</option>
              <option v-for="opt in platformOptions.filter(o => o.value)" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">平台账号</label>
            <input
              v-model.trim="dialog.form.account"
              type="text"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">平台SKU</label>
            <input
              v-model.trim="dialog.form.platformSku"
              type="text"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">SaleYee SKU</label>
            <input
              v-model.trim="dialog.form.saleYeeSkuInfo"
              type="text"
              placeholder="格式: SKU*数量*区域*物流"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">SKU状态</label>
            <select
              v-model="dialog.form.saleStatus"
              class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent bg-white"
            >
              <option value="1">在售</option>
              <option value="0">下架</option>
            </select>
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

const platformOptions = [
  { value: '', label: '全部' },
  { value: '1', label: 'WISH' },
  { value: '2', label: 'eBay' },
  { value: '3', label: '美国亚马逊' },
  { value: '4', label: '加拿大亚马逊' },
  { value: '5', label: '墨西哥亚马逊' },
  { value: '6', label: '英国亚马逊' },
  { value: '7', label: '法国亚马逊' },
  { value: '8', label: '德国亚马逊' },
  { value: '9', label: '意大利亚马逊' },
  { value: '10', label: '西班牙亚马逊' },
  { value: '11', label: '赛盒' },
  { value: '12', label: 'Shopify' },
  { value: '13', label: 'EKM' },
  { value: '14', label: 'Walmart' },
]

const filters = reactive({
  platformType: '',
  platformSku: '',
  platformAccount: '',
  saleYeeSku: '',
  createStartStr: '',
  createEndStr: '',
  skuStatus: '',
})

const data = ref([
  { id: 1, platform: '12', platformLabel: 'Shopify', account: 'shopify-store-1', platformSku: 'gid://shopify/ProductVariant/1234567890', saleYeeSkuInfo: 'SY-1001*2*US*UPS', saleStatus: '1', saleStatusLabel: '在售', source: '手动', updatedAt: '2025-01-06 12:00:00' },
  { id: 2, platform: '2', platformLabel: 'eBay', account: 'ebay_seller_cn', platformSku: 'EBAY-ABC-001', saleYeeSkuInfo: 'SY-2002*1*UK*RM', saleStatus: '1', saleStatusLabel: '在售', source: '导入', updatedAt: '2025-01-05 09:30:00' },
  { id: 3, platform: '3', platformLabel: '美国亚马逊', account: 'amazon-us-01', platformSku: 'B0TESTSKU1', saleYeeSkuInfo: 'SY-3003*3*US*FEDEX', saleStatus: '0', saleStatusLabel: '下架', source: '手动', updatedAt: '2025-01-03 18:20:00' },
  { id: 4, platform: '6', platformLabel: '英国亚马逊', account: 'amazon-uk-main', platformSku: 'UK-SKU-8899', saleYeeSkuInfo: 'SY-4004*5*UK*DPD', saleStatus: '1', saleStatusLabel: '在售', source: '导入', updatedAt: '2025-01-02 14:10:00' },
  { id: 5, platform: '14', platformLabel: 'Walmart', account: 'walmart-pro-1', platformSku: 'WM-7788-XYZ', saleYeeSkuInfo: 'SY-5005*1*US*USPS', saleStatus: '1', saleStatusLabel: '在售', source: '手动', updatedAt: '2025-01-01 08:00:00' },
])

const selected = ref([])
const page = ref(1)
const pageSize = ref(10)

const filtered = computed(() => {
  return data.value.filter((row) => {
    if (filters.platformType && row.platform !== filters.platformType) return false
    if (filters.platformSku && !row.platformSku.includes(filters.platformSku)) return false
    if (filters.platformAccount && !row.account.includes(filters.platformAccount)) return false
    if (filters.saleYeeSku && !row.saleYeeSkuInfo.includes(filters.saleYeeSku)) return false
    if (filters.skuStatus && row.saleStatus !== filters.skuStatus) return false
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
    platform: '',
    account: '',
    platformSku: '',
    saleYeeSkuInfo: '',
    saleStatus: '1',
  },
})

const openAdd = () => {
  dialog.visible = true
  dialog.editing = false
  dialog.form = { id: 0, platform: '', account: '', platformSku: '', saleYeeSkuInfo: '', saleStatus: '1' }
}

const editRow = (row) => {
  dialog.visible = true
  dialog.editing = true
  dialog.form = {
    id: row.id,
    platform: row.platform,
    account: row.account,
    platformSku: row.platformSku,
    saleYeeSkuInfo: row.saleYeeSkuInfo,
    saleStatus: row.saleStatus,
  }
}

const saveDialog = () => {
  const label = platformOptions.find((o) => o.value === dialog.form.platform)?.label || ''
  if (dialog.editing) {
    const idx = data.value.findIndex((d) => d.id === dialog.form.id)
    if (idx !== -1) {
      data.value[idx] = {
        id: dialog.form.id,
        platform: String(dialog.form.platform),
        platformLabel: label,
        account: dialog.form.account,
        platformSku: dialog.form.platformSku,
        saleYeeSkuInfo: dialog.form.saleYeeSkuInfo,
        saleStatus: dialog.form.saleStatus,
        saleStatusLabel: dialog.form.saleStatus === '1' ? '在售' : '下架',
        source: '手动',
        updatedAt: new Date().toISOString().slice(0, 19).replace('T', ' '),
      }
    }
  } else {
    const id = Date.now()
    data.value.unshift({
      id,
      platform: String(dialog.form.platform),
      platformLabel: label,
      account: dialog.form.account,
      platformSku: dialog.form.platformSku,
      saleYeeSkuInfo: dialog.form.saleYeeSkuInfo,
      saleStatus: dialog.form.saleStatus,
      saleStatusLabel: dialog.form.saleStatus === '1' ? '在售' : '下架',
      source: '手动',
      updatedAt: new Date().toISOString().slice(0, 19).replace('T', ' '),
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
    if (cols.length < 6) continue

    const id = Date.now() + Math.floor(Math.random() * 10000)
    const platform = cols[0]
    const platformLabel = platformOptions.find((o) => o.value === platform)?.label || platform

    data.value.push({
      id,
      platform,
      platformLabel,
      account: cols[1],
      platformSku: cols[2],
      saleYeeSkuInfo: cols[3],
      saleStatus: cols[4] || '1',
      saleStatusLabel: cols[4] === '0' ? '下架' : '在售',
      source: '导入',
      updatedAt: cols[5] || new Date().toISOString().slice(0, 19).replace('T', ' '),
    })
  }

  page.value = 1
  e.target.value = ''
}

const downloadTemplate = () => {
  const header = ['平台类型(数值)', '平台账号', '平台SKU', 'SaleYee-SKU*数量*区域*物流', 'SKU状态(1在售/0下架)', '最后更新时间']
  const csv = header.join(',') + '\n'
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'sku-mapping-template.csv'
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

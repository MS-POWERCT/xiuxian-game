<template>
  <section class="table-layout">
    <aside class="table-list">
      <div class="panel-title"><span>{{ tableGroupTitle }}</span></div>
      <button
        v-for="item in visibleTables"
        :key="item.name"
        type="button"
        class="config-item"
        :class="{ active: item.name === selectedTable }"
        @click="selectTable(item.name)"
      >
        <span>{{ item.label }}</span>
        <small>{{ item.name }}</small>
      </button>
      <p class="muted" v-if="visibleTables.length === 0">当前分组没有可展示的数据表。</p>
    </aside>

    <div class="content-column">
      <div class="panel-title">
        <div>
          <span>{{ selectedLabel || '表数据' }}</span>
          <small class="muted" v-if="total > 0">共 {{ total }} 行</small>
        </div>
        <form class="search-form" @submit.prevent="search">
          <input v-model="keyword" type="search" placeholder="关键词搜索" />
          <button type="submit" :disabled="loading">搜索</button>
        </form>
      </div>

      <p class="notice">只读浏览，敏感字段已在服务端脱敏；仅闭关记录提供“调整结束时间”测试工具。</p>

      <div class="table-scroll" v-if="columns.length > 0">
        <table>
          <thead>
            <tr>
              <th v-for="col in columns" :key="col.name" :title="col.name" @click="sortBy(col.name)">
                {{ col.label }}
                <span v-if="sort === col.name">{{ order === 'asc' ? '↑' : '↓' }}</span>
                <small v-if="col.masked" class="masked-tag">脱敏</small>
              </th>
              <th v-if="isRetreats">测试操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in rows" :key="rowKey(row, index)">
              <td v-for="col in columns" :key="col.name" :title="formatCell(row[col.name], col)">
                {{ formatCell(row[col.name], col) }}
              </td>
              <td v-if="isRetreats">
                <button
                  v-if="row.status === 0 || row.status === '0'"
                  type="button"
                  class="ghost-btn"
                  @click="openFinishDialog(row)"
                >
                  调整结束时间
                </button>
                <span v-else class="muted">已结束</span>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td :colspan="columns.length + (isRetreats ? 1 : 0)" class="empty-cell">没有数据</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="error" v-if="error">{{ error }}</p>
      <div class="pagination">
        <button type="button" :disabled="page <= 1 || loading" @click="goPage(page - 1)">上一页</button>
        <span>第 {{ page }} / {{ pageCount }} 页</span>
        <button type="button" :disabled="page >= pageCount || loading" @click="goPage(page + 1)">下一页</button>
      </div>
    </div>
  </section>

  <div class="modal-mask" v-if="finishDialog">
    <div class="modal">
      <h3>调整闭关结束时间</h3>
      <p class="muted">只修改测试用的 `finish_at`，不会自动结算收益或改变玩家状态。</p>
      <label>
        新结束时间
        <input v-model="finishAtText" type="datetime-local" />
      </label>
      <label>
        当前管理员密码
        <input v-model="finishPassword" type="password" autocomplete="current-password" />
      </label>
      <label>
        测试原因（3-200 字）
        <textarea v-model="finishReason" rows="3" placeholder="例如：测试闭关到期结算"></textarea>
      </label>
      <p class="error" v-if="dialogError">{{ dialogError }}</p>
      <div class="modal-actions">
        <button type="button" @click="setFinishNow">立即到期</button>
        <button type="button" @click="closeFinishDialog" :disabled="finishBusy">取消</button>
        <button type="button" class="primary" :disabled="finishBusy" @click="submitFinishDialog">
          {{ finishBusy ? '保存中…' : '确认修改' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { AdminApiError, api, type TableColumn, type TableItem } from './api'

const props = withDefaults(defineProps<{ scope?: 'game' | 'admin'; initialTable?: string }>(), {
  scope: 'game',
  initialTable: '',
})
const emit = defineEmits<{ unauthorized: [] }>()

const tables = ref<TableItem[]>([])
const selectedTable = ref('')
const columns = ref<TableColumn[]>([])
const rows = ref<Record<string, unknown>[]>([])
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)
const keyword = ref('')
const sort = ref('id')
const order = ref<'asc' | 'desc'>('desc')
const loading = ref(false)
const error = ref('')
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
const visibleTables = computed(() => tables.value.filter((item) => item.group === props.scope))
const selectedLabel = computed(() => visibleTables.value.find((item) => item.name === selectedTable.value)?.label || '')
const isRetreats = computed(() => selectedTable.value === 'retreats')
const tableGroupTitle = computed(() => (props.scope === 'game' ? '主业务数据' : '后台数据'))

const finishDialog = ref(false)
const finishBusy = ref(false)
const dialogError = ref('')
const finishAtText = ref('')
const finishPassword = ref('')
const finishReason = ref('')
const editingRetreatId = ref(0)

function handleError(e: unknown) {
  if (e instanceof AdminApiError && e.code === 6001) {
    emit('unauthorized')
    return
  }
  error.value = e instanceof Error ? e.message : '请求失败'
}

async function loadTables() {
  loading.value = true
  error.value = ''
  try {
    tables.value = (await api.tables()).tables
    const scoped = tables.value.filter((item) => item.group === props.scope)
    const initial = props.initialTable && scoped.some((item) => item.name === props.initialTable)
      ? props.initialTable
      : scoped.some((item) => item.name === selectedTable.value)
        ? selectedTable.value
        : scoped[0]?.name || ''
    selectedTable.value = ''
    if (initial) await selectTable(initial)
  } catch (e) {
    handleError(e)
  } finally {
    loading.value = false
  }
}

async function selectTable(name: string) {
  if (!name) return
  selectedTable.value = name
  page.value = 1
  keyword.value = ''
  sort.value = 'id'
  order.value = 'desc'
  await loadData()
}

async function loadData() {
  if (!selectedTable.value) return
  loading.value = true
  error.value = ''
  try {
    const data = await api.table(selectedTable.value, {
      page: page.value,
      page_size: pageSize.value,
      keyword: keyword.value.trim(),
      sort: sort.value,
      order: order.value,
    })
    columns.value = data.columns
    rows.value = data.rows
    page.value = data.page
    pageSize.value = data.page_size
    total.value = data.total
    if (!columns.value.some((column) => column.name === sort.value) && columns.value[0]) {
      sort.value = columns.value[0].name
    }
  } catch (e) {
    handleError(e)
  } finally {
    loading.value = false
  }
}

function search() {
  page.value = 1
  loadData()
}

function goPage(next: number) {
  page.value = next
  loadData()
}

function sortBy(name: string) {
  if (sort.value === name) {
    order.value = order.value === 'asc' ? 'desc' : 'asc'
  } else {
    sort.value = name
    order.value = 'asc'
  }
  page.value = 1
  loadData()
}

function rowKey(row: Record<string, unknown>, index: number): string {
  return String(row.id ?? `${selectedTable.value}-${index}`)
}

function formatCell(value: unknown, column: TableColumn): string {
  if (column.masked) return '***'
  if (value === null || value === undefined || value === '') return 'NULL'
  if (column.is_time) {
    const timestamp = Number(value)
    if (!Number.isFinite(timestamp) || timestamp <= 0) return String(value)
    return new Date(timestamp * 1000).toLocaleString('zh-CN', { hour12: false })
  }
  if (column.name === 'detail' && typeof value === 'string') {
    try {
      return JSON.stringify(JSON.parse(value), null, 2)
    } catch {
      return value
    }
  }
  if (typeof value === 'object') return JSON.stringify(value)
  const text = String(value)
  return text.length > 240 ? `${text.slice(0, 240)}…` : text
}

function toDatetimeLocal(timestamp: number): string {
  const date = new Date(timestamp * 1000)
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function openFinishDialog(row: Record<string, unknown>) {
  const id = Number(row.id)
  const finishAt = Number(row.finish_at)
  if (!Number.isFinite(id) || !Number.isFinite(finishAt)) return
  editingRetreatId.value = id
  finishAtText.value = toDatetimeLocal(finishAt)
  finishPassword.value = ''
  finishReason.value = '测试闭关时间调整'
  dialogError.value = ''
  finishDialog.value = true
}

function closeFinishDialog() {
  if (!finishBusy.value) finishDialog.value = false
}

function setFinishNow() {
  finishAtText.value = toDatetimeLocal(Math.floor(Date.now() / 1000))
}

async function submitFinishDialog() {
  const timestamp = Math.floor(new Date(finishAtText.value).getTime() / 1000)
  if (!Number.isFinite(timestamp) || timestamp <= 0) {
    dialogError.value = '请选择有效的结束时间'
    return
  }
  finishBusy.value = true
  dialogError.value = ''
  try {
    await api.updateRetreatFinishAt(editingRetreatId.value, {
      finish_at: timestamp,
      password: finishPassword.value,
      reason: finishReason.value.trim(),
    })
    finishDialog.value = false
    await loadData()
  } catch (e) {
    if (e instanceof AdminApiError && e.code === 6001) {
      emit('unauthorized')
      return
    }
    dialogError.value = e instanceof Error ? e.message : '修改失败'
  } finally {
    finishBusy.value = false
  }
}

onMounted(loadTables)
watch(() => props.scope, loadTables)
</script>

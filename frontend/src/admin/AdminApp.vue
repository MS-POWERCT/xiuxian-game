<template>
  <div v-if="!ready" class="boot-screen">正在加载后台…</div>

  <div v-else-if="!loggedIn" class="login-screen">
    <form class="login-card" @submit.prevent="submitLogin">
      <div class="brand-mark">玄</div>
      <h1>文字修仙 · 后台</h1>
      <p class="muted">管理配置、查看数据表和审计日志</p>
      <label>
        管理员账号
        <input v-model="username" type="text" autocomplete="username" autofocus />
      </label>
      <label>
        密码
        <input v-model="password" type="password" autocomplete="current-password" />
      </label>
      <p class="error" v-if="error">{{ error }}</p>
      <button class="primary login-button" type="submit" :disabled="busy">
        {{ busy ? '登录中…' : '进入后台' }}
      </button>
    </form>
  </div>

  <div v-else class="admin-shell">
    <aside class="sidebar">
      <div class="sidebar-brand">
        <div class="brand-mark small">玄</div>
        <div>
          <strong>文字修仙</strong>
          <small>ADMIN CONSOLE</small>
        </div>
      </div>

      <nav>
        <button :class="{ active: view === 'dashboard' }" type="button" @click="openView('dashboard')">概览</button>

        <button :class="{ active: view === 'config' }" type="button" @click="openView('config')">配置管理</button>
        <div class="nav-sub" v-if="view === 'config'">
          <button
            v-for="item in configs"
            :key="item.name"
            type="button"
            class="nav-sub-item"
            :class="{ active: item.name === selectedConfig }"
            @click="selectedConfig = item.name"
          >
            {{ item.label || item.name }}
          </button>
          <p class="nav-empty muted" v-if="configs.length === 0">暂无配置文件</p>
        </div>

        <button :class="{ active: view === 'game' }" type="button" @click="openView('game')">主业务数据</button>
        <div class="nav-sub" v-if="view === 'game'">
          <button
            v-for="item in gameTables"
            :key="item.name"
            type="button"
            class="nav-sub-item"
            :class="{ active: item.name === selectedGameTable }"
            @click="selectedGameTable = item.name"
          >
            {{ item.label }}
          </button>
          <p class="nav-empty muted" v-if="gameTables.length === 0">暂无数据表</p>
        </div>

        <button :class="{ active: view === 'admin' }" type="button" @click="openView('admin')">后台数据</button>
        <div class="nav-sub" v-if="view === 'admin'">
          <button
            v-for="item in adminTables"
            :key="item.name"
            type="button"
            class="nav-sub-item"
            :class="{ active: item.name === selectedAdminTable }"
            @click="selectedAdminTable = item.name"
          >
            {{ item.label }}
          </button>
          <p class="nav-empty muted" v-if="adminTables.length === 0">暂无数据表</p>
        </div>
      </nav>

      <div class="sidebar-footer">
        <span>{{ admin?.username }}</span>
        <button type="button" class="ghost-btn" @click="doLogout">退出</button>
      </div>
    </aside>

    <main class="main-content">
      <header class="topbar">
        <div>
          <h2>{{ pageTitle }}</h2>
          <small class="muted">所有修改都会保留操作审计记录</small>
        </div>
        <div class="topbar-meta">
          <span class="status-dot"></span>
          <span>安全会话已连接</span>
        </div>
      </header>

      <p class="error global-error" v-if="error">{{ error }}</p>

      <DashboardPanel v-if="view === 'dashboard'" :data="dashboard" @refresh="loadDashboard" />
      <ConfigPanel
        v-else-if="view === 'config'"
        :config="selectedConfig"
        :config-label="selectedConfigLabel"
        @unauthorized="handleUnauthorized"
      />
      <TablePanel
        v-else-if="view === 'game'"
        :table="selectedGameTable"
        :table-label="selectedGameTableLabel"
        @unauthorized="handleUnauthorized"
      />
      <TablePanel
        v-else-if="view === 'admin'"
        :table="selectedAdminTable"
        :table-label="selectedAdminTableLabel"
        @unauthorized="handleUnauthorized"
      />
    </main>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  AdminApiError,
  api,
  login as loginApi,
  logout as logoutApi,
  me as meApi,
  type AdminInfo,
  type ConfigItem,
  type Dashboard,
  type TableItem,
} from './api'
import ConfigPanel from './ConfigPanel.vue'
import DashboardPanel from './DashboardPanel.vue'
import TablePanel from './TablePanel.vue'

type View = 'dashboard' | 'config' | 'game' | 'admin'

const ready = ref(false)
const loggedIn = ref(false)
const admin = ref<AdminInfo | null>(null)
const username = ref('admin')
const password = ref('')
const busy = ref(false)
const error = ref('')
const view = ref<View>('dashboard')

// 一级菜单 + 二级选择：二级列表在 AdminApp 统一拉取，面板只负责展示内容
const dashboard = ref<Dashboard | null>(null)
const configs = ref<ConfigItem[]>([])
const tables = ref<TableItem[]>([])
const selectedConfig = ref('')
const selectedGameTable = ref('')
const selectedAdminTable = ref('')
const gameTables = computed(() => tables.value.filter((item) => item.group === 'game'))
const adminTables = computed(() => tables.value.filter((item) => item.group === 'admin'))

function labelOf(list: TableItem[], name: string): string {
  return list.find((item) => item.name === name)?.label ?? ''
}
const selectedConfigLabel = computed(() => configs.value.find((item) => item.name === selectedConfig.value)?.label ?? '')
const selectedGameTableLabel = computed(() => labelOf(gameTables.value, selectedGameTable.value))
const selectedAdminTableLabel = computed(() => labelOf(adminTables.value, selectedAdminTable.value))

const pageTitle = computed(() => {
  if (view.value === 'config') return selectedConfigLabel.value ? `配置管理 · ${selectedConfigLabel.value}` : '配置管理'
  if (view.value === 'game') return selectedGameTableLabel.value ? `主业务数据 · ${selectedGameTableLabel.value}` : '主业务数据'
  if (view.value === 'admin') return selectedAdminTableLabel.value ? `后台数据 · ${selectedAdminTableLabel.value}` : '后台数据'
  return '运行概览'
})

async function boot() {
  try {
    const data = await meApi()
    admin.value = data.admin
    loggedIn.value = true
    await loadAll()
  } catch (e) {
    if (!(e instanceof AdminApiError && e.code === 6001)) {
      error.value = e instanceof Error ? e.message : '后台加载失败'
    }
  } finally {
    ready.value = true
  }
}

async function submitLogin() {
  busy.value = true
  error.value = ''
  try {
    const data = await loginApi(username.value, password.value)
    admin.value = data.admin
    password.value = ''
    loggedIn.value = true
    await loadAll()
  } catch (e) {
    error.value = e instanceof Error ? e.message : '登录失败'
  } finally {
    busy.value = false
  }
}

async function loadAll() {
  await Promise.all([loadDashboard(), loadNav()])
}

async function loadDashboard() {
  try {
    dashboard.value = await api.dashboard()
  } catch (e) {
    handleError(e)
  }
}

// 拉取二级菜单：配置文件列表 + 数据表列表
async function loadNav() {
  try {
    const [configData, tableData] = await Promise.all([api.configs(), api.tables()])
    configs.value = configData.configs
    tables.value = tableData.tables
    if (!selectedConfig.value || !configs.value.some((item) => item.name === selectedConfig.value)) {
      selectedConfig.value = configs.value[0]?.name ?? ''
    }
    if (!gameTables.value.some((item) => item.name === selectedGameTable.value)) {
      selectedGameTable.value = gameTables.value[0]?.name ?? ''
    }
    if (!adminTables.value.some((item) => item.name === selectedAdminTable.value)) {
      selectedAdminTable.value = adminTables.value[0]?.name ?? ''
    }
  } catch (e) {
    handleError(e)
  }
}

function openView(next: View) {
  view.value = next
  error.value = ''
  if (next === 'dashboard') loadDashboard()
}

function handleError(e: unknown) {
  if (e instanceof AdminApiError && e.code === 6001) {
    handleUnauthorized()
    return
  }
  error.value = e instanceof Error ? e.message : '请求失败'
}

function handleUnauthorized() {
  loggedIn.value = false
  admin.value = null
  error.value = ''
  window.setTimeout(() => {
    window.location.reload()
  }, 300)
}

async function doLogout() {
  try {
    await logoutApi()
  } catch {
    // 退出失败也清理本地展示状态，避免保留已失效会话的界面
  } finally {
    loggedIn.value = false
    admin.value = null
  }
}

onMounted(boot)
</script>
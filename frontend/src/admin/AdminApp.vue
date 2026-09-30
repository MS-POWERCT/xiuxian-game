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
        <button :class="{ active: view === 'dashboard' }" type="button" @click="switchView('dashboard')">概览</button>
        <button :class="{ active: view === 'config' }" type="button" @click="switchView('config')">配置管理</button>
        <button :class="{ active: view === 'game' }" type="button" @click="switchView('game')">主业务数据</button>
        <button :class="{ active: view === 'admin' }" type="button" @click="switchView('admin')">后台数据</button>
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

      <DashboardPanel v-if="view === 'dashboard'" :data="dashboard" />
      <ConfigPanel v-else-if="view === 'config'" @unauthorized="handleUnauthorized" />
      <TablePanel v-else-if="view === 'game'" scope="game" @unauthorized="handleUnauthorized" />
      <TablePanel v-else-if="view === 'admin'" scope="admin" @unauthorized="handleUnauthorized" />
    </main>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { AdminApiError, api, login as loginApi, logout as logoutApi, me as meApi, type AdminInfo, type Dashboard } from './api'
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
const dashboard = ref<Dashboard | null>(null)

const pageTitle = computed(() => {
  if (view.value === 'config') return '配置管理'
  if (view.value === 'game') return '主业务数据'
  if (view.value === 'admin') return '后台数据'
  return '运行概览'
})

async function boot() {
  try {
    const data = await meApi()
    admin.value = data.admin
    loggedIn.value = true
    await loadDashboard()
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
    await loadDashboard()
  } catch (e) {
    error.value = e instanceof Error ? e.message : '登录失败'
  } finally {
    busy.value = false
  }
}

async function loadDashboard() {
  try {
    dashboard.value = await api.dashboard()
  } catch (e) {
    handleError(e)
  }
}

function switchView(next: View) {
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

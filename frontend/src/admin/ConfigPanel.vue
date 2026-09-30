<template>
  <section class="split-panel">
    <aside class="config-list">
      <div class="panel-title">
        <span>JSON 配置</span>
        <button class="icon-btn" type="button" :disabled="loading" @click="loadList">刷新</button>
      </div>
      <button
        v-for="item in configs"
        :key="item.name"
        type="button"
        class="config-item"
        :class="{ active: item.name === selectedName }"
        @click="selectConfig(item.name)"
      >
        <span>{{ item.name }}</span>
        <small>{{ formatBytes(item.size) }}</small>
      </button>
      <p class="muted" v-if="!loading && configs.length === 0">没有可读取的配置文件。</p>
    </aside>

    <div class="content-column">
      <div class="panel-title">
        <div>
          <span>{{ detail?.file || '配置详情' }}</span>
          <small class="muted" v-if="detail">更新于 {{ formatTime(detail.updated_at) }}</small>
        </div>
        <div class="button-row">
          <button type="button" :disabled="!detail || loading" @click="openSaveDialog">保存配置</button>
        </div>
      </div>

      <p class="notice">
        保存会先做 JSON 与核心结构校验，自动备份当前文件，再用原子替换写入。配置修改按文件 mtime 热更新。
      </p>

      <textarea
        v-model="content"
        class="json-editor"
        spellcheck="false"
        :disabled="!detail || loading"
        aria-label="配置 JSON 编辑器"
      ></textarea>

      <div class="backup-block">
        <div class="panel-title small-title">
          <span>最近备份</span>
          <small class="muted">最多保留 20 份</small>
        </div>
        <p class="muted" v-if="backups.length === 0">暂无备份。</p>
        <div v-for="backup in backups" :key="backup.name" class="backup-row">
          <span>{{ backup.name }}</span>
          <small>{{ formatBytes(backup.size) }} · {{ formatTime(backup.created_at) }}</small>
          <button type="button" class="ghost-btn" @click="openRestoreDialog(backup.name)">恢复</button>
        </div>
      </div>

      <p class="error" v-if="error">{{ error }}</p>
      <p class="success" v-if="success">{{ success }}</p>
    </div>
  </section>

  <div class="modal-mask" v-if="dialog">
    <div class="modal">
      <h3>{{ dialogTitle }}</h3>
      <p class="muted">
        {{ dialogAction === 'save' ? `即将写入 config/${selectedName}.json` : `即将恢复备份 ${selectedBackup}` }}
      </p>
      <label>
        当前管理员密码
        <input v-model="dialogPassword" type="password" autocomplete="current-password" />
      </label>
      <label>
        操作原因（3-200 字）
        <textarea v-model="dialogReason" rows="3" placeholder="例如：调整练气阶段寿命上限"></textarea>
      </label>
      <div class="modal-actions">
        <button type="button" @click="closeDialog">取消</button>
        <button type="button" class="primary" :disabled="busy" @click="submitDialog">
          {{ busy ? '提交中…' : '确认执行' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { AdminApiError, api, type BackupItem, type ConfigDetail, type ConfigItem } from './api'

const emit = defineEmits<{ unauthorized: [] }>()

const configs = ref<ConfigItem[]>([])
const selectedName = ref('')
const detail = ref<ConfigDetail | null>(null)
const content = ref('')
const backups = ref<BackupItem[]>([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const success = ref('')
const dialog = ref(false)
const dialogAction = ref<'save' | 'restore'>('save')
const dialogPassword = ref('')
const dialogReason = ref('')
const selectedBackup = ref('')

const dialogTitle = computed(() => (dialogAction.value === 'save' ? '确认保存配置' : '确认恢复配置'))

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  return `${(bytes / 1024).toFixed(1)} KB`
}

function formatTime(timestamp: number): string {
  return new Date(timestamp * 1000).toLocaleString('zh-CN', { hour12: false })
}

function handleError(e: unknown) {
  if (e instanceof AdminApiError && e.code === 6001) {
    emit('unauthorized')
    return
  }
  error.value = e instanceof Error ? e.message : '请求失败'
}

async function loadList() {
  loading.value = true
  error.value = ''
  try {
    configs.value = (await api.configs()).configs
    if (!selectedName.value && configs.value[0]) {
      await selectConfig(configs.value[0].name)
    } else if (selectedName.value && configs.value.some((item) => item.name === selectedName.value)) {
      await loadDetail(selectedName.value)
    }
  } catch (e) {
    handleError(e)
  } finally {
    loading.value = false
  }
}

async function selectConfig(name: string) {
  if (!name || name === selectedName.value && detail.value) return
  selectedName.value = name
  await loadDetail(name)
}

async function loadDetail(name: string) {
  loading.value = true
  error.value = ''
  success.value = ''
  try {
    const data = await api.config(name)
    detail.value = data
    content.value = data.content
    backups.value = data.backups
  } catch (e) {
    handleError(e)
  } finally {
    loading.value = false
  }
}

function openSaveDialog() {
  dialogAction.value = 'save'
  selectedBackup.value = ''
  dialogPassword.value = ''
  dialogReason.value = ''
  error.value = ''
  dialog.value = true
}

function openRestoreDialog(backup: string) {
  dialogAction.value = 'restore'
  selectedBackup.value = backup
  dialogPassword.value = ''
  dialogReason.value = ''
  error.value = ''
  dialog.value = true
}

function closeDialog() {
  if (!busy.value) dialog.value = false
}

async function submitDialog() {
  if (!selectedName.value) return
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    if (dialogAction.value === 'save') {
      await api.saveConfig(selectedName.value, {
        content: content.value,
        password: dialogPassword.value,
        reason: dialogReason.value.trim(),
      })
      success.value = '配置已保存，游戏数值将在后续请求中按 mtime 自动重载。'
    } else {
      await api.restoreConfig(selectedName.value, {
        backup: selectedBackup.value,
        password: dialogPassword.value,
        reason: dialogReason.value.trim(),
      })
      success.value = '配置已从备份恢复。'
    }
    dialog.value = false
    await loadDetail(selectedName.value)
    await loadList()
  } catch (e) {
    handleError(e)
  } finally {
    busy.value = false
  }
}

onMounted(loadList)
</script>

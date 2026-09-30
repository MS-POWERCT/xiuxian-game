<template>
  <section class="admin-grid">
    <div class="stat-card" v-for="item in cards" :key="item.label">
      <span>{{ item.label }}</span>
      <strong>{{ item.value }}</strong>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { Dashboard } from './api'

const props = defineProps<{ data: Dashboard | null }>()

const cards = computed(() => [
  { label: '用户账号', value: props.data?.users ?? '--' },
  { label: '玩家角色', value: props.data?.players ?? '--' },
  { label: '存活角色', value: props.data?.alive_players ?? '--' },
  { label: '闭关中', value: props.data?.retreating_players ?? '--' },
  { label: '管理员', value: props.data?.admin_users ?? '--' },
  { label: '配置文件', value: props.data?.configs ?? '--' },
  { label: '服务器时间', value: props.data ? formatTime(props.data.server_time) : '--' },
])

function formatTime(timestamp: number): string {
  return new Date(timestamp * 1000).toLocaleString('zh-CN', { hour12: false })
}
</script>

<template>
  <section class="panel">
    <h3>前世档案</h3>
    <p v-if="loading" class="muted">加载中…</p>
    <p v-else-if="records.length === 0" class="muted">尚无前世档案。转世重修后，每一世的最终状态将在此留存。</p>
    <template v-else>
      <div v-for="r in records" :key="r.life_no" class="life">
        <div class="life-head" @click="toggle(r.life_no)">
          <span class="chevron">{{ open[r.life_no] ? '▾' : '▸' }}</span>
          <span class="head-main">第 {{ r.life_no }} 世 · {{ r.name }}</span>
          <span class="muted">{{ r.realm_name }} · {{ r.stage_name }}</span>
        </div>
        <div v-if="open[r.life_no]" class="life-detail">
          <span class="chip">修为 {{ r.exp }}</span>
          <span class="chip">灵石 {{ r.spirit_stones }}</span>
          <span class="chip">岁数 {{ r.age }}/{{ r.lifespan_max }}</span>
          <span class="chip">气血 {{ r.hp }}/100</span>
          <span class="chip">效率 ×{{ r.cultivate_rate.toFixed(2) }}</span>
          <span class="chip">{{ r.total_days }} 天</span>
          <span class="chip">{{ reasonText(r.death_reason) }}</span>
          <span class="chip">{{ formatTime(r.death_at) }}</span>
        </div>
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { getReincarnationRecords, type ReincarnationRecord } from '@/api/game'
import { usePlayerStore } from '@/stores/player'

const store = usePlayerStore()
const loading = ref(false)
const records = ref<ReincarnationRecord[]>([])
const open = reactive<Record<number, boolean>>({})

async function load() {
  loading.value = true
  try {
    const data = await getReincarnationRecords()
    records.value = data.records
  } catch {
    /* 由 http 层统一处理错误 */
  } finally {
    loading.value = false
  }
}

// 转世后 life_no 变化，自动在原地刷新档案列表（停留在设置页，不跳转）
watch(() => store.player?.life_no, () => {
  if (store.player) load()
})

onMounted(load)

function toggle(lifeNo: number) {
  open[lifeNo] = !open[lifeNo]
}

// death_reason 中文映射：lifespan=寿元耗尽、self=主动兵解、relic=遗迹陨落
const REASON_TEXT: Record<ReincarnationRecord['death_reason'], string> = {
  lifespan: '寿元耗尽',
  self: '主动兵解',
  relic: '遗迹陨落',
}
function reasonText(reason: ReincarnationRecord['death_reason']): string {
  return REASON_TEXT[reason] ?? reason
}

function formatTime(ts: number): string {
  const d = new Date(ts * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}
</script>

<style scoped>
.life {
  border-top: 1px dashed var(--border);
  padding: 6px 0;
}

.life:first-of-type {
  border-top: 0;
  padding-top: 0;
}

.life-head {
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  font-size: 12px;
}

.head-main {
  color: var(--accent);
}

.chevron {
  width: 12px;
  color: var(--muted);
}

.life-detail {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
  margin-top: 6px;
}

.chip {
  font-size: 11px;
  color: var(--muted);
}
</style>
<template>
  <section class="panel">
    <h3>庇护</h3>

    <p v-if="!travel.patronsLoaded" class="muted">加载中…</p>
    <p v-else-if="travel.patrons.length === 0" class="muted">
      尚无庇护对象。外出游历，或许能遇上需要相助的凡人或宗门。
    </p>

    <template v-else>
      <!-- 凡人庇护（纯数字，有上限） -->
      <div v-if="mortal" class="patron-row">
        <div class="patron-main">
          <span class="patron-name">凡人</span>
          <span class="muted patron-meta">
            {{ mortal.count }} / {{ mortalMax }} 人 · 每 {{ mortal.interval_hours }} 小时
            {{ perCapita }} 下品/人
          </span>
          <span class="muted patron-meta">下次上供 {{ nextText(mortal) }}</span>
        </div>
        <span class="pending">{{ pendingText(mortal) }}</span>
      </div>

      <!-- 宗门庇护（有名额上限） -->
      <div v-for="p in sects" :key="p.id" class="patron-row sect">
        <div class="patron-main">
          <span class="patron-name">{{ p.sect_name }}</span>
          <span class="muted patron-meta">
            每 {{ p.interval_hours }} 小时 {{ supplyText(p) }}
          </span>
          <span class="muted patron-meta">下次上供 {{ nextText(p) }}</span>
        </div>
        <div class="patron-side">
          <span class="pending">{{ pendingText(p) }}</span>
          <template v-if="confirmId === p.id">
            <div class="btn-row">
              <button class="primary" data-sfx="click" @click="release(p)">确认解除</button>
              <button class="ghost" @click="confirmId = null">取消</button>
            </div>
          </template>
          <button v-else class="ghost small" @click="confirmId = p.id">
            解除（断缘令×{{ releaseCost(p) }}）
          </button>
        </div>
      </div>

      <button
        class="primary claim-btn"
        :disabled="!hasPending"
        data-sfx="click"
        @click="claim"
      >
        领取上供{{ hasPending ? '：' + totalText : '（暂无可领）' }}
      </button>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import type { Patron, SpiritStones } from '@/api/game'
import { patronConfig, spiritStoneLabels } from '@/config'
import { useTravelStore } from '@/stores/travel'
import { useToastStore } from '@/stores/toast'

const travel = useTravelStore()
const toast = useToastStore()

const now = ref(Math.floor(Date.now() / 1000))
const confirmId = ref<number | null>(null)

const mortalMax = patronConfig.mortal.max_count
const perCapita = patronConfig.mortal.supply_per_capita_low

const mortal = computed(() => travel.patrons.find((p) => p.kind === 'mortal') ?? null)
const sects = computed(() => travel.patrons.filter((p) => p.kind === 'sect'))

function levelLabel(level: string) {
  return spiritStoneLabels[level] ?? level
}

function pendingText(p: Patron): string {
  const parts = Object.entries(p.pending)
    .filter(([, amount]) => amount > 0)
    .map(([level, amount]) => `${levelLabel(level)} ${amount}`)
  return parts.length ? parts.join('，') : '—'
}

function supplyText(p: Patron): string {
  const cfg = patronConfig.sect.levels[p.sect_level]
  if (!cfg) return ''
  return Object.entries(cfg.supply)
    .map(([level, amount]) => `${levelLabel(level)} ${amount}`)
    .join(' + ')
}

function releaseCost(p: Patron): number {
  return patronConfig.release.compensation_by_level[p.sect_level] ?? 0
}

function nextText(p: Patron): string {
  const sec = Math.max(0, p.next_supply_at - now.value)
  const h = Math.floor(sec / 3600)
  const m = Math.floor((sec % 3600) / 60)
  const base = h > 0 ? `${h} 时 ${m} 分` : `${m} 分`
  return p.applied_cycles >= p.max_accumulate ? `${base}（已满）` : base
}

const pendingLevels = computed(() =>
  Object.entries(travel.totalPending ?? {})
    .filter(([, amount]) => amount > 0)
    .map(([level, amount]) => ({ level, amount }))
)
const hasPending = computed(() => pendingLevels.value.length > 0)
const totalText = computed(() =>
  pendingLevels.value.map((e) => `${levelLabel(e.level)} ${e.amount}`).join('，')
)

function stoneText(stones: SpiritStones): string {
  return Object.entries(stones)
    .filter(([, amount]) => amount > 0)
    .map(([level, amount]) => `${levelLabel(level)} ${amount}`)
    .join('，')
}

async function claim() {
  try {
    const data = await travel.claim()
    toast.success(`领取上供：${stoneText(data.total) || '无'}`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}

async function release(p: Patron) {
  confirmId.value = null
  try {
    await travel.release(p.id)
    toast.success(`已解除与${p.sect_name}的庇护`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}

let timer: number | undefined

onMounted(() => {
  timer = window.setInterval(() => (now.value = Math.floor(Date.now() / 1000)), 1000)
  if (!travel.patronsLoaded) {
    travel.loadPatrons().catch((e) => toast.error((e as Error).message))
  }
})
onUnmounted(() => {
  if (timer) window.clearInterval(timer)
})
</script>

<style scoped>
.patron-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 9px;
  margin-bottom: 8px;
  border: 1px solid var(--border);
  border-radius: 5px;
  background: rgba(13, 19, 13, .55);
}
.patron-row.sect {
  border-color: rgba(126, 224, 126, .28);
}
.patron-main {
  min-width: 0;
}
.patron-name {
  display: block;
  color: var(--text);
  font-size: 13px;
}
.patron-meta {
  display: block;
  margin-top: 2px;
  font-size: 9px;
}
.patron-side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 5px;
  flex: none;
}
.pending {
  color: var(--accent);
  font-size: 11px;
  white-space: nowrap;
}
.patron-side .small {
  padding: 3px 7px;
  font-size: 9px;
}
.patron-side .btn-row {
  gap: 5px;
}
.patron-side .btn-row button {
  padding: 3px 7px;
  font-size: 9px;
}
.claim-btn {
  width: 100%;
  margin-top: 2px;
}
</style>
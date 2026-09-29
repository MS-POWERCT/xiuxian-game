<template>
  <section class="panel">
    <h3>闭关</h3>
    <div v-if="store.retreat">
      <div class="row"><span>闭关中</span><span class="muted">{{ remaining }}</span></div>
      <div class="row"><span>预计收益</span><span>{{ store.retreat.expected_exp }} 修为</span></div>
      <button class="primary" @click="claim">{{ finished ? '结算闭关' : '提前出关' }}</button>
      <p v-if="!finished" class="muted">提前出关收益按已过时间折算 ×{{ earlyExitPct }}%</p>
    </div>
    <div v-else>
      <p v-if="retreatLocked" class="muted">🔒 {{ unlockHint('retreat') }}</p>
      <fieldset :disabled="retreatLocked" class="plain-fieldset">
        <div class="select">
          <span class="muted">时长</span>
          <select v-model.number="durationHours">
            <option v-for="r in retreats" :key="r.id" :value="r.duration_hours">{{ r.name }}</option>
          </select>
        </div>
        <div class="select">
          <span class="muted">心法</span>
          <select v-model="techniqueId">
            <option v-for="t in unlockedTechniques" :key="t.id" :value="t.id">
              {{ t.name }}（{{ t.closing_efficiency }}）
            </option>
          </select>
        </div>
        <div class="select">
          <span class="muted">法阵</span>
          <select v-model="formationId">
            <option value="">不用法阵</option>
            <option v-for="f in unlockedFormations" :key="f.id" :value="f.id">
              {{ f.name }}（{{ f.cost_per_use }} 灵石）
            </option>
          </select>
        </div>
        <div class="select">
          <span class="muted">丹药</span>
          <span v-for="p in unlockedPills" :key="p.id" class="pill-cnt">
            <button type="button" @click="dec(p.id)">-</button>
            <span>{{ p.name }} ×{{ count(p.id) }}</span>
            <button type="button" @click="inc(p.id)">+</button>
          </span>
        </div>
        <div class="row"><span class="muted">消耗灵石</span><span>{{ cost }}</span></div>
        <p v-if="blocked" class="muted">{{ hint }}</p>
        <button class="primary" :disabled="blocked" data-sfx="retreat_start" @click="start">开始闭关</button>
      </fieldset>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onUnmounted, reactive, ref } from 'vue'
import { formationsConfig, meditationConfig, pillsConfig, realmOrder, techniquesConfig, isFeatureUnlocked, unlockHint } from '@/config'
import { usePlayerStore } from '@/stores/player'
import { statusHint } from '@/utils/status'
import { useCelebrationStore } from '@/stores/celebration'
import { useAudioStore } from '@/stores/audio'
import { useToastStore } from '@/stores/toast'

const store = usePlayerStore()
const celebration = useCelebrationStore()
const audio = useAudioStore()
const toast = useToastStore()
const retreats = meditationConfig.retreats

const blocked = computed(() => (store.player?.status ?? 'idle') !== 'idle')
const hint = computed(() => statusHint(store.player?.status ?? 'idle'))
const retreatLocked = computed(() => {
  if (!store.player) return false
  return !isFeatureUnlocked('retreat', store.player.realm_id, store.player.stage_index)
})

const durationHours = ref(24)
const techniqueId = ref('tuna')
const formationId = ref('')
const pillCounts = reactive<Record<string, number>>({})

const order = computed(() => (store.player ? realmOrder(store.player.realm_id) : 0))
const unlockedTechniques = computed(() =>
  techniquesConfig.techniques.filter((t) => !t.unlock_realm || realmOrder(t.unlock_realm) <= order.value)
)
const unlockedFormations = computed(() =>
  formationsConfig.formations.filter((f) => !f.unlock_realm || realmOrder(f.unlock_realm) <= order.value)
)
const unlockedPills = computed(() =>
  pillsConfig.pills.filter((p) => !p.unlock_realm || realmOrder(p.unlock_realm) <= order.value)
)

function count(id: string) {
  return pillCounts[id] ?? 0
}
function inc(id: string) {
  pillCounts[id] = count(id) + 1
}
function dec(id: string) {
  pillCounts[id] = Math.max(0, count(id) - 1)
}

const cost = computed(() => {
  let c = 0
  const f = formationsConfig.formations.find((x) => x.id === formationId.value)
  if (f) c += f.cost_per_use
  for (const [id, n] of Object.entries(pillCounts)) {
    const p = pillsConfig.pills.find((x) => x.id === id)
    if (p && n > 0) c += p.price * n
  }
  return c
})

function pillIdsArray(): string[] {
  const arr: string[] = []
  for (const [id, n] of Object.entries(pillCounts)) {
    for (let i = 0; i < n; i++) arr.push(id)
  }
  return arr
}

async function start() {
  try {
    await store.startRetreat({
      duration_hours: durationHours.value,
      technique_id: techniqueId.value,
      formation_id: formationId.value,
      pill_ids: pillIdsArray(),
    })
  } catch (e) {
    toast.error((e as Error).message)
  }
}

// 结算倒计时
const now = ref(Math.floor(Date.now() / 1000))
const tick = window.setInterval(() => {
  now.value = Math.floor(Date.now() / 1000)
}, 1000)
onUnmounted(() => window.clearInterval(tick))

const remaining = computed(() => {
  if (!store.retreat) return ''
  const s = store.retreat.finish_at - now.value
  if (s <= 0) return '可结算'
  return `${Math.floor(s / 3600)}时${Math.floor((s % 3600) / 60)}分${s % 60}秒后`
})
const finished = computed(() => !!store.retreat && now.value >= store.retreat.finish_at)
const earlyExitPct = computed(() => Math.round((meditationConfig.retreat_early_exit_ratio ?? 0.5) * 100))

async function claim() {
  try {
    const data = await store.claimRetreat()
    if (data) {
      audio.playSfx('retreat_done')
      celebration.celebrate('grand', {
        title: '闭关大成',
        subtitle: '闭关圆满，道行大进',
        reward: `修为 +${data.gained_exp}`,
        lines: ['灵台清明，道基愈发稳固'],
      })
    }
  } catch (e) {
    toast.error((e as Error).message)
  }
}
</script>
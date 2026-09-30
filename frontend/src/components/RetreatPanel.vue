<template>
  <section class="panel retreat-panel" :class="{ active: !!store.retreat }">
    <header class="retreat-head">
      <div>
        <p class="eyebrow">闭关</p>
        <h3>{{ store.retreat ? (finished ? '闭关圆满' : '入定之中') : '准备闭关' }}</h3>
      </div>
      <span v-if="store.retreat" class="state" :class="{ done: finished }">
        {{ finished ? '可出关' : '运转周天' }}
      </span>
    </header>

    <div v-if="store.retreat">
      <div class="retreat-stage" :class="{ finished }">
        <div class="meditator" aria-hidden="true">
          <div class="aura aura-outer"></div>
          <div class="aura aura-inner"></div>
          <div class="motes">
            <i v-for="n in 7" :key="n" :style="{ animationDelay: `${n * 0.7}s` }"></i>
          </div>
          <svg class="figure" viewBox="0 0 120 94">
            <ellipse class="shadow" cx="60" cy="78" rx="36" ry="7" />
            <circle class="head" cx="60" cy="28" r="10" />
            <path class="body" d="M60 40 C45 43 36 55 36 69 L84 69 C84 55 75 43 60 40 Z" />
            <path class="lap" d="M29 74 C40 63 51 59 60 59 C69 59 80 63 91 74" />
          </svg>
        </div>

        <div class="readout">
          <div class="progress-ring" :style="{ '--progress': progressPercent + '%' }">
            <span>{{ progressPercent }}%</span>
          </div>
          <div class="remaining">
            <span class="label">距离出关</span>
            <div class="remaining-grid">
              <div class="time-chip">
                <strong>{{ remainingClock.hours }}</strong>
                <span>时</span>
              </div>
              <div class="time-chip">
                <strong>{{ remainingClock.minutes }}</strong>
                <span>分</span>
              </div>
              <div class="time-chip">
                <strong>{{ remainingClock.seconds }}</strong>
                <span>秒</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="retreat-notes">
        <p class="phase">{{ phaseText }}</p>
        <div class="row"><span class="muted">预计收获</span><span>{{ store.retreat.expected_exp }} 修为</span></div>
      </div>

      <div class="actions">
        <button v-if="finished" class="primary" @click="claim">出关 · 结算修为</button>
        <button v-else class="ghost" @click="claim">提前破关</button>
      </div>
      <p v-if="!finished" class="muted exit-hint">提前出关收益按已过时间折算 ×{{ earlyExitPct }}%</p>
    </div>
    <div v-else class="retreat-entry">
      <p v-if="retreatLocked" class="muted">🔒 {{ unlockHint('retreat') }}</p>
      <button class="primary retreat-open" :disabled="retreatLocked || blocked" data-sfx="click" @click="openModal">
        闭关
      </button>
      <p class="muted">择心法、结法阵、服丹药，入关后静待道基增长。</p>
    </div>
  </section>

  <Teleport to="body">
    <div v-if="showModal" class="retreat-modal" @click.self="closeModal">
      <section class="retreat-dialog" role="dialog" aria-modal="true" aria-label="闭关准备">
        <header class="dialog-head">
          <div>
            <p class="eyebrow">闭关准备</p>
            <h3>入关</h3>
          </div>
          <button class="dialog-close" type="button" aria-label="取消闭关" @click="closeModal">×</button>
        </header>

        <fieldset :disabled="retreatLocked || blocked" class="plain-fieldset">
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
          <div class="dialog-actions">
            <button class="ghost" type="button" @click="closeModal">再想想</button>
            <button class="primary" data-sfx="retreat_start" @click="start">入关</button>
          </div>
        </fieldset>
      </section>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, onUnmounted, reactive, ref } from 'vue'
import { formationsConfig, meditationConfig, pillsConfig, realmOrder, techniquesConfig, isFeatureUnlocked, unlockHint } from '@/config'
import { usePlayerStore } from '@/stores/player'
import { useCelebrationStore } from '@/stores/celebration'
import { useAudioStore } from '@/stores/audio'
import { useToastStore } from '@/stores/toast'

const store = usePlayerStore()
const celebration = useCelebrationStore()
const audio = useAudioStore()
const toast = useToastStore()
const retreats = meditationConfig.retreats

const blocked = computed(() => (store.player?.status ?? 'idle') !== 'idle')
const retreatLocked = computed(() => {
  if (!store.player) return false
  return !isFeatureUnlocked('retreat', store.player.realm_id, store.player.stage_index)
})

const durationHours = ref(24)
const techniqueId = ref('tuna')
const formationId = ref('')
const pillCounts = reactive<Record<string, number>>({})
const showModal = ref(false)

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
    showModal.value = false
  } catch (e) {
    toast.error((e as Error).message)
  }
}

function openModal() {
  if (retreatLocked.value || blocked.value) return
  showModal.value = true
}

function closeModal() {
  showModal.value = false
}

// 结算倒计时
const now = ref(Math.floor(Date.now() / 1000))
const tick = window.setInterval(() => {
  now.value = Math.floor(Date.now() / 1000)
}, 1000)
onUnmounted(() => window.clearInterval(tick))

const finished = computed(() => !!store.retreat && now.value >= store.retreat.finish_at)
const earlyExitPct = computed(() => Math.round((meditationConfig.retreat_early_exit_ratio ?? 0.5) * 100))

const remainingSeconds = computed(() => {
  if (!store.retreat) return 0
  return Math.max(0, store.retreat.finish_at - now.value)
})

const totalSeconds = computed(() => {
  if (!store.retreat) return 1
  return Math.max(1, store.retreat.finish_at - store.retreat.start_at)
})

const progressPercent = computed(() => {
  if (!store.retreat) return 0
  if (finished.value) return 100
  const elapsed = totalSeconds.value - remainingSeconds.value
  return Math.min(99, Math.floor((elapsed / totalSeconds.value) * 100))
})

const remainingClock = computed(() => {
  if (!store.retreat || finished.value) {
    return { hours: '--', minutes: '--', seconds: '--' }
  }
  const s = remainingSeconds.value
  return {
    hours: String(Math.floor(s / 3600)).padStart(2, '0'),
    minutes: String(Math.floor((s % 3600) / 60)).padStart(2, '0'),
    seconds: String(s % 60).padStart(2, '0'),
  }
})

const phaseText = computed(() => {
  if (finished.value) return '气息圆满，灵台清明，可以出关了。'
  if (progressPercent.value < 20) return '气息渐沉，尘念如雾散去。'
  if (progressPercent.value < 45) return '灵息循行周天，若隐若现。'
  if (progressPercent.value < 75) return '丹田温热，道韵暗中生长。'
  return '灵光渐凝，关窍将开，静待圆满。'
})

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

<style scoped>
.retreat-panel h3 {
  border-bottom: 0;
  margin: 0;
  padding: 0;
  font-size: 16px;
}
.retreat-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 10px;
}
.eyebrow {
  margin: 0 0 3px;
  font-size: 10px;
  color: var(--muted);
  letter-spacing: 3px;
}
.state {
  flex: none;
  padding: 3px 8px;
  border: 1px solid rgba(126, 224, 126, .32);
  border-radius: 999px;
  color: var(--accent);
  font-size: 10px;
  letter-spacing: 1px;
  background: rgba(126, 224, 126, .06);
}
.state.done {
  border-color: rgba(126, 224, 126, .68);
  background: rgba(126, 224, 126, .14);
}

.retreat-panel.active {
  position: relative;
  overflow: hidden;
  border-color: rgba(126, 224, 126, .32);
  background:
    radial-gradient(circle at 30% 20%, rgba(126, 224, 126, .10), transparent 58%),
    linear-gradient(180deg, #101710, var(--panel));
}
.retreat-panel.active::before {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: radial-gradient(circle at 50% 34%, rgba(126, 224, 126, .08), transparent 70%);
}

.retreat-stage {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 138px;
  align-items: center;
  gap: 12px;
  padding: 4px 0 10px;
}
.meditator {
  position: relative;
  height: 156px;
  display: grid;
  place-items: center;
}
.aura,
.figure,
.motes {
  pointer-events: none;
}
.aura {
  position: absolute;
  left: 50%;
  top: 55%;
  border-radius: 50%;
  transform: translate(-50%, -50%);
}
.aura-outer {
  width: 136px;
  height: 136px;
  border: 1px solid rgba(126, 224, 126, .22);
  box-shadow: 0 0 34px rgba(126, 224, 126, .06) inset;
  animation: retreat-breathe 6.5s ease-in-out infinite;
}
.aura-inner {
  width: 98px;
  height: 98px;
  background: radial-gradient(circle, rgba(126, 224, 126, .16), transparent 68%);
  animation: retreat-breathe 6.5s ease-in-out infinite reverse;
}
.figure {
  position: relative;
  z-index: 2;
  width: 108px;
  filter: drop-shadow(0 0 12px rgba(126, 224, 126, .20));
}
.head,
.body {
  fill: #0d130d;
  stroke: rgba(126, 224, 126, .82);
  stroke-width: 2;
}
.body {
  fill: rgba(126, 224, 126, .14);
}
.lap {
  fill: none;
  stroke: rgba(216, 232, 216, .65);
  stroke-width: 2;
  stroke-linecap: round;
}
.shadow {
  fill: rgba(0, 0, 0, .46);
}

.motes {
  position: absolute;
  inset: 0;
  overflow: hidden;
}
.motes i {
  position: absolute;
  bottom: 16px;
  width: 3px;
  height: 3px;
  border-radius: 50%;
  background: rgba(126, 224, 126, .72);
  box-shadow: 0 0 8px rgba(126, 224, 126, .60);
  opacity: 0;
  animation: retreat-rise 5.4s linear infinite;
}
.motes i:nth-child(1) { left: 23%; }
.motes i:nth-child(2) { left: 35%; animation-duration: 6.1s; }
.motes i:nth-child(3) { left: 46%; animation-duration: 4.9s; }
.motes i:nth-child(4) { left: 54%; animation-duration: 6.7s; }
.motes i:nth-child(5) { left: 64%; animation-duration: 5.2s; }
.motes i:nth-child(6) { left: 72%; animation-duration: 6.4s; }
.motes i:nth-child(7) { left: 82%; animation-duration: 5.8s; }

.retreat-stage.finished .aura-outer,
.retreat-stage.finished .aura-inner {
  animation-duration: 2.8s;
  border-color: rgba(126, 224, 126, .55);
}
.retreat-stage.finished .figure {
  filter: drop-shadow(0 0 18px rgba(126, 224, 126, .38));
}

.readout {
  display: grid;
  gap: 10px;
  justify-items: center;
}
.progress-ring {
  position: relative;
  width: 70px;
  height: 70px;
  border-radius: 50%;
  background: conic-gradient(var(--accent) var(--progress), rgba(126, 224, 126, .12) 0);
}
.progress-ring::after {
  content: '';
  position: absolute;
  inset: 7px;
  border-radius: 50%;
  background: #0b110b;
  border: 1px solid rgba(126, 224, 126, .18);
}
.progress-ring span {
  position: absolute;
  inset: 0;
  z-index: 1;
  display: grid;
  place-items: center;
  font-size: 12px;
  color: var(--accent);
}
.remaining {
  width: 100%;
  text-align: center;
}
.remaining .label {
  display: block;
  margin-bottom: 5px;
  font-size: 10px;
  color: var(--muted);
  letter-spacing: 2px;
}
.remaining-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 4px;
}
.time-chip {
  min-width: 0;
  padding: 5px 2px;
  border: 1px solid rgba(126, 224, 126, .14);
  border-radius: 4px;
  background: rgba(13, 19, 13, .88);
  text-align: center;
}
.time-chip strong {
  display: block;
  color: var(--text);
  font-size: 14px;
}
.time-chip span {
  font-size: 9px;
  color: var(--muted);
}

.retreat-notes {
  position: relative;
  z-index: 1;
  padding: 9px;
  border: 1px dashed rgba(42, 58, 42, .9);
  border-radius: 5px;
  background: rgba(13, 19, 13, .45);
}
.phase {
  margin: 0 0 7px;
  color: var(--text);
  font-size: 12px;
  line-height: 1.7;
}
.actions {
  display: flex;
  margin-top: 10px;
}
.actions button {
  width: 100%;
}
.exit-hint {
  margin: 7px 0 0;
  font-size: 11px;
  text-align: center;
}
.retreat-entry {
  display: grid;
  gap: 8px;
}
.retreat-entry p {
  margin: 0;
  font-size: 11px;
  line-height: 1.7;
}
.retreat-open {
  width: 100%;
  padding: 10px 12px;
}

.retreat-modal {
  position: fixed;
  inset: 0;
  z-index: 120;
  display: grid;
  place-items: center;
  padding: 16px;
  background: rgba(0, 0, 0, .72);
  backdrop-filter: blur(4px);
}
.retreat-dialog {
  width: min(100%, 480px);
  max-height: min(88vh, 680px);
  overflow: auto;
  padding: 16px;
  border: 1px solid rgba(126, 224, 126, .32);
  border-radius: 10px;
  background:
    radial-gradient(circle at 50% 0, rgba(126, 224, 126, .09), transparent 52%),
    #101710;
  box-shadow: 0 24px 70px rgba(0, 0, 0, .62);
}
.dialog-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
  padding-bottom: 9px;
  border-bottom: 1px dashed rgba(42, 58, 42, .9);
}
.dialog-head h3 {
  margin: 0;
  padding: 0;
  border: 0;
  font-size: 17px;
}
.dialog-close {
  width: 28px;
  height: 28px;
  padding: 0;
  border-style: dashed;
  color: var(--muted);
  font-size: 18px;
  line-height: 1;
}
.dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 14px;
}
.dialog-actions button {
  min-width: 82px;
}

@keyframes retreat-breathe {
  0%, 100% {
    opacity: .55;
    transform: translate(-50%, -50%) scale(.94);
  }
  50% {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1.05);
  }
}
@keyframes retreat-rise {
  0% {
    opacity: 0;
    transform: translateY(0) scale(.7);
  }
  15% {
    opacity: .8;
  }
  75% {
    opacity: .2;
  }
  100% {
    opacity: 0;
    transform: translateY(-112px) scale(1.1);
  }
}

@media (max-width: 460px) {
  .retreat-stage {
    grid-template-columns: 1fr;
  }
  .readout {
    grid-template-columns: 70px 1fr;
    align-items: center;
    width: 100%;
  }
  .remaining-grid {
    gap: 5px;
  }
}
@media (prefers-reduced-motion: reduce) {
  .aura,
  .motes i {
    animation: none;
  }
  .aura {
    opacity: .8;
  }
}
</style>

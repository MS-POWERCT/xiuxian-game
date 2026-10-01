<template>
  <section class="panel dazuo-panel">
    <div class="dazuo-head">
      <div>
        <h3>感悟</h3>
        <p class="muted">念起即悟，攒满 {{ batchSize }} 次凝为一缕修为。</p>
      </div>
      <span class="batch">{{ dailyText }}</span>
    </div>

    <button
      ref="buttonRef"
      class="dazuo-button"
      :class="{ pulse, resolving }"
      :disabled="blocked || dailyBlocked || clickLocked || resolving"
      data-sfx="click"
      @click="click"
    >
      <span class="button-aura"></span>
      <strong>{{ resolving ? '凝念' : '感悟' }}</strong>
      <small>{{ actionHint }}</small>
    </button>

    <div class="dots" :class="{ merging: resolving }" aria-hidden="true">
      <i
        v-for="i in batchSize"
        :key="i"
        :class="{ lit: i <= count }"
        :style="{ '--merge-x': `${(3 - i) * 20}px` }"
      ></i>
    </div>

    <p class="dazuo-tip muted">
      {{ resolving ? '五点灵光正在汇聚…' : '不满批次不会结算，也不会写入修为。' }}
    </p>

    <Teleport to="body">
      <span v-if="flying" class="dazuo-fly-orb" :style="flyStyle"></span>
      <span v-if="flash" class="dazuo-reward-flash" :style="flashStyle">修为 +{{ flash }}</span>
    </Teleport>
  </section>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { meditationConfig } from '@/config'
import { usePlayerStore } from '@/stores/player'
import { useToastStore } from '@/stores/toast'
import { statusHint } from '@/utils/status'

const store = usePlayerStore()
const toast = useToastStore()

const batchSize = meditationConfig.dazuo_batch_size
const clickInterval = meditationConfig.dazuo_click_interval_ms

const buttonRef = ref<HTMLButtonElement>()
const count = ref(0)
const pulse = ref(false)
const clickLocked = ref(false)
const resolving = ref(false)
const flying = ref(false)
const flash = ref('')
const flyFrom = ref({ x: 0, y: 0 })
const flyTo = ref({ x: 0, y: 0 })

let pulseTimer: number | undefined
let clickTimer: number | undefined
let mergeTimer: number | undefined
let flightTimer: number | undefined
let flashTimer: number | undefined

const DAZUO_STATUSES = new Set(['idle', 'meditating', 'retreating', 'exploring'])
const blocked = computed(() => !DAZUO_STATUSES.has(store.player?.status ?? 'idle'))
const dailyUsed = computed(() => store.dazuoState?.daily_used ?? 0)
const dailyLimit = computed(() => store.dazuoState?.daily_limit ?? meditationConfig.dazuo_daily_limit_base)
const dailyBlocked = computed(() => dailyLimit.value > 0 && dailyUsed.value + batchSize > dailyLimit.value)
const dailyDisplayUsed = computed(() => dailyLimit.value > 0
  ? Math.min(dailyLimit.value, dailyUsed.value + count.value)
  : dailyUsed.value + count.value)
const dailyText = computed(() => dailyLimit.value > 0
  ? `今日 ${dailyDisplayUsed.value} / ${dailyLimit.value}`
  : `今日 ${dailyDisplayUsed.value} 次`)
const actionHint = computed(() => {
  if (blocked.value) return statusHint(store.player?.status ?? 'idle')
  if (dailyBlocked.value) return '今日次数已不足以凝聚一批'
  return '点击感念，凝神一悟'
})
const flyStyle = computed(() => ({
  left: `${flyFrom.value.x}px`,
  top: `${flyFrom.value.y}px`,
  '--fly-x': `${flyTo.value.x - flyFrom.value.x}px`,
  '--fly-y': `${flyTo.value.y - flyFrom.value.y}px`,
}))
const flashStyle = computed(() => ({
  left: `${flyTo.value.x}px`,
  top: `${flyTo.value.y}px`,
}))

function resetCount() {
  count.value = 0
  pulse.value = false
  clickLocked.value = false
  resolving.value = false
}

function click() {
  if (blocked.value || dailyBlocked.value || clickLocked.value || resolving.value) return

  count.value += 1
  pulse.value = true
  window.clearTimeout(pulseTimer)
  pulseTimer = window.setTimeout(() => {
    pulse.value = false
  }, 180)

  clickLocked.value = true
  window.clearTimeout(clickTimer)
  clickTimer = window.setTimeout(() => {
    clickLocked.value = false
  }, clickInterval)

  if (count.value >= batchSize) {
    count.value = batchSize
    resolving.value = true
    window.clearTimeout(mergeTimer)
    mergeTimer = window.setTimeout(beginFlight, 320)
  }
}

function beginFlight() {
  const buttonRect = buttonRef.value?.getBoundingClientRect()
  const targetRect = document.getElementById('exp-reward-target')?.getBoundingClientRect()
  if (!buttonRect || !targetRect) {
    void settle()
    return
  }

  flyFrom.value = {
    x: buttonRect.left + buttonRect.width / 2,
    y: buttonRect.top + buttonRect.height / 2,
  }
  flyTo.value = {
    x: targetRect.left + targetRect.width / 2,
    y: targetRect.top + targetRect.height / 2,
  }
  flying.value = true
  window.clearTimeout(flightTimer)
  flightTimer = window.setTimeout(settle, 520)
}

async function settle() {
  flying.value = false
  try {
    const data = await store.dazuo(batchSize)
    flash.value = String(data.gained_exp)
    window.clearTimeout(flashTimer)
    flashTimer = window.setTimeout(() => {
      flash.value = ''
    }, 780)
  } catch (e) {
    toast.error((e as Error).message)
    await store.load()
  } finally {
    resetCount()
  }
}

watch(
  () => store.player?.status,
  (status) => {
    if (status === 'dead') resetCount()
  }
)

watch(
  () => store.player?.life_no,
  () => resetCount()
)

onUnmounted(() => {
  window.clearTimeout(pulseTimer)
  window.clearTimeout(clickTimer)
  window.clearTimeout(mergeTimer)
  window.clearTimeout(flightTimer)
  window.clearTimeout(flashTimer)
})
</script>

<style scoped>
.dazuo-panel {
  overflow: hidden;
}
.dazuo-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 10px;
}
.dazuo-head h3 {
  margin-bottom: 4px;
}
.dazuo-head p {
  margin: 0;
  font-size: 11px;
  line-height: 1.6;
}
.batch {
  flex: none;
  padding: 3px 8px;
  border: 1px solid rgba(126, 224, 126, .28);
  border-radius: 999px;
  color: var(--accent);
  background: rgba(126, 224, 126, .06);
  font-size: 10px;
}

.dazuo-button {
  position: relative;
  display: grid;
  place-items: center;
  width: 100%;
  min-height: 76px;
  overflow: hidden;
  border-color: rgba(126, 224, 126, .28);
  background:
    radial-gradient(circle at 50% 50%, rgba(126, 224, 126, .14), transparent 68%),
    #0d130d;
  transition: transform .18s ease, border-color .5s ease, box-shadow .5s ease;
}
.dazuo-button:hover:not(:disabled) {
  border-color: var(--accent);
  box-shadow: 0 0 26px rgba(126, 224, 126, .08);
}
.dazuo-button.pulse {
  transform: scale(.985);
}
.dazuo-button.resolving {
  border-color: rgba(126, 224, 126, .60);
  box-shadow: 0 0 34px rgba(126, 224, 126, .14);
}
.dazuo-button strong,
.dazuo-button small {
  position: relative;
  z-index: 1;
}
.dazuo-button strong {
  color: var(--accent);
  font-size: 17px;
  letter-spacing: 6px;
}
.dazuo-button small {
  color: var(--muted);
  font-size: 10px;
  letter-spacing: 1px;
}
.button-aura {
  position: absolute;
  width: 92px;
  height: 92px;
  border: 1px solid rgba(126, 224, 126, .18);
  border-radius: 50%;
  animation: dazuo-breathe 3.2s ease-in-out infinite;
}
.dazuo-button.resolving .button-aura {
  border-color: rgba(126, 224, 126, .48);
  animation-duration: 1.1s;
}

.dots {
  position: relative;
  display: flex;
  justify-content: center;
  gap: 12px;
  height: 22px;
  margin: 8px 0 0;
}
.dots i {
  width: 7px;
  height: 7px;
  margin-top: 7px;
  border: 1px solid rgba(126, 224, 126, .28);
  border-radius: 50%;
  background: #0d130d;
  transition: background .18s ease, box-shadow .18s ease, transform .18s ease;
}
.dots i.lit {
  background: var(--accent);
  box-shadow: 0 0 10px rgba(126, 224, 126, .64);
  transform: translateY(-3px);
}
.dots.merging i {
  animation: dazuo-merge .32s ease-in forwards;
}
.dots.merging i:nth-child(2) { animation-delay: .02s; }
.dots.merging i:nth-child(3) { animation-delay: .04s; }
.dots.merging i:nth-child(4) { animation-delay: .06s; }
.dots.merging i:nth-child(5) { animation-delay: .08s; }

.dazuo-tip {
  margin: 2px 0 0;
  font-size: 10px;
  text-align: center;
}

.dazuo-fly-orb {
  position: fixed;
  z-index: 100;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #dfffe0;
  box-shadow:
    0 0 10px rgba(126, 224, 126, .95),
    0 0 26px rgba(126, 224, 126, .72);
  pointer-events: none;
  transform: translate(-50%, -50%);
  animation: dazuo-fly .52s cubic-bezier(.24, .68, .35, 1) forwards;
}
.dazuo-reward-flash {
  position: fixed;
  z-index: 101;
  color: #dfffe0;
  font-size: 12px;
  white-space: nowrap;
  pointer-events: none;
  transform: translate(-50%, -50%);
  animation: dazuo-flash .78s ease-out forwards;
}

@keyframes dazuo-breathe {
  0%, 100% { opacity: .42; transform: scale(.88); }
  50% { opacity: .9; transform: scale(1.08); }
}
@keyframes dazuo-merge {
  to {
    opacity: 0;
    transform: translateX(var(--merge-x)) translateY(-5px) scale(.45);
  }
}
@keyframes dazuo-fly {
  0% {
    opacity: 0;
    transform: translate(-50%, -50%) scale(.45);
  }
  18% {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1.35);
  }
  100% {
    opacity: 0;
    transform: translate(calc(-50% + var(--fly-x)), calc(-50% + var(--fly-y))) scale(.65);
  }
}
@keyframes dazuo-flash {
  0% { opacity: 0; transform: translate(-50%, -20%) scale(.9); }
  24% { opacity: 1; transform: translate(-50%, -70%) scale(1); }
  100% { opacity: 0; transform: translate(-50%, -125%) scale(.96); }
}

@media (prefers-reduced-motion: reduce) {
  .button-aura,
  .dots.merging i,
  .dazuo-fly-orb,
  .dazuo-reward-flash {
    animation-duration: .01ms;
  }
}
</style>

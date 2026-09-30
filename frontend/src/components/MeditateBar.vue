<template>
  <section class="panel meditation-panel" :class="{ active: !!store.meditation }">
    <header class="meditation-head">
      <div>
        <p class="eyebrow">冥想</p>
        <h3>{{ store.meditation ? activeName : '入静' }}</h3>
      </div>
    </header>

    <div v-if="!store.meditation" class="meditation-picker">
      <p class="meditation-intro muted">择一段静时入定，灵息会随时间自行流转。</p>
      <button
        v-for="m in meditations"
        :key="m.id"
        class="meditation-choice"
        :disabled="blocked || isLocked(m)"
        data-sfx="meditate_start"
        @click="start(m)"
      >
        <span>
          <strong>{{ m.name }}</strong>
          <small v-if="isLocked(m)" class="muted">{{ unlockHint(m.id) }}</small>
          <small v-else class="muted">静心守一</small>
        </span>
        <em>{{ durationText(m.duration_seconds) }}</em>
      </button>
    </div>

    <div v-else class="meditation-active" :class="{ finished }">
      <div class="meditation-orb">
        <div class="orb-halo orb-halo-outer"></div>
        <div class="orb-halo orb-halo-inner"></div>
        <div class="orb-core">
          <span class="orb-character">静</span>
          <strong class="orb-time">{{ remainingClock }}</strong>
        </div>
      </div>
      <p class="meditation-phase">{{ phaseText }}</p>

      <div class="meditation-notes">
        <div class="row"><span class="muted">入定所得</span><span>{{ store.meditation.expected_exp }} 修为</span></div>
      </div>

      <button v-if="finished" class="primary" :disabled="claiming" @click="claim">
        {{ claiming ? '出定中…' : '出定 · 领取修为' }}
      </button>
      <p v-else class="muted meditation-hint">灵息未散，此时离开页面也不会中断。</p>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { meditationConfig, isFeatureUnlocked, unlockHint } from '@/config'
import { usePlayerStore } from '@/stores/player'
import { useCelebrationStore } from '@/stores/celebration'
import { useAudioStore } from '@/stores/audio'
import { useToastStore } from '@/stores/toast'

const store = usePlayerStore()
const celebration = useCelebrationStore()
const audio = useAudioStore()
const toast = useToastStore()
const meditations = meditationConfig.meditations

const now = ref(Math.floor(Date.now() / 1000))
const claiming = ref(false)
let attemptedFinishAt = 0
let timer: number | undefined

const blocked = computed(() => (store.player?.status ?? 'idle') !== 'idle')
const remainingSeconds = computed(() => {
  if (!store.meditation) return 0
  return Math.max(0, store.meditation.finish_at - now.value)
})
const totalSeconds = computed(() => {
  if (!store.meditation) return 1
  return Math.max(1, store.meditation.finish_at - store.meditation.start_at)
})
const elapsedSeconds = computed(() => Math.max(0, totalSeconds.value - remainingSeconds.value))
const progressPercent = computed(() => {
  if (!store.meditation) return 0
  return Math.min(100, Math.floor((elapsedSeconds.value / totalSeconds.value) * 100))
})
const finished = computed(() => !!store.meditation && remainingSeconds.value === 0)
const activeName = computed(() => {
  const duration = store.meditation?.duration
  return meditations.find((m) => m.duration_seconds === duration)?.name ?? '冥想'
})
const remainingClock = computed(() => clockText(remainingSeconds.value))
const phaseText = computed(() => {
  if (finished.value) return '灵息圆满，可以出定'
  if (progressPercent.value < 20) return '呼吸渐缓，杂念初退'
  if (progressPercent.value < 45) return '灵息循行，万籁俱寂'
  if (progressPercent.value < 75) return '气息绵长，神意渐明'
  return '周天将满，静待灵光归元'
})

function clockText(seconds: number): string {
  const value = Math.max(0, Math.floor(seconds))
  const minutes = Math.floor(value / 60)
  const remain = value % 60
  return `${String(minutes).padStart(2, '0')}:${String(remain).padStart(2, '0')}`
}

function durationText(seconds: number): string {
  return clockText(seconds)
}

function isLocked(m: { id: string }) {
  if (!store.player) return false
  return !isFeatureUnlocked(m.id, store.player.realm_id, store.player.stage_index)
}

async function start(m: { id: string; duration_seconds: number }) {
  try {
    await store.startMeditation(m.duration_seconds)
    audio.playSfx('meditate_start')
  } catch (e) {
    toast.error((e as Error).message)
  }
}

async function claim() {
  if (!store.meditation || !finished.value || claiming.value) return
  claiming.value = true
  const finishedName = activeName.value
  const finishedDuration = store.meditation.duration
  try {
    const data = await store.claimMeditation()
    const level = meditations.find((m) => m.duration_seconds === finishedDuration)?.id === 'meditate_deep'
      ? 'advanced'
      : 'basic'
    audio.playSfx('meditate_done')
    celebration.celebrate(level, {
      title: level === 'advanced' ? '深修有得' : '修炼有成',
      subtitle: finishedName,
      reward: `修为 +${data.gained_exp}`,
    })
  } catch (e) {
    toast.error((e as Error).message)
  } finally {
    claiming.value = false
  }
}

watch(
  () => [store.meditation?.finish_at ?? 0, now.value] as const,
  ([finishAt]) => {
    if (!finishAt || now.value < finishAt || attemptedFinishAt === finishAt) return
    attemptedFinishAt = finishAt
    void claim()
  },
  { immediate: true }
)

onMounted(() => {
  timer = window.setInterval(() => {
    now.value = Math.floor(Date.now() / 1000)
  }, 1000)
})

onUnmounted(() => {
  if (timer) window.clearInterval(timer)
})
</script>

<style scoped>
.meditation-panel h3 {
  border-bottom: 0;
  margin: 0;
  padding: 0;
  font-size: 16px;
}
.meditation-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 10px;
}
.eyebrow {
  margin: 0 0 3px;
  color: var(--muted);
  font-size: 10px;
  letter-spacing: 3px;
}
.meditation-picker {
  display: grid;
  gap: 8px;
}
.meditation-intro {
  margin: 0 0 2px;
  font-size: 11px;
  line-height: 1.7;
}
.meditation-choice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 10px 12px;
  background: linear-gradient(90deg, rgba(126, 224, 126, .04), #0d130d);
}
.meditation-choice span {
  display: grid;
  gap: 2px;
  text-align: left;
}
.meditation-choice strong {
  color: var(--text);
  font-weight: normal;
}
.meditation-choice small {
  font-size: 10px;
}
.meditation-choice em {
  color: var(--accent);
  font-size: 13px;
  font-style: normal;
}

.meditation-panel.active {
  position: relative;
  overflow: hidden;
  border-color: rgba(126, 224, 126, .32);
  background:
    radial-gradient(circle at 50% 24%, rgba(126, 224, 126, .12), transparent 58%),
    linear-gradient(180deg, #101710, var(--panel));
}
.meditation-panel.active::before {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: radial-gradient(circle at 50% 42%, rgba(126, 224, 126, .06), transparent 70%);
}
.meditation-active {
  position: relative;
  z-index: 1;
}
.meditation-orb {
  position: relative;
  display: grid;
  place-items: center;
  width: 190px;
  height: 190px;
  margin: 4px auto 0;
}
.orb-halo {
  position: absolute;
  border-radius: 50%;
}
.orb-halo-outer {
  width: 166px;
  height: 166px;
  border: 1px solid rgba(126, 224, 126, .22);
  box-shadow: 0 0 34px rgba(126, 224, 126, .06) inset;
  animation: meditation-breathe 6.2s ease-in-out infinite;
}
.orb-halo-inner {
  width: 132px;
  height: 132px;
  border: 1px dashed rgba(126, 224, 126, .18);
  animation: meditation-breathe 6.2s ease-in-out infinite reverse;
}
.orb-core {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 118px;
  height: 118px;
  border: 1px solid rgba(126, 224, 126, .34);
  border-radius: 50%;
  background: radial-gradient(circle, rgba(126, 224, 126, .16), #0b110b 72%);
  box-shadow: 0 0 26px rgba(126, 224, 126, .12);
  animation: meditation-core 6.2s ease-in-out infinite;
}
.orb-character {
  color: var(--accent);
  font-size: 23px;
  line-height: 1;
  text-shadow: 0 0 14px rgba(126, 224, 126, .52);
}
.orb-time {
  margin-top: 6px;
  color: var(--text);
  font-size: 19px;
  font-weight: normal;
  letter-spacing: 2px;
}
.meditation-phase {
  margin: 1px 0 0;
  color: var(--muted);
  font-size: 11px;
  line-height: 1.6;
  text-align: center;
}

.meditation-notes {
  margin-top: 9px;
  padding: 8px 9px;
  border: 1px dashed rgba(42, 58, 42, .9);
  border-radius: 5px;
  background: rgba(13, 19, 13, .45);
}
.meditation-notes .row {
  padding: 0;
}
.meditation-hint {
  margin: 7px 0 0;
  font-size: 10px;
  text-align: center;
}
.meditation-active > button {
  width: 100%;
  margin-top: 10px;
}

.meditation-active.finished .orb-core {
  border-color: rgba(126, 224, 126, .64);
  box-shadow: 0 0 34px rgba(126, 224, 126, .24);
  animation-duration: 3.2s;
}

@keyframes meditation-breathe {
  0%, 100% {
    opacity: .48;
    transform: scale(.94);
  }
  50% {
    opacity: 1;
    transform: scale(1.06);
  }
}
@keyframes meditation-core {
  0%, 100% {
    opacity: .82;
    transform: scale(.96);
  }
  50% {
    opacity: 1;
    transform: scale(1.04);
  }
}
@media (max-width: 460px) {
  .meditation-orb {
    width: 170px;
    height: 170px;
  }
  .orb-halo-outer {
    width: 150px;
    height: 150px;
  }
  .orb-halo-inner {
    width: 120px;
    height: 120px;
  }
  .orb-core {
    width: 106px;
    height: 106px;
  }
  .orb-time {
    font-size: 17px;
  }
}
@media (prefers-reduced-motion: reduce) {
  .orb-halo,
  .orb-core {
    animation: none;
  }
}
</style>

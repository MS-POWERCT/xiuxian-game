<template>
  <section class="panel">
    <h3>冥想</h3>
    <div v-if="!store.meditation" class="select">
      <button v-for="m in meditations" :key="m.id" :disabled="blocked || isLocked(m)" data-sfx="meditate_start"
        @click="start(m)">
        {{ m.name }}（{{ m.duration_seconds }}秒）
        <span v-if="isLocked(m)" class="muted">🔒 {{ unlockHint(m.id) }}</span>
      </button>
    </div>
    <div v-else>
      <div class="row">
        <span>{{ activeName }}</span>
        <span class="muted">{{ elapsed }}s / {{ store.meditation.duration }}s</span>
      </div>
      <div class="bar">
        <div class="bar-fill" :style="{ width: percent + '%' }"></div>
      </div>
      <button v-if="finished" class="primary" :disabled="claiming" @click="claim">
        {{ claiming ? '结算中…' : '结算冥想' }}
      </button>
      <p v-else class="muted">入定中，刷新页面不会中断。</p>
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
const remaining = computed(() => {
  if (!store.meditation) return 0
  return Math.max(0, store.meditation.finish_at - now.value)
})
const elapsed = computed(() => {
  if (!store.meditation) return 0
  return Math.min(store.meditation.duration, store.meditation.duration - remaining.value)
})
const percent = computed(() => {
  if (!store.meditation || store.meditation.duration <= 0) return 0
  return Math.min(100, Math.floor((elapsed.value / store.meditation.duration) * 100))
})
const finished = computed(() => !!store.meditation && remaining.value === 0)
const activeName = computed(() => {
  const duration = store.meditation?.duration
  return meditations.find((m) => m.duration_seconds === duration)?.name ?? '冥想'
})

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
  try {
    const data = await store.claimMeditation()
    const level = data.gained_exp >= 40 ? 'advanced' : 'basic'
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

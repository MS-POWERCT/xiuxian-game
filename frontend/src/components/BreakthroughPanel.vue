<template>
  <section class="panel">
    <h3>渡劫突破</h3>
    <p v-if="blocked" class="muted">{{ hint }}</p>
    <p v-else-if="!canBreakthrough" class="muted">修为未满，继续冥想 / 闭关积累修为。</p>
    <button v-else class="primary" data-sfx="breakthrough" @click="doBreakthrough">突破至「{{ nextRealmName }}」</button>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { usePlayerStore } from '@/stores/player'
import { realmsConfig } from '@/config'
import { statusHint } from '@/utils/status'
import { useToastStore } from '@/stores/toast'

const store = usePlayerStore()
const toast = useToastStore()

const blocked = computed(() => (store.player?.status ?? 'idle') !== 'idle')
const hint = computed(() => statusHint(store.player?.status ?? 'idle'))

const currentIndex = computed(() =>
  store.player ? realmsConfig.realms.findIndex((r) => r.id === store.player!.realm_id) : -1
)
const realm = computed(() =>
  store.player ? realmsConfig.realms.find((r) => r.id === store.player!.realm_id) : undefined
)
const nextRealm = computed(() => {
  const i = currentIndex.value
  return i >= 0 ? realmsConfig.realms[i + 1] : undefined
})
const nextRealmName = computed(() => nextRealm.value?.name ?? '')

const canBreakthrough = computed(() => {
  if (!store.player || !realm.value || !nextRealm.value) return false
  const last = realm.value.stages.length - 1
  return store.player.stage_index === last && store.player.exp >= realm.value.exp_to_next[last]
})

async function doBreakthrough() {
  try {
    await store.breakthrough()
  } catch (e) {
    toast.error((e as Error).message)
  }
}
</script>
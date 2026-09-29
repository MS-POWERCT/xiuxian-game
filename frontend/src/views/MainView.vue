<template>
  <div v-if="!store.player" class="panel muted">
    {{ store.loading ? '加载中…' : store.loadError }}
  </div>
  <template v-else>
    <template v-if="page === 'settings'">
      <button class="ghost" data-sfx="click" @click="page = 'main'">‹ 返回主界面</button>
      <AudioControl />
      <section class="panel">
        <h3>转世</h3>
        <div class="row"><span>当前</span><span>第 {{ store.player.life_no }} 世</span></div>
        <div class="row"><span>传承速度加成</span><span>×{{ speedBonusText }}</span></div>
        <div v-if="asking" class="confirm-box">
          <p class="muted">{{ confirmText }}</p>
          <div class="btn-row">
            <button class="primary" data-sfx="click" @click="doReincarnate">确认转世</button>
            <button class="ghost" @click="asking = false">取消</button>
          </div>
        </div>
        <button v-else class="primary" :disabled="!canReincarnate" data-sfx="click" @click="askReincarnate">
          {{ store.player.status === 'dead' ? '转世重修' : '重新修仙一世' }}
        </button>
      </section>
      <ReincarnationRecords />
    </template>
    <template v-else>
      <section class="panel">
        <h3>属性</h3>
        <div class="row"><span>道号</span><span>{{ store.player.name }}</span></div>
        <div class="row"><span>境界</span><span>{{ realmName }} · {{ stageName }}</span></div>
        <div class="row"><span>寿元</span><span>{{ store.player.age }} / {{ store.player.lifespan_max }} 年</span></div>
        <div class="row"><span>气血</span><span>{{ store.player.hp }} / 100</span></div>
        <div class="row"><span>灵石</span><span>{{ store.player.spirit_stones }}</span></div>
        <div class="row"><span>修为</span><span>{{ store.player.exp }} / {{ expThreshold }}</span></div>
        <div class="bar" style="margin-top: 8px">
          <div class="bar-fill" :style="{ width: expPercent + '%' }"></div>
        </div>
      </section>

      <MeditateBar />
      <RetreatPanel />
      <BreakthroughPanel />

      <button class="ghost settings-entry" data-sfx="click" @click="page = 'settings'">转世与档案</button>
    </template>
  </template>
  <button class="logout" @click="auth.logout()">退出登录</button>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { usePlayerStore } from '@/stores/player'
import { useAuthStore } from '@/stores/auth'
import { realmOf } from '@/config'
import { useToastStore } from '@/stores/toast'
import MeditateBar from '@/components/MeditateBar.vue'
import RetreatPanel from '@/components/RetreatPanel.vue'
import BreakthroughPanel from '@/components/BreakthroughPanel.vue'
import AudioControl from '@/components/AudioControl.vue'
import ReincarnationRecords from '@/components/ReincarnationRecords.vue'

const store = usePlayerStore()
const auth = useAuthStore()
const toast = useToastStore()

onMounted(() => store.load())

const page = ref<'main' | 'settings'>('main')
const asking = ref(false)

const canReincarnate = computed(() => {
  const s = store.player?.status ?? 'idle'
  return s === 'idle' || s === 'dead'
})
const speedBonusText = computed(() =>
  (1 + (store.player?.speed_bonus ?? 0)).toFixed(2)
)
const confirmText = computed(() =>
  store.player?.status === 'dead'
    ? '转世重修后重获新生，传承灵石与速度加成，是否继续？'
    : '主动兵解将损失本世修为与境界，仅传承灵石与速度加成，是否继续？'
)

function askReincarnate() {
  if (!canReincarnate.value) return
  asking.value = true
}

async function doReincarnate() {
  asking.value = false
  try {
    await store.reincarnate()
  } catch (e) {
    toast.error((e as Error).message)
  }
}

const realm = computed(() => (store.player ? realmOf(store.player.realm_id) : undefined))
const realmName = computed(() => realm.value?.name ?? '')
const stageName = computed(() => (realm.value ? realm.value.stages[store.player!.stage_index] : ''))
const expThreshold = computed(() => (realm.value ? realm.value.exp_to_next[store.player!.stage_index] : 0))
const expPercent = computed(() => {
  const t = expThreshold.value
  if (!t) return 0
  return Math.min(100, Math.floor((store.player!.exp / t) * 100))
})
</script>
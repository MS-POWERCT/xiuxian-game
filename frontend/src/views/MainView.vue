<template>
  <div v-if="!store.player" class="panel muted">
    {{ store.loading ? '加载中…' : store.loadError }}
  </div>
  <template v-else>
    <template v-if="page === 'settings'">
      <button class="ghost" data-sfx="click" @click="page = 'main'">‹ 返回主界面</button>
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
    <template v-else-if="page === 'path'">
      <button class="ghost" data-sfx="click" @click="page = 'main'">‹ 返回洞府</button>

      <section class="panel path-hero">
        <p class="attribute-eyebrow">修行之路</p>
        <h3>大道无声，日拱一卒</h3>
        <p>不急，不赶。每天做一点，路就会长一点。</p>
      </section>

      <div class="path-tabs">
        <button :class="{ active: pathTab === 'game' }" @click="pathTab = 'game'">玩法速览</button>
        <button :class="{ active: pathTab === 'heart' }" @click="pathTab = 'heart'">修行心语</button>
      </div>

      <section v-if="pathTab === 'game'" class="panel path-page">
        <h3>每天能做什么</h3>
        <div class="quick-grid">
          <div class="quick-card">
            <span>感悟</span>
            <strong>点 5 次，微涨修为</strong>
            <small>碎片时间</small>
          </div>
          <div class="quick-card">
            <span>冥想</span>
            <strong>静候 3 至 20 分钟</strong>
            <small>定期积累</small>
          </div>
          <div class="quick-card">
            <span>闭关</span>
            <strong>投入一天或两天</strong>
            <small>长线收益</small>
          </div>
          <div class="quick-card">
            <span>渡劫</span>
            <strong>修为圆满，冲击境界</strong>
            <small>可能失败</small>
          </div>
        </div>
        <div class="path-flow">
          <span>感悟 / 冥想</span><i>→</i><span>攒修为</span><i>→</i><span>闭关</span><i>→</i><span>渡劫</span>
        </div>
        <p class="path-tip">记住一件事：寿元会一直流逝。</p>
      </section>

      <section v-else class="panel path-page heart-page">
        <h3>修仙，本来就是一件无聊的事</h3>
        <p class="heart-lead">不是每天都有奇遇。<br />大多数时候，只是重复昨天。</p>
        <div class="heart-cards">
          <div><span>大多数日子</span><strong>感悟、冥想、闭关</strong></div>
          <div><span>剩下的时间</span><strong>等一点变化发生</strong></div>
        </div>
        <blockquote>仙路漫漫，慢也是一种前进。<br />你今天来过，这条路上就多了一道脚印。</blockquote>
      </section>

      <AudioControl />
      <section class="panel path-settings">
        <h3>账号</h3>
        <button class="ghost logout-inside" @click="auth.logout()">退出登录</button>
      </section>
    </template>
    <template v-else>
      <section class="panel attribute-panel">
        <div class="attribute-head">
          <div class="identity-seal">修</div>
          <div class="identity-main">
            <p class="attribute-eyebrow">在世修士</p>
            <h3>{{ store.player.name }}</h3>
            <div class="identity-meta">
              <span class="realm-chip">{{ realmName }} · {{ stageName }}</span>
              <span>第 {{ store.player.life_no }} 世</span>
            </div>
          </div>
        </div>

        <div class="attribute-grid">
          <div class="attribute-card">
            <span>寿元</span>
            <strong>{{ store.player.age }}<small> / {{ store.player.lifespan_max }} 年</small></strong>
            <div class="attribute-track"><i :style="{ width: lifePercent + '%' }"></i></div>
          </div>
          <div class="attribute-card">
            <span>气血</span>
            <strong>{{ store.player.hp }}<small> / 100</small></strong>
            <div class="attribute-track hp"><i :style="{ width: hpPercent + '%' }"></i></div>
          </div>
          <div class="attribute-card plain">
            <span>灵石</span>
            <strong>{{ store.player.spirit_stones }}</strong>
            <small>修行资粮</small>
          </div>
          <div class="attribute-card plain">
            <span>悟性</span>
            <strong>×{{ cultivateRateText }}</strong>
            <small>年龄补偿 {{ Math.round((store.player.cultivate_rate - 1) * 100) }}%</small>
          </div>
        </div>

        <div id="exp-reward-target" class="cultivation-card">
          <div class="cultivation-head">
            <span>修为</span>
            <span>{{ store.player.exp }} / {{ expThreshold }}</span>
          </div>
          <div class="cultivation-track"><i :style="{ width: expPercent + '%' }"></i></div>
        </div>
      </section>

      <DazuoPanel />
      <MeditateBar />
      <RetreatPanel />
      <BreakthroughPanel />

      <button class="ghost settings-entry" data-sfx="click" @click="page = 'settings'">转世与档案</button>
    </template>
  </template>
  <button v-if="page !== 'path'" class="path-entry" data-sfx="click" @click="page = 'path'">修行之路</button>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { usePlayerStore } from '@/stores/player'
import { useAuthStore } from '@/stores/auth'
import { realmOf } from '@/config'
import { useToastStore } from '@/stores/toast'
import MeditateBar from '@/components/MeditateBar.vue'
import DazuoPanel from '@/components/DazuoPanel.vue'
import RetreatPanel from '@/components/RetreatPanel.vue'
import BreakthroughPanel from '@/components/BreakthroughPanel.vue'
import AudioControl from '@/components/AudioControl.vue'
import ReincarnationRecords from '@/components/ReincarnationRecords.vue'

const store = usePlayerStore()
const auth = useAuthStore()
const toast = useToastStore()

onMounted(() => store.load())

const page = ref<'main' | 'settings' | 'path'>('main')
const pathTab = ref<'game' | 'heart'>('game')
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
const lifePercent = computed(() => {
  const max = store.player?.lifespan_max ?? 0
  if (!max) return 0
  return Math.min(100, Math.floor(((store.player?.age ?? 0) / max) * 100))
})
const hpPercent = computed(() => Math.min(100, Math.max(0, store.player?.hp ?? 0)))
const cultivateRateText = computed(() => (store.player?.cultivate_rate ?? 1).toFixed(2))
</script>

<style scoped>
.attribute-panel {
  position: relative;
  overflow: hidden;
  border-color: rgba(126, 224, 126, .30);
  background:
    radial-gradient(circle at 12% 0, rgba(126, 224, 126, .11), transparent 46%),
    linear-gradient(180deg, #111911, var(--panel));
}
.attribute-panel::after {
  content: '';
  position: absolute;
  right: -28px;
  top: -34px;
  width: 120px;
  height: 120px;
  border: 1px solid rgba(126, 224, 126, .08);
  border-radius: 50%;
  pointer-events: none;
}
.attribute-head {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}
.identity-seal {
  display: grid;
  place-items: center;
  flex: none;
  width: 54px;
  height: 54px;
  border: 1px solid rgba(126, 224, 126, .52);
  border-radius: 50%;
  color: var(--accent);
  background: radial-gradient(circle, rgba(126, 224, 126, .20), rgba(126, 224, 126, .03));
  box-shadow: 0 0 20px rgba(126, 224, 126, .12);
  font-size: 22px;
  text-shadow: 0 0 12px rgba(126, 224, 126, .55);
}
.identity-main {
  min-width: 0;
}
.attribute-eyebrow {
  margin: 0 0 2px;
  color: var(--muted);
  font-size: 10px;
  letter-spacing: 3px;
}
.identity-main h3 {
  margin: 0;
  padding: 0;
  border: 0;
  color: var(--text);
  font-size: 18px;
  font-weight: normal;
  letter-spacing: 1px;
}
.identity-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 7px;
  margin-top: 5px;
  color: var(--muted);
  font-size: 10px;
}
.realm-chip {
  padding: 2px 6px;
  border: 1px solid rgba(126, 224, 126, .28);
  border-radius: 999px;
  color: var(--accent);
  background: rgba(126, 224, 126, .06);
}
.attribute-grid {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 7px;
}
.attribute-card {
  min-width: 0;
  padding: 8px 9px;
  border: 1px solid rgba(42, 58, 42, .85);
  border-radius: 5px;
  background: rgba(13, 19, 13, .58);
}
.attribute-card > span {
  display: block;
  margin-bottom: 4px;
  color: var(--muted);
  font-size: 10px;
  letter-spacing: 1px;
}
.attribute-card strong {
  display: block;
  overflow: hidden;
  color: var(--text);
  font-size: 15px;
  font-weight: normal;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.attribute-card strong small {
  color: var(--muted);
  font-size: 10px;
}
.attribute-card.plain > small {
  display: block;
  margin-top: 3px;
  color: var(--muted);
  font-size: 9px;
}
.attribute-track {
  height: 3px;
  margin-top: 7px;
  overflow: hidden;
  border-radius: 999px;
  background: rgba(126, 224, 126, .10);
}
.attribute-track i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, rgba(126, 224, 126, .35), var(--accent));
  box-shadow: 0 0 8px rgba(126, 224, 126, .45);
  transition: width .8s ease;
}
.attribute-track.hp i {
  background: linear-gradient(90deg, #78b98a, #b8e6a4);
}
.cultivation-card {
  position: relative;
  z-index: 1;
  margin-top: 9px;
  padding: 9px;
  border: 1px solid rgba(126, 224, 126, .18);
  border-radius: 5px;
  background: linear-gradient(90deg, rgba(126, 224, 126, .05), rgba(13, 19, 13, .62));
}
.cultivation-head {
  display: flex;
  justify-content: space-between;
  margin-bottom: 7px;
  color: var(--muted);
  font-size: 10px;
}
.cultivation-track {
  height: 6px;
  overflow: hidden;
  border-radius: 999px;
  background: #0a100a;
}
.cultivation-track i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, rgba(126, 224, 126, .30), rgba(194, 255, 194, .95));
  box-shadow: 0 0 14px rgba(126, 224, 126, .48);
  transition: width .9s cubic-bezier(.22, .61, .36, 1);
}

.path-entry {
  width: 100%;
  margin-top: 12px;
  border-style: dashed;
  opacity: .82;
}
.path-hero {
  position: relative;
  overflow: hidden;
  border-color: rgba(126, 224, 126, .30);
  background:
    radial-gradient(circle at 85% 20%, rgba(126, 224, 126, .13), transparent 48%),
    linear-gradient(180deg, #111911, var(--panel));
}
.path-hero::after {
  content: '道';
  position: absolute;
  right: 13px;
  bottom: -22px;
  color: rgba(126, 224, 126, .07);
  font-size: 90px;
  pointer-events: none;
}
.path-hero h3 {
  border: 0;
  margin: 0 0 5px;
  padding: 0;
  color: var(--text);
  font-size: 18px;
  font-weight: normal;
}
.path-hero p {
  margin: 0;
  color: var(--muted);
  font-size: 11px;
}
.path-tabs {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 7px;
  margin-bottom: 12px;
}
.path-tabs button {
  padding: 8px;
  border-style: dashed;
  color: var(--muted);
}
.path-tabs button.active {
  border-style: solid;
  border-color: rgba(126, 224, 126, .52);
  color: var(--accent);
  background: rgba(126, 224, 126, .07);
}
.path-page h3 {
  color: var(--text);
}
.quick-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}
.quick-card {
  padding: 9px;
  border: 1px solid rgba(42, 58, 42, .9);
  border-radius: 5px;
  background: rgba(13, 19, 13, .55);
}
.quick-card span {
  display: block;
  color: var(--accent);
  font-size: 11px;
}
.quick-card strong {
  display: block;
  margin: 5px 0 3px;
  color: var(--text);
  font-size: 12px;
  font-weight: normal;
  line-height: 1.5;
}
.quick-card small {
  color: var(--muted);
  font-size: 9px;
}
.path-flow {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 5px;
  margin-top: 12px;
  color: var(--muted);
  font-size: 10px;
}
.path-flow span {
  padding: 3px 5px;
  border: 1px dashed rgba(126, 224, 126, .16);
  border-radius: 3px;
}
.path-flow i {
  color: rgba(126, 224, 126, .52);
  font-style: normal;
}
.path-tip {
  margin: 10px 0 0;
  color: var(--muted);
  font-size: 10px;
  text-align: center;
}
.heart-page h3 {
  color: var(--text);
  line-height: 1.5;
}
.heart-lead {
  margin: 0 0 12px;
  color: var(--text);
  font-size: 13px;
  line-height: 1.9;
}
.heart-cards {
  display: grid;
  gap: 7px;
}
.heart-cards div {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 9px;
  border: 1px solid rgba(42, 58, 42, .9);
  border-radius: 5px;
  background: rgba(13, 19, 13, .55);
}
.heart-cards span {
  color: var(--muted);
  font-size: 10px;
}
.heart-cards strong {
  color: var(--text);
  font-size: 11px;
  font-weight: normal;
}
.heart-page blockquote {
  margin: 12px 0 0;
  padding: 10px;
  border-left: 2px solid rgba(126, 224, 126, .52);
  color: var(--accent);
  background: rgba(126, 224, 126, .05);
  font-size: 11px;
  line-height: 1.9;
}
.path-settings h3 {
  color: var(--muted);
}
.logout-inside {
  width: 100%;
}

@media (max-width: 420px) {
  .quick-grid {
    grid-template-columns: 1fr;
  }
  .heart-cards div {
    align-items: flex-start;
    flex-direction: column;
    gap: 3px;
  }
}
</style>

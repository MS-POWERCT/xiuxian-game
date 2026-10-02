<template>
  <section class="panel">
    <h3>游历</h3>

    <p v-if="!unlocked" class="muted">
      {{ unlockHint('travel') }}后开放。届时可外出游历，途中或遇奇缘。
    </p>

    <template v-else>
      <!-- 进行中：赶路场景 + 倒计时 + 行程进度 -->
      <div v-if="travel.activeTravel" class="travel-active">
        <div class="scene" :class="activeMethod">
          <svg class="ridge" viewBox="0 0 120 40" preserveAspectRatio="none" aria-hidden="true">
            <path class="ridge-far" d="M0 30 L14 18 L26 27 L40 11 L54 25 L68 15 L82 26 L96 17 L120 31" />
            <path class="ridge-near" d="M0 38 L20 30 L36 36 L58 26 L76 35 L98 27 L120 38" />
          </svg>
          <span class="cloud cloud-a"></span>
          <span class="cloud cloud-b"></span>
          <!-- 行者位置随行程推进 -->
          <span class="traveler" :style="{ left: travelerLeft + '%' }">
            <!-- 御剑飞行 -->
            <svg v-if="activeMethod === 'fly'" class="figure fly" viewBox="0 0 44 34" aria-hidden="true">
              <path class="sword" d="M3 26 H34" />
              <path class="trail" d="M34 26 h8" />
              <circle cx="17" cy="9" r="3" />
              <path d="M17 12 v7" />
              <path class="sleeve-a" d="M17 14 l-5 6" />
              <path class="sleeve-b" d="M17 14 l5 6" />
              <path class="hem" d="M14 19 h6 l1.5 5 h-9 z" />
            </svg>
            <!-- 步行 -->
            <svg v-else class="figure walk" viewBox="0 0 24 34" aria-hidden="true">
              <circle cx="12" cy="6" r="2.8" />
              <path d="M12 9 v9" />
              <path class="limb leg-a" d="M12 18 l-3.5 9" />
              <path class="limb leg-b" d="M12 18 l3.5 9" />
              <path class="limb arm-a" d="M12 12 l-4 4" />
              <path class="limb arm-b" d="M12 12 l4 4" />
            </svg>
          </span>
        </div>
        <div class="row">
          <span>{{ travel.activeTravel.mode_name }} · {{ methodLabel(activeMethod) }}</span>
          <span>{{ remainText }}</span>
        </div>
        <div class="track"><i :style="{ width: progress + '%' }"></i></div>
        <p class="muted tip">
          {{ activeMethod === 'fly' ? '御剑千里，云海在脚下。' : '一路步行，山道在脚下。' }}归来时或有奇遇。
        </p>
      </div>

      <!-- 空闲：选择赶路方式 -->
      <div v-else class="mode-row">
        <button
          v-for="m in modes"
          :key="m.id"
          class="ghost mode-btn"
          :disabled="!canStart"
          data-sfx="click"
          @click="start(m)"
        >
          <span class="mode-name">{{ m.name }}</span>
          <small>{{ methodLabel(m.method) }} · {{ m.duration_minutes }} 分钟</small>
        </button>
      </div>
      <p v-if="slotsFull && !travel.activeTravel" class="muted tip">事件已满，先处理或放弃旧事件。</p>
      <p v-else-if="meditating && !travel.activeTravel" class="muted tip">冥想中无法外出游历，静候功成。</p>

      <!-- 事件槽 -->
      <div class="slot-grid">
        <div
          v-for="slot in slots"
          :key="slot.key"
          class="slot"
          :class="[slot.event ? slot.event.quality : 'empty', { opened: !!slot.event && opened === slot.event.id }]"
        >
          <template v-if="slot.event">
            <div class="slot-head">
              <span class="slot-name">{{ slot.event.name }}</span>
              <em class="quality" :class="slot.event.quality">{{ qualityLabel(slot.event.quality) }}</em>
            </div>
            <p class="muted slot-meta">
              <span class="slot-type">{{ typeLabel(slot.event.type) }}</span>
              <span>剩余 {{ expireText(slot.event) }}</span>
            </p>
            <div v-if="opened === slot.event.id" class="slot-open">
              <p v-if="slot.event.desc" class="muted slot-desc">{{ slot.event.desc }}</p>
              <div class="btn-row">
                <button class="primary" data-sfx="click" @click="resolve(slot.event)">完成</button>
                <button class="ghost" @click="abandon(slot.event)">放弃</button>
              </div>
            </div>
            <button v-else class="ghost small" @click="opened = slot.event.id">查看</button>
          </template>
          <span v-else class="muted empty-text">尚无奇遇</span>
        </div>
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import type { TravelEvent } from '@/api/game'
import type { TravelModeConfig } from '@/config'
import {
  eventQualityLabels,
  eventTypeLabels,
  isFeatureUnlocked,
  travelConfig,
  travelMethodLabels,
  unlockHint,
} from '@/config'
import { usePlayerStore } from '@/stores/player'
import { useTravelStore } from '@/stores/travel'
import { useToastStore } from '@/stores/toast'

const travel = useTravelStore()
const player = usePlayerStore()
const toast = useToastStore()

const modes = travelConfig.modes
const now = ref(Math.floor(Date.now() / 1000))
const opened = ref<number | null>(null)

const unlocked = computed(() =>
  player.player
    ? isFeatureUnlocked('travel', player.player.realm_id, player.player.stage_index)
    : false
)

const slotCount = computed(() => travel.maxSlots || travelConfig.max_event_slots)
const slotsFull = computed(() => travel.events.length >= slotCount.value)
// 冥想中不能外出游历（闭关中允许），与后端 guard 保持一致
const meditating = computed(() => player.player?.status === 'meditating')
const canStart = computed(() => !slotsFull.value && !meditating.value)
const slots = computed(() => {
  const out: { key: number; event: TravelEvent | null }[] = []
  for (let i = 0; i < slotCount.value; i++) {
    out.push({ key: i, event: travel.events[i] ?? null })
  }
  return out
})

// 当前游历的赶路方式（步行 / 御剑飞行），取自 travel.json 的 modes
const activeMethod = computed(
  () => modes.find((m) => m.id === travel.activeTravel?.mode_id)?.method ?? 'walk'
)

const remain = computed(() => Math.max(0, (travel.activeTravel?.finish_at ?? 0) - now.value))
const remainText = computed(() => fmtDuration(remain.value))
const progress = computed(() => {
  const t = travel.activeTravel
  if (!t) return 0
  const total = t.finish_at - t.start_at
  if (total <= 0) return 100
  return Math.min(100, Math.floor(((now.value - t.start_at) / total) * 100))
})
// 行者沿场景横向推进（留出两端余量，避免贴边）
const travelerLeft = computed(() => 6 + progress.value * 0.88)

function methodLabel(method: string) {
  return travelMethodLabels[method] ?? method
}

function typeLabel(type: string) {
  return eventTypeLabels[type] ?? type
}

function qualityLabel(quality: string) {
  return eventQualityLabels[quality] ?? quality
}

function fmtDuration(sec: number): string {
  if (sec <= 0) return '即将归来'
  const h = Math.floor(sec / 3600)
  const m = Math.floor((sec % 3600) / 60)
  const s = sec % 60
  if (h > 0) return `${h} 时 ${m} 分`
  if (m > 0) return `${m} 分 ${s} 秒`
  return `${s} 秒`
}

function expireText(e: TravelEvent): string {
  const sec = Math.max(0, e.expire_at - now.value)
  if (sec <= 0) return '已过期'
  const d = Math.floor(sec / 86400)
  const h = Math.floor((sec % 86400) / 3600)
  if (d > 0) return `${d} 天 ${h} 时`
  const m = Math.floor((sec % 3600) / 60)
  return h > 0 ? `${h} 时 ${m} 分` : `${m} 分`
}

async function start(mode: TravelModeConfig) {
  try {
    const data = await travel.start(mode.id)
    toast.success(`${data.mode_name}已出发`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}

async function resolve(ev: TravelEvent) {
  opened.value = null
  try {
    const data = await travel.resolve(ev.id)
    const parts: string[] = []
    if (data.patron?.kind === 'mortal') parts.push(`庇护凡人 +${data.patron.count ?? 1}`)
    if (data.patron?.kind === 'sect') parts.push(`庇护${data.patron.sect_name ?? '宗门'}`)
    if (data.stones) parts.push('获得灵石')
    if (data.items?.length) parts.push(`获得 ${data.items.map((i) => `${i.name}×${i.quantity}`).join('、')}`)
    toast.success(`${ev.name}：${parts.length ? parts.join('，') : '平安无事'}`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}

async function abandon(ev: TravelEvent) {
  opened.value = null
  try {
    await travel.abandon(ev.id)
    toast.show(`已放弃「${ev.name}」`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}

let timer: number | undefined
let refreshing = false

function tick() {
  now.value = Math.floor(Date.now() / 1000)
  const t = travel.activeTravel
  if (t && now.value >= t.finish_at && !refreshing) {
    refreshing = true
    travel.loadTravel().catch(() => {}).finally(() => (refreshing = false))
  }
}

onMounted(() => {
  timer = window.setInterval(tick, 1000)
  travel.loadTravel().catch((e) => toast.error((e as Error).message))
})
onUnmounted(() => {
  if (timer) window.clearInterval(timer)
})
</script>

<style scoped>
.travel-active {
  padding: 9px;
  border: 1px solid var(--border);
  border-radius: 5px;
  background: rgba(13, 19, 13, .55);
}

/* 赶路场景：山脊 + 流云 + 行者 */
.scene {
  position: relative;
  height: 76px;
  margin-bottom: 9px;
  overflow: hidden;
  border: 1px dashed rgba(42, 58, 42, .9);
  border-radius: 5px;
  background: linear-gradient(180deg, rgba(126, 224, 126, .05), rgba(10, 16, 10, .78));
  user-select: none;
}
.ridge {
  position: absolute;
  right: 0;
  bottom: 0;
  left: 0;
  width: 100%;
  height: 58%;
}
.ridge-far {
  fill: none;
  stroke: rgba(126, 224, 126, .20);
  stroke-width: 1;
}
.ridge-near {
  fill: none;
  stroke: rgba(126, 224, 126, .10);
  stroke-width: 1;
}
.cloud {
  position: absolute;
  height: 1px;
  background: rgba(126, 224, 126, .22);
  animation: drift 11s linear infinite;
}
.cloud-a {
  top: 17px;
  width: 26px;
}
.cloud-b {
  top: 32px;
  width: 15px;
  animation-duration: 16s;
  animation-delay: -6s;
}
@keyframes drift {
  from { transform: translateX(-30px); }
  to { transform: translateX(320px); }
}

.traveler {
  position: absolute;
  bottom: 10px;
  transform: translateX(-50%);
  transition: left .9s linear;
}
.figure {
  display: block;
  width: auto;
  height: 42px;
  fill: none;
  stroke: var(--accent);
  stroke-width: 1.4;
  stroke-linecap: round;
  stroke-linejoin: round;
  filter: drop-shadow(0 0 5px rgba(126, 224, 126, .45));
}
/* 步行：迈步 + 手臂摆动 + 轻微起伏 */
.figure.walk {
  animation: bob .31s ease-in-out infinite alternate;
}
.figure.walk .leg-a,
.figure.walk .leg-b,
.figure.walk .arm-a,
.figure.walk .arm-b {
  transform-box: view-box;
}
.figure.walk .leg-a {
  transform-origin: 12px 18px;
  animation: swing .62s ease-in-out infinite alternate;
}
.figure.walk .leg-b {
  transform-origin: 12px 18px;
  animation: swing .62s ease-in-out infinite alternate-reverse;
}
.figure.walk .arm-a {
  transform-origin: 12px 12px;
  animation: swing .62s ease-in-out infinite alternate-reverse;
}
.figure.walk .arm-b {
  transform-origin: 12px 12px;
  animation: swing .62s ease-in-out infinite alternate;
}
@keyframes swing {
  from { transform: rotate(-22deg); }
  to { transform: rotate(22deg); }
}
@keyframes bob {
  from { transform: translateY(0); }
  to { transform: translateY(-1.5px); }
}
/* 御剑飞行：悬停起伏 + 剑尾流光 */
.scene.fly .traveler {
  bottom: 20px;
}
.figure.fly {
  animation: hover 2.6s ease-in-out infinite;
}
.figure.fly .sword {
  stroke-width: 1.6;
}
.figure.fly .trail {
  stroke: rgba(126, 224, 126, .45);
  stroke-dasharray: 2 3;
  animation: trail 1s linear infinite;
}
@keyframes hover {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}
@keyframes trail {
  to { stroke-dashoffset: -10; }
}

.track {
  height: 4px;
  margin: 7px 0;
  overflow: hidden;
  border-radius: 999px;
  background: rgba(126, 224, 126, .10);
}
.track i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, rgba(126, 224, 126, .35), var(--accent));
  box-shadow: 0 0 8px rgba(126, 224, 126, .45);
  transition: width .8s ease;
}

.mode-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}
.mode-btn {
  display: flex;
  flex-direction: column;
  gap: 3px;
  width: 100%;
}
.mode-name {
  color: var(--text);
  font-size: 12px;
}
.mode-btn:hover:not(:disabled) .mode-name {
  color: var(--accent);
}
.mode-row small {
  color: var(--muted);
  font-size: 9px;
}
.tip {
  margin: 8px 0 0;
  font-size: 10px;
}

.slot-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-top: 10px;
}
.slot {
  min-height: 66px;
  padding: 8px 9px;
  border: 1px solid var(--border);
  border-radius: 5px;
  background: rgba(13, 19, 13, .55);
  transition: border-color .6s, background .6s;
}
.slot.rare {
  border-color: rgba(126, 224, 126, .32);
}
.slot.epic {
  border-color: rgba(196, 160, 255, .40);
}
.slot.opened {
  background: rgba(126, 224, 126, .06);
}
.slot.empty {
  display: grid;
  place-items: center;
  border-style: dashed;
}
.empty-text {
  font-size: 10px;
}
.slot-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}
.slot-name {
  overflow: hidden;
  color: var(--text);
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.quality {
  flex: none;
  padding: 1px 5px;
  border: 1px solid rgba(42, 58, 42, .9);
  border-radius: 999px;
  color: var(--muted);
  font-size: 9px;
  font-style: normal;
}
.quality.rare {
  border-color: rgba(126, 224, 126, .36);
  color: var(--accent);
}
.quality.epic {
  border-color: rgba(196, 160, 255, .42);
  color: #c4a0ff;
}
.slot-meta {
  display: flex;
  gap: 8px;
  margin: 5px 0;
  font-size: 9px;
}
.slot-type {
  color: rgba(126, 224, 126, .78);
}
.slot-desc {
  margin: 0 0 6px;
  font-size: 9px;
  line-height: 1.7;
}
.slot .small {
  width: 100%;
  padding: 4px;
  font-size: 10px;
}
.slot .btn-row {
  gap: 6px;
}
.slot .btn-row button {
  padding: 4px;
  font-size: 10px;
}
</style>
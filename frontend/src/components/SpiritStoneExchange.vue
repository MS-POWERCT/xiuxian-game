<template>
  <section class="panel">
    <h3>灵石</h3>
    <div class="stone-list">
      <div v-for="level in levels" :key="level" class="stone-row">
        <span class="stone-name">{{ label(level) }}</span>
        <span class="stone-amount">
          {{ stoneOf(level) }}<small> / {{ caps[level] }}</small>
        </span>
      </div>
    </div>

    <div class="exchange">
      <div class="select">
        <span class="muted">兑出</span>
        <select v-model="fromLevel">
          <option v-for="l in levels" :key="l" :value="l">{{ label(l) }}</option>
        </select>
      </div>
      <div class="select">
        <span class="muted">兑入</span>
        <select v-model="toLevel">
          <option v-for="l in toOptions" :key="l" :value="l">{{ label(l) }}</option>
        </select>
      </div>
      <div class="select">
        <span class="muted">数量</span>
        <input v-model.number="amount" type="number" min="1" />
      </div>
      <p class="muted preview">{{ previewText }}</p>
      <button class="primary full" :disabled="!canExchange" data-sfx="click" @click="submit">兑换</button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { economyConfig, spiritStoneLabels } from '@/config'
import { usePlayerStore } from '@/stores/player'
import { useMarketStore } from '@/stores/market'
import { useToastStore } from '@/stores/toast'

const player = usePlayerStore()
const market = useMarketStore()
const toast = useToastStore()

const levels = economyConfig.spirit_stone_levels
const caps = economyConfig.spirit_stone_caps
const ratio = economyConfig.exchange_ratio
const feeRatios = economyConfig.exchange_fee_ratio

const stones = computed(() => (player.player?.spirit_stones ?? { low: 0, mid: 0, high: 0, top: 0 }) as Record<string, number>)

function label(level: string) {
  return spiritStoneLabels[level] ?? level
}
function stoneOf(level: string) {
  return stones.value[level] ?? 0
}

const fromLevel = ref(levels[0])
const toLevel = ref(levels[1] ?? levels[0])
const amount = ref(1)

// 只允许相邻品级兑换：兑入项限定为兑出品的左右邻居
const toOptions = computed(() => {
  const i = levels.indexOf(fromLevel.value)
  return [levels[i - 1], levels[i + 1]].filter((l): l is string => !!l)
})
watch(toOptions, (opts) => {
  if (!opts.includes(toLevel.value)) toLevel.value = opts[0]
})

const isUpgrade = computed(() => levels.indexOf(toLevel.value) > levels.indexOf(fromLevel.value))
const count = computed(() => Math.max(0, Math.floor(amount.value) || 0))
// 升级本金（以兑出品计）；降级免手续费
const principal = computed(() => count.value * ratio)
const fee = computed(() =>
  isUpgrade.value ? Math.ceil(principal.value * (feeRatios[`${fromLevel.value}_to_${toLevel.value}`] ?? 0)) : 0
)

const previewText = computed(() => {
  if (count.value <= 0) return '数量需大于 0'
  if (isUpgrade.value) {
    return `需${label(fromLevel.value)}灵石 ${principal.value + fee.value}（含手续费 ${fee.value}），得${label(toLevel.value)}灵石 ${count.value}`
  }
  return `需${label(fromLevel.value)}灵石 ${count.value}，得${label(toLevel.value)}灵石 ${principal.value}`
})

const canExchange = computed(() => {
  if (count.value <= 0) return false
  const from = fromLevel.value
  const to = toLevel.value
  if (isUpgrade.value) {
    if (stoneOf(from) < principal.value + fee.value) return false
    return stoneOf(to) + count.value <= caps[to]
  }
  if (stoneOf(from) < count.value) return false
  return stoneOf(to) + principal.value <= caps[to]
})

async function submit() {
  if (!canExchange.value) return
  try {
    const data = await market.exchange(fromLevel.value, toLevel.value, count.value)
    toast.success(data.fee > 0 ? `兑换完成，手续费 ${data.fee}` : '兑换完成，免手续费')
  } catch (e) {
    toast.error((e as Error).message)
  }
}
</script>

<style scoped>
.stone-list {
  display: grid;
  gap: 5px;
  margin-bottom: 12px;
}
.stone-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 9px;
  border: 1px solid var(--border);
  border-radius: 5px;
  background: rgba(13, 19, 13, .55);
}
.stone-name {
  color: var(--muted);
  font-size: 11px;
  letter-spacing: 1px;
}
.stone-amount {
  color: var(--text);
  font-size: 13px;
}
.stone-amount small {
  color: var(--muted);
  font-size: 10px;
}
.exchange {
  padding-top: 10px;
  border-top: 1px dashed var(--border);
}
.preview {
  margin: 8px 0;
  font-size: 11px;
  line-height: 1.7;
}
.full {
  width: 100%;
}
</style>
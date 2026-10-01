<template>
  <section class="panel">
    <h3>坊市</h3>
    <p v-if="!market.shopLoaded" class="muted">加载中…</p>
    <p v-else-if="market.shopItems.length === 0" class="muted">坊市空空如也。</p>
    <div v-else class="shop-list">
      <div v-for="item in market.shopItems" :key="item.id" class="shop-item">
        <div class="shop-main">
          <span class="shop-name">{{ item.name }}</span>
          <span class="muted shop-meta">
            {{ categoryLabel(item.category) }} · {{ item.price }} {{ levelLabel(item.currency_level) }}灵石
          </span>
        </div>
        <div class="shop-buy">
          <span class="pill-cnt">
            <button type="button" @click="dec(item.id)">-</button>
            <span>{{ count(item.id) }}</span>
            <button type="button" @click="inc(item.id)">+</button>
          </span>
          <button class="primary" :disabled="!canBuy(item)" data-sfx="click" @click="buy(item)">购买</button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive } from 'vue'
import { spiritStoneLabels } from '@/config'
import type { ShopItem } from '@/api/game'
import { usePlayerStore } from '@/stores/player'
import { useMarketStore } from '@/stores/market'
import { useToastStore } from '@/stores/toast'

const player = usePlayerStore()
const market = useMarketStore()
const toast = useToastStore()

const categoryLabels: Record<string, string> = {
  pill: '丹药',
  formation: '法阵',
  technique: '功法',
  talisman: '符箓',
  material: '材料',
}
function categoryLabel(category: string) {
  return categoryLabels[category] ?? category
}
function levelLabel(level: string) {
  return spiritStoneLabels[level] ?? level
}

const quantities = reactive<Record<string, number>>({})
function count(id: string) {
  return quantities[id] ?? 1
}
function inc(id: string) {
  quantities[id] = count(id) + 1
}
function dec(id: string) {
  quantities[id] = Math.max(1, count(id) - 1)
}

function stoneOf(level: string) {
  const stones = player.player?.spirit_stones as Record<string, number> | undefined
  return stones?.[level] ?? 0
}
function canBuy(item: ShopItem) {
  return stoneOf(item.currency_level) >= item.price * count(item.id)
}

async function buy(item: ShopItem) {
  if (!canBuy(item)) return
  try {
    const data = await market.buy(item.id, count(item.id))
    toast.success(`购得${data.name} ×${data.quantity}，耗${data.cost}${levelLabel(data.currency_level)}灵石`)
  } catch (e) {
    toast.error((e as Error).message)
  }
}

onMounted(() => {
  if (!market.shopLoaded) market.loadShop()
})
</script>

<style scoped>
.shop-list {
  display: grid;
  gap: 8px;
}
.shop-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 9px;
  border: 1px solid var(--border);
  border-radius: 5px;
  background: rgba(13, 19, 13, .55);
}
.shop-main {
  min-width: 0;
}
.shop-name {
  display: block;
  color: var(--text);
  font-size: 13px;
}
.shop-meta {
  display: block;
  margin-top: 3px;
  font-size: 10px;
}
.shop-buy {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
}
.shop-buy .pill-cnt span {
  min-width: 18px;
  text-align: center;
  font-size: 12px;
}
</style>
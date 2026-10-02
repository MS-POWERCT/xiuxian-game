<template>
  <section class="panel">
    <h3>储物戒</h3>
    <p v-if="!market.itemsLoaded" class="muted">加载中…</p>
    <p v-else-if="market.items.length === 0" class="muted">戒中空空，尚无物品。</p>
    <div v-else class="item-list">
      <div v-for="item in market.items" :key="item.item_id" class="item-row">
        <span class="item-main">
          <span class="item-name">{{ item.name }}</span>
          <span class="muted item-cat">{{ categoryLabel(item.category) }}</span>
        </span>
        <span class="item-qty">×{{ item.quantity }}</span>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { categoryLabels } from '@/config'
import { useMarketStore } from '@/stores/market'

const market = useMarketStore()

function categoryLabel(category: string) {
  return categoryLabels[category] ?? category
}

onMounted(() => {
  if (!market.itemsLoaded) market.loadItems()
})
</script>

<style scoped>
.item-list {
  display: grid;
  gap: 6px;
}
.item-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 7px 9px;
  border: 1px solid var(--border);
  border-radius: 5px;
  background: rgba(13, 19, 13, .55);
}
.item-main {
  display: flex;
  align-items: baseline;
  gap: 8px;
  min-width: 0;
}
.item-name {
  color: var(--text);
  font-size: 13px;
}
.item-cat {
  font-size: 10px;
}
.item-qty {
  flex: none;
  color: var(--accent);
  font-size: 12px;
}
</style>
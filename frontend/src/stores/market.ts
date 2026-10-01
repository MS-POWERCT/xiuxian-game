import { defineStore } from 'pinia'
import * as api from '@/api/game'
import { usePlayerStore } from '@/stores/player'

// 坊市状态：商店商品与储物戒物品共享，购买后同步刷新储物戒与灵石
export const useMarketStore = defineStore('market', {
  state: () => ({
    shopItems: [] as api.ShopItem[],
    shopLoaded: false,
    items: [] as api.ItemEntry[],
    itemsLoaded: false,
  }),
  actions: {
    async loadShop() {
      const data = await api.getShop()
      this.shopItems = data.items
      this.shopLoaded = true
    },
    async loadItems() {
      const data = await api.getItems()
      this.items = data.items
      this.itemsLoaded = true
    },
    async buy(itemId: string, quantity: number) {
      const data = await api.buyShopItem(itemId, quantity)
      usePlayerStore().setStones(data.stones)
      await this.loadItems()
      return data
    },
    async exchange(fromLevel: string, toLevel: string, amount: number) {
      const data = await api.exchangeSpiritStones(fromLevel, toLevel, amount)
      usePlayerStore().setStones(data.stones)
      return data
    },
  },
})
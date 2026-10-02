import { defineStore } from 'pinia'
import * as api from '@/api/game'
import { useMarketStore } from '@/stores/market'
import { usePlayerStore } from '@/stores/player'

// 游历 / 事件 / 庇护共享状态：游历状态与事件槽、庇护列表与待领取上供
export const useTravelStore = defineStore('travel', {
  state: () => ({
    maxSlots: 0,
    events: [] as api.TravelEvent[],
    activeTravel: null as api.ActiveTravel | null,
    loaded: false,
    patrons: [] as api.Patron[],
    totalPending: null as api.SpiritStones | null,
    patronsLoaded: false,
  }),
  actions: {
    async loadTravel() {
      const data = await api.getTravel()
      this.maxSlots = data.max_slots
      this.events = data.events
      this.activeTravel = data.active_travel
      this.loaded = true
    },
    async start(modeId: string) {
      const data = await api.startTravel(modeId)
      await this.loadTravel()
      return data
    },
    async resolve(id: number) {
      const data = await api.resolveTravelEvent(id)
      if (data.stones) usePlayerStore().setStones(data.stones)
      await Promise.all([this.loadTravel(), this.loadPatrons()])
      // 游历可能产出储物戒物品，同步刷新一次，避免坊市页展示过期
      if (data.items?.length) await useMarketStore().loadItems()
      return data
    },
    async abandon(id: number) {
      await api.abandonTravelEvent(id)
      await this.loadTravel()
    },
    async loadPatrons() {
      const data = await api.getPatrons()
      this.patrons = data.patrons
      this.totalPending = data.total_pending
      this.patronsLoaded = true
      usePlayerStore().setStones(data.stones)
    },
    async claim() {
      const data = await api.claimPatrons()
      usePlayerStore().setStones(data.stones)
      await this.loadPatrons()
      return data
    },
    async release(id: number) {
      const data = await api.releasePatron(id)
      await this.loadPatrons()
      return data
    },
  },
})
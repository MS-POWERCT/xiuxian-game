import { defineStore } from 'pinia'
import * as api from '@/api/game'
import { useToastStore } from '@/stores/toast'

export const usePlayerStore = defineStore('player', {
  state: () => ({
    player: null as api.Player | null,
    retreat: null as api.ActiveRetreat | null,
    meditation: null as api.ActiveMeditation | null,
    dazuoState: null as api.DazuoState | null,
    loading: false,
    loadError: '' as string,
  }),
  actions: {
    async load() {
      this.loading = true
      try {
        const data = await api.getPlayer()
        this.player = data.player
        this.retreat = data.retreat
        this.meditation = data.meditation
        this.dazuoState = data.dazuo
        this.loadError = ''
      } catch (e) {
        this.loadError = '无法连接后端：' + (e as Error).message
      } finally {
        this.loading = false
      }
    },
    async startMeditation(duration: number) {
      const data = await api.startMeditation(duration)
      this.player = data.player
      this.meditation = data.meditation
      return data
    },
    async claimMeditation() {
      const data = await api.claimMeditation()
      this.player = data.player
      this.meditation = null
      return data
    },
    async dazuo(count: number) {
      const data = await api.dazuo(count)
      this.player = data.player
      this.dazuoState = {
        daily_used: data.daily_used,
        daily_limit: data.daily_limit,
        batch_size: data.batch_size,
      }
      return data
    },
    async startRetreat(payload: api.RetreatStartPayload) {
      const data = await api.startRetreat(payload)
      useToastStore().success(`闭关开始，预计修为 +${data.expected_exp}`)
      await this.load()
    },
    async claimRetreat() {
      if (!this.retreat) return
      const data = await api.claimRetreat(this.retreat.retreat_id)
      this.player = data.player
      this.retreat = null
      this.meditation = null
      return data
    },
    async breakthrough() {
      const data = await api.breakthrough()
      this.player = data.player
      if (data.success) {
        useToastStore().success(`渡劫成功，突破至「${data.to_realm}」`)
      } else {
        useToastStore().error(`渡劫失败，修为 -${data.exp_rollback}，气血 -${data.hp_loss}`)
      }
      return data
    },
    async reincarnate() {
      const data = await api.reincarnate()
      this.player = data.player
      this.retreat = null
      this.meditation = null
      useToastStore().success(`已转世重修，当前第 ${data.player.life_no} 世`)
      await this.load()
      return data
    },
  },
})

import { defineStore } from 'pinia'

// 庆祝等级：basic 日常收获 / advanced 进阶收获 / grand 重大收获
export type CelebrationLevel = 'basic' | 'advanced' | 'grand'

export interface CelebrationPayload {
  title: string
  subtitle?: string
  reward?: string
  lines?: string[]
}

export const useCelebrationStore = defineStore('celebration', {
  state: () => ({
    active: false,
    level: 'basic' as CelebrationLevel,
    title: '',
    subtitle: '',
    reward: '',
    lines: [] as string[],
  }),
  actions: {
    // 全局庆祝入口：任意玩法结束调用即可弹出对应规格的反馈
    celebrate(level: CelebrationLevel, payload: CelebrationPayload) {
      this.level = level
      this.title = payload.title
      this.subtitle = payload.subtitle ?? ''
      this.reward = payload.reward ?? ''
      this.lines = payload.lines ?? []
      this.active = true
    },
    dismiss() {
      this.active = false
    },
  },
})
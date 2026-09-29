import { defineStore } from 'pinia'

// 弹出提示类型：info 中性 / success 成功（青色）/ error 失败（红色）
export type ToastType = 'info' | 'success' | 'error'

let timer: number | undefined

export const useToastStore = defineStore('toast', {
  state: () => ({
    visible: false,
    message: '',
    type: 'info' as ToastType,
  }),
  actions: {
    // 弹出提示，几秒后自动消失；再次弹出会重置计时
    show(message: string, type: ToastType = 'info', duration = 2600) {
      this.message = message
      this.type = type
      this.visible = true
      if (timer) window.clearTimeout(timer)
      timer = window.setTimeout(() => {
        this.visible = false
      }, duration)
    },
    success(message: string) {
      this.show(message, 'success')
    },
    error(message: string) {
      this.show(message, 'error')
    },
  },
})
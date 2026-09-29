import { defineStore } from 'pinia'
import { audioConfig } from '@/config'

// 统一音频管理器：BGM 与音效的开关、音量、播放全部走这里，组件不直接 new Audio()
// 音频路径从 config/audio.json 读取，本地状态存 localStorage。

const STORAGE_KEY = 'xiuxian.audio'

interface Persisted {
  bgmOn: boolean
  sfxOn: boolean
  bgmVolume: number // 0..1
  sfxVolume: number
}

function loadPersisted(): Persisted {
  const def: Persisted = { bgmOn: true, sfxOn: true, bgmVolume: 0.6, sfxVolume: 0.8 }
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return def
    return { ...def, ...(JSON.parse(raw) as Partial<Persisted>) }
  } catch {
    return def
  }
}

// HTMLAudioElement 不放入 state（避免被 Vue 代理），模块级单例由 store 管理
let bgmEl: HTMLAudioElement | null = null
const sfxCache = new Map<string, HTMLAudioElement>()

export const useAudioStore = defineStore('audio', {
  state: () => loadPersisted(),
  actions: {
    persist() {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({
          bgmOn: this.bgmOn,
          sfxOn: this.sfxOn,
          bgmVolume: this.bgmVolume,
          sfxVolume: this.sfxVolume,
        })
      )
    },

    // 首次用户交互后调用，通过浏览器自动播放策略，随后启动 BGM
    // 改为幂等：每次调用若未在播放则尝试 play，跨浏览器兼容更稳

    playSfx(id: string) {
      if (!this.sfxOn) return
      const item = audioConfig.sfx[id]
      if (!item) return
      let el = sfxCache.get(item.src)
      if (!el) {
        el = new Audio(item.src)
        sfxCache.set(item.src, el)
      }
      el.volume = this.sfxVolume
      el.currentTime = 0
      el.play().catch(() => {})
    },

    // 幂等：元素已存在且正在播则直接返回，否则创建/续播
    playBgm() {
      const main = audioConfig.bgm.main
      if (!main || !this.bgmOn) return
      if (!bgmEl) {
        bgmEl = new Audio(main.src)
        bgmEl.loop = main.loop !== false
      }
      bgmEl.volume = this.bgmVolume
      if (!bgmEl.paused) return
      bgmEl.play().catch(() => {})
    },

    stopBgm() {
      bgmEl?.pause()
    },

    toggleBgm() {
      this.bgmOn = !this.bgmOn
      this.persist()
      if (this.bgmOn) this.playBgm()
      else this.stopBgm()
    },

    toggleSfx() {
      this.sfxOn = !this.sfxOn
      this.persist()
    },

    setBgmVolume(v: number) {
      this.bgmVolume = v
      this.persist()
      if (bgmEl) bgmEl.volume = v
    },

    setSfxVolume(v: number) {
      this.sfxVolume = v
      this.persist()
    },
  },
})
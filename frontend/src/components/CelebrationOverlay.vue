<template>
  <Transition name="celebrate">
    <div
      v-if="store.active"
      class="celebrate-overlay"
      :class="'lv-' + store.level"
      @click="store.dismiss()"
    >
      <div class="celebrate-card" @click.stop>
        <div class="glow" aria-hidden="true"></div>
        <div class="sparks" aria-hidden="true">
          <span v-for="s in sparks" :key="s.key" class="spark" :style="s.style">{{ s.char }}</span>
        </div>
        <div class="title">{{ store.title }}</div>
        <div v-if="store.reward" class="reward">{{ store.reward }}</div>
        <div v-if="store.subtitle" class="subtitle">{{ store.subtitle }}</div>
        <p v-if="store.lines.length" class="lines">{{ store.lines.join('\n') }}</p>
        <p class="hint">点击空白处关闭</p>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import { useCelebrationStore, type CelebrationLevel } from '@/stores/celebration'

const store = useCelebrationStore()

interface Spark {
  key: number
  char: string
  style: Record<string, string>
}

// 各等级火花数量：basic 轻量 / advanced 进阶 / grand 盛大
const sparkCount: Record<CelebrationLevel, number> = { basic: 10, advanced: 20, grand: 34 }

const sparks = ref<Spark[]>([])

function buildSparks(level: CelebrationLevel): Spark[] {
  const chars = level === 'grand' ? ['✦', '✧', '★', '❖'] : ['✦', '✧']
  return Array.from({ length: sparkCount[level] }, (_, i) => ({
    key: i,
    char: chars[Math.floor(Math.random() * chars.length)],
    style: {
      left: `${6 + Math.random() * 88}%`,
      animationDelay: `${(Math.random() * 1.8).toFixed(2)}s`,
      animationDuration: `${(1.6 + Math.random() * 2.4).toFixed(2)}s`,
      fontSize: `${12 + Math.random() * 16}px`,
    },
  }))
}

watch(
  () => store.active,
  (v) => {
    if (v) sparks.value = buildSparks(store.level)
  }
)

// 自动关闭：规格越高停留越久
const autoClose: Record<CelebrationLevel, number> = { basic: 2600, advanced: 3400, grand: 4600 }
let timer: number | undefined
watch(
  () => store.active,
  (v) => {
    window.clearTimeout(timer)
    if (v) timer = window.setTimeout(() => store.dismiss(), autoClose[store.level])
  }
)
onUnmounted(() => window.clearTimeout(timer))
</script>

<style scoped>
.celebrate-overlay {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: rgba(8, 14, 8, 0.86);
  backdrop-filter: blur(2px);
}
.celebrate-card {
  position: relative;
  width: 100%;
  max-width: 360px;
  overflow: hidden;
  text-align: center;
  padding: 34px 22px 26px;
  background: var(--panel);
  border: 1px solid var(--accent);
  border-radius: 8px;
  box-shadow: 0 0 24px rgba(126, 224, 126, 0.18);
}
.title {
  font-size: 26px;
  letter-spacing: 6px;
  color: var(--accent);
  animation: pulse 1.6s ease-in-out infinite;
}
.reward {
  margin-top: 10px;
  font-size: 20px;
  color: var(--text);
  letter-spacing: 1px;
}
.subtitle {
  margin-top: 6px;
  font-size: 13px;
  color: var(--muted);
  letter-spacing: 2px;
}
.lines {
  margin: 12px 0 0;
  white-space: pre-line;
  font-size: 13px;
  color: var(--muted);
}
.hint {
  margin: 18px 0 0;
  font-size: 11px;
  color: var(--muted);
  opacity: 0.6;
}
.glow {
  position: absolute;
  top: -40%;
  left: 50%;
  width: 260px;
  height: 260px;
  transform: translateX(-50%);
  border-radius: 50%;
  background: radial-gradient(circle, rgba(126, 224, 126, 0.28), transparent 70%);
  pointer-events: none;
}
.spark {
  position: absolute;
  top: 100%;
  color: var(--accent);
  opacity: 0;
  animation-name: rise;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
  pointer-events: none;
}

@keyframes rise {
  0% { transform: translateY(0) scale(0.6); opacity: 0; }
  12% { opacity: 0.9; }
  100% { transform: translateY(-260px) scale(1.1); opacity: 0; }
}
@keyframes pulse {
  0%, 100% { text-shadow: 0 0 8px rgba(126, 224, 126, 0.35); }
  50% { text-shadow: 0 0 20px rgba(126, 224, 126, 0.75); }
}

/* 进阶/盛大：标题更强光、卡片更亮 */
.lv-advanced .celebrate-card { box-shadow: 0 0 32px rgba(126, 224, 126, 0.26); }
.lv-grand .celebrate-card {
  box-shadow: 0 0 48px rgba(126, 224, 126, 0.36);
}
.lv-grand .title { font-size: 32px; }
.lv-grand .glow { width: 360px; height: 360px; background: radial-gradient(circle, rgba(126, 224, 126, 0.4), transparent 70%); }

/* 进场/退场过渡 */
.celebrate-enter-active, .celebrate-leave-active { transition: opacity 0.5s ease; }
.celebrate-enter-active .celebrate-card, .celebrate-leave-active .celebrate-card {
  transition: transform 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}
.celebrate-enter-from, .celebrate-leave-to { opacity: 0; }
.celebrate-enter-from .celebrate-card, .celebrate-leave-to .celebrate-card {
  transform: scale(0.9) translateY(8px);
}
</style>
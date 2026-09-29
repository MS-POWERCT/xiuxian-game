<template>
  <LoginView v-if="!auth.isLoggedIn" />
  <BindIdcardView v-else-if="needBind" @skip="bindDismissed = true" />
  <MainView v-else />
  <CelebrationOverlay />
  <Toast />
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useAudioStore } from '@/stores/audio'
import LoginView from './views/LoginView.vue'
import BindIdcardView from './views/BindIdcardView.vue'
import MainView from './views/MainView.vue'
import CelebrationOverlay from './components/CelebrationOverlay.vue'
import Toast from './components/Toast.vue'

const auth = useAuthStore()
const audio = useAudioStore()

// 登录后未绑定身份证且本次会话未跳过时，先引导实名绑定
const bindDismissed = ref(false)
const needBind = computed(
  () => auth.user !== null && auth.user.realname_age === null && !bindDismissed.value
)

// 全局交互：确保 BGM 在播（浏览器需用户手势后才能出声）+ 按钮音效
function onDocClick(e: MouseEvent) {
  audio.playBgm()
  const btn = (e.target as HTMLElement).closest('button')
  if (!btn) return
  audio.playSfx(btn.getAttribute('data-sfx') ?? 'click')
}

function onKeydown() {
  audio.playBgm()
}

onMounted(() => {
  document.addEventListener('click', onDocClick)
  document.addEventListener('keydown', onKeydown)
})
onUnmounted(() => {
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>
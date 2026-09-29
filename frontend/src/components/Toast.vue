<template>
  <Transition name="toast">
    <div v-if="toast.visible" class="toast" :class="toast.type">
      {{ toast.message }}
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
</script>

<style scoped>
.toast {
  position: fixed;
  top: 16px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 1000;
  padding: 8px 16px;
  border-radius: 4px;
  font-size: 12px;
  line-height: 1.5;
  max-width: 80%;
  text-align: center;
  background: var(--panel);
  border: 1px solid var(--border);
  color: var(--text);
}
.toast.success { border-color: var(--accent); color: var(--accent); }
.toast.error { border-color: var(--danger); color: var(--danger); }
.toast-enter-active, .toast-leave-active { transition: opacity .6s ease, transform .6s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(-50%) translateY(-8px); }
</style>
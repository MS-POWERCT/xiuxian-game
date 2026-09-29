<template>
  <section class="panel audio-panel">
    <h3>声音</h3>
    <div class="audio-row">
      <label class="audio-label">
        <input type="checkbox" :checked="audio.bgmOn" @change="audio.toggleBgm()" />
        背景音乐
      </label>
      <input
        type="range"
        min="0"
        max="1"
        step="0.05"
        :value="audio.bgmVolume"
        :disabled="!audio.bgmOn"
        @input="audio.setBgmVolume(parseFloat(($event.target as HTMLInputElement).value))"
      />
    </div>
    <div class="audio-row">
      <label class="audio-label">
        <input type="checkbox" :checked="audio.sfxOn" @change="audio.toggleSfx()" />
        音效
      </label>
      <input
        type="range"
        min="0"
        max="1"
        step="0.05"
        :value="audio.sfxVolume"
        :disabled="!audio.sfxOn"
        @input="audio.setSfxVolume(parseFloat(($event.target as HTMLInputElement).value))"
      />
    </div>
  </section>
</template>

<script setup lang="ts">
import { useAudioStore } from '@/stores/audio'

const audio = useAudioStore()
</script>

<style scoped>
.audio-panel {
  margin-top: 14px;
}
.audio-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 3px 0;
}
.audio-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex: 0 0 auto;
  color: var(--text);
  cursor: pointer;
}
.audio-row input[type='range'] {
  flex: 1;
  accent-color: var(--accent);
}
</style>
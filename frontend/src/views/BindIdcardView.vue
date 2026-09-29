<template>
  <div class="bind-wrap">
    <div class="brand">
      <img class="logo" src="/logo.png" alt="文字修仙" />
      <h1 class="title">文字修仙</h1>
    </div>

    <section class="card">
      <h3>绑定身份证（实名）</h3>
      <p class="intro muted">
        系统将校验身份证号合法性，并按出生日期计算你的真实年龄（12-70 岁），用于「真实年龄开局」。
      </p>

      <label class="field">
        <span class="lbl">身份证号</span>
        <input v-model="idCard" type="text" maxlength="18" placeholder="18 位身份证号" @keyup.enter="submit" />
      </label>

      <p v-if="msg" class="msg err">{{ msg }}</p>

      <div class="actions">
        <button class="primary" :disabled="busy" @click="submit">{{ busy ? '校验中…' : '绑定并进入' }}</button>
        <button class="ghost" :disabled="busy" @click="emit('skip')">跳过</button>
      </div>

      <p class="hint muted">仅保存计算出的年龄，不存身份证号原文；可稍后手动补绑。</p>
    </section>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const emit = defineEmits<{ (e: 'skip'): void }>()

const auth = useAuthStore()
const idCard = ref('')
const msg = ref('')
const busy = ref(false)

async function submit() {
  msg.value = ''
  if (!idCard.value) {
    msg.value = '请输入身份证号'
    return
  }
  busy.value = true
  try {
    // 绑定成功后 auth.user.realname_age 会更新，App 自动切到主界面
    await auth.bindIdcard(idCard.value)
  } catch (e) {
    msg.value = (e as Error).message
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.bind-wrap {
  max-width: 360px;
  margin: 0 auto;
  padding-top: 40px;
}
.brand { text-align: center; margin-bottom: 22px; }
.logo {
  width: 96px;
  height: 96px;
  border-radius: 12px;
  border: 1px solid var(--border);
}
.title {
  margin: 12px 0 2px;
  font-size: 24px;
  letter-spacing: 6px;
  color: var(--text);
  font-weight: normal;
}
.card {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 16px 18px;
}
.card h3 {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--accent);
  letter-spacing: 1px;
  border-bottom: 1px dashed var(--border);
  padding-bottom: 8px;
}
.intro { font-size: 12px; line-height: 1.7; margin: 0 0 14px; }
.field { display: block; margin-bottom: 14px; }
.lbl { display: block; font-size: 12px; color: var(--muted); margin-bottom: 5px; letter-spacing: 1px; }
.msg { margin: 0 0 12px; font-size: 12px; color: var(--danger); }
.actions { display: flex; gap: 10px; }
.actions .primary { flex: 1; }
.hint { margin: 14px 0 0; font-size: 11px; line-height: 1.6; }
</style>
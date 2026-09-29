<template>
  <div class="login-wrap">
    <div class="brand">
      <img class="logo" src="/logo.png" alt="文字修仙" />
      <h1 class="title">文字修仙</h1>
      <p class="tagline muted">一念入定 · 大道可期</p>
    </div>

    <section class="card">
      <h3>{{ mode === 'login' ? '登录' : '注册' }}</h3>

      <label class="field">
        <span class="lbl">邮箱</span>
        <input v-model="email" type="email" autocomplete="username" placeholder="you@example.com" />
      </label>

      <label class="field">
        <span class="lbl">密码</span>
        <input
          v-model="password"
          type="password"
          :autocomplete="mode === 'login' ? 'current-password' : 'new-password'"
          placeholder="至少 8 位"
          @keyup.enter="submit"
        />
      </label>

      <p v-if="msg" class="msg" :class="{ err: isErr }">{{ msg }}</p>

      <button class="primary wide" :disabled="busy" @click="submit">
        {{ busy ? '请稍候…' : mode === 'login' ? '进入修炼' : '注册' }}
      </button>

      <p class="switch muted">
        {{ mode === 'login' ? '没有账号？' : '已有账号？' }}
        <a class="link" @click="toggle">{{ mode === 'login' ? '注册' : '登录' }}</a>
      </p>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const mode = ref<'login' | 'register'>('login')
const email = ref('')
const password = ref('')
const msg = ref('')
const isErr = ref(false)

const busy = computed(() => auth.busy)

function setMsg(text: string, err = false) {
  msg.value = text
  isErr.value = err
}

function toggle() {
  mode.value = mode.value === 'login' ? 'register' : 'login'
  password.value = ''
  setMsg('')
}

async function submit() {
  setMsg('')
  if (!email.value) return setMsg('请输入邮箱', true)
  if (password.value.length < 8) return setMsg('密码至少 8 位', true)
  try {
    if (mode.value === 'register') {
      await auth.register(email.value, password.value)
      setMsg('注册成功，请登录')
      mode.value = 'login'
      password.value = ''
    } else {
      await auth.login(email.value, password.value)
    }
  } catch (e) {
    setMsg((e as Error).message, true)
  }
}
</script>

<style scoped>
.login-wrap {
  max-width: 360px;
  margin: 0 auto;
  padding-top: 40px;
}
.brand {
  text-align: center;
  margin-bottom: 22px;
}
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
.tagline {
  font-size: 12px;
  letter-spacing: 2px;
}
.card {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 16px 18px;
}
.card h3 {
  margin: 0 0 14px;
  font-size: 13px;
  color: var(--accent);
  letter-spacing: 1px;
  border-bottom: 1px dashed var(--border);
  padding-bottom: 8px;
}
.field {
  display: block;
  margin-bottom: 14px;
}
.lbl {
  display: block;
  font-size: 12px;
  color: var(--muted);
  margin-bottom: 5px;
  letter-spacing: 1px;
}
.msg {
  margin: 0 0 12px;
  font-size: 12px;
  color: var(--accent);
}
.msg.err { color: var(--danger); }
.wide { width: 100%; }
.switch {
  margin: 16px 0 0;
  text-align: center;
  font-size: 12px;
}
.link {
  color: var(--accent);
  cursor: pointer;
  text-decoration: none;
}
.link:hover { text-decoration: underline; }
</style>
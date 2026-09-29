import { defineStore } from 'pinia'
import * as api from '@/api/auth'

const TOKEN_KEY = 'token'
const USER_KEY = 'user'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || ('' as string),
    user: JSON.parse(localStorage.getItem(USER_KEY) || 'null') as api.AuthUser | null,
    busy: false,
  }),
  getters: {
    isLoggedIn: (s) => s.token !== '' && s.user !== null,
  },
  actions: {
    persist() {
      localStorage.setItem(TOKEN_KEY, this.token)
      localStorage.setItem(USER_KEY, JSON.stringify(this.user))
    },
    async register(email: string, password: string) {
      this.busy = true
      try {
        await api.register(email, password)
      } finally {
        this.busy = false
      }
    },
    async login(email: string, password: string) {
      this.busy = true
      try {
        const data = await api.login(email, password)
        this.token = data.token
        this.user = data.user
        this.persist()
      } finally {
        this.busy = false
      }
    },
    async bindIdcard(idCard: string) {
      const data = await api.bindIdcard(idCard)
      if (this.user) this.user.realname_age = data.realname_age
      this.persist()
      return data
    },
    logout() {
      this.token = ''
      this.user = null
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem(USER_KEY)
    },
  },
})
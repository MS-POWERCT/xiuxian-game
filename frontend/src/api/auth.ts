import { http } from './http'

export interface AuthUser {
  id: number
  email: string
  realname_age: number | null
}

export function register(email: string, password: string) {
  return http.post<{ id: number; email: string }>('/auth/register', { email, password })
}

export function login(email: string, password: string) {
  return http.post<{ token: string; user: AuthUser }>('/auth/login', { email, password })
}

export function bindIdcard(idCard: string) {
  return http.post<{ age: number; realname_age: number }>('/auth/bind-idcard', { id_card: idCard })
}
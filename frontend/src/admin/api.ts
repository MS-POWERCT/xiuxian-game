const BASE = '/api/admin'
let csrfToken = ''

export class AdminApiError extends Error {
  code: number
  constructor(code: number, msg: string) {
    super(msg)
    this.name = 'AdminApiError'
    this.code = code
  }
}

async function rawRequest<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' }
  if (csrfToken && method !== 'GET') {
    headers['X-CSRF-Token'] = csrfToken
  }

  const response = await fetch(BASE + path, {
    method,
    headers,
    credentials: 'include',
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const json = await response.json()
  if (json.code !== 0) {
    throw new AdminApiError(json.code, json.msg || '请求失败')
  }
  return json.data as T
}

export async function ensureCsrf(): Promise<string> {
  if (csrfToken) return csrfToken
  const data = await rawRequest<{ csrf_token: string }>('GET', '/auth/csrf')
  csrfToken = data.csrf_token
  return csrfToken
}

export function setCsrf(token: string) {
  csrfToken = token
}

export async function login(username: string, password: string) {
  await ensureCsrf()
  const data = await rawRequest<{ admin: AdminInfo; csrf_token: string }>('POST', '/auth/login', {
    username,
    password,
  })
  setCsrf(data.csrf_token)
  return data
}

export async function me() {
  const data = await rawRequest<{ admin: AdminInfo; csrf_token: string }>('GET', '/auth/me')
  setCsrf(data.csrf_token)
  return data
}

export async function logout() {
  await ensureCsrf()
  await rawRequest('POST', '/auth/logout')
  csrfToken = ''
}

export const api = {
  dashboard: () => rawRequest<Dashboard>('GET', '/dashboard'),
  configs: () => rawRequest<{ configs: ConfigItem[] }>('GET', '/configs'),
  config: (name: string) => rawRequest<ConfigDetail>('GET', `/configs/${encodeURIComponent(name)}`),
  saveConfig: (name: string, payload: { content: string; password: string; reason: string }) =>
    rawRequest<ConfigSaveResult>('POST', `/configs/${encodeURIComponent(name)}`, payload),
  backups: (name: string) => rawRequest<{ backups: BackupItem[] }>('GET', `/configs/${encodeURIComponent(name)}/backups`),
  restoreConfig: (name: string, payload: { backup: string; password: string; reason: string }) =>
    rawRequest<ConfigSaveResult>('POST', `/configs/${encodeURIComponent(name)}/restore`, payload),
  tables: () => rawRequest<{ tables: TableItem[] }>('GET', '/tables'),
  table: (name: string, params: Record<string, string | number>) => {
    const query = new URLSearchParams()
    Object.entries(params).forEach(([key, value]) => query.set(key, String(value)))
    return rawRequest<TableData>('GET', `/tables/${encodeURIComponent(name)}?${query.toString()}`)
  },
  updateRetreatFinishAt: (id: number, payload: { finish_at: number; password: string; reason: string }) =>
    rawRequest<{ id: number; before_finish_at: number; finish_at: number }>(
      'POST',
      `/retreats/${encodeURIComponent(String(id))}/finish-at`,
      payload
    ),
}

export interface AdminInfo {
  id: number
  username: string
}

export interface Dashboard {
  users: number
  players: number
  alive_players: number
  retreating_players: number
  admin_users: number
  configs: number
  server_time: number
}

export interface ConfigItem {
  name: string
  file: string
  size: number
  updated_at: number
  sha256: string
}

export interface BackupItem {
  name: string
  size: number
  created_at: number
}

export interface ConfigDetail {
  name: string
  file: string
  content: string
  updated_at: number
  sha256: string
  backups: BackupItem[]
}

export interface ConfigSaveResult {
  name: string
  sha256: string
  updated_at: number
}

export interface TableItem {
  name: string
  label: string
  group: 'game' | 'admin'
}

export interface TableColumn {
  name: string
  label: string
  type?: string
  is_time: boolean
  masked: boolean
}

export interface TableData {
  table: string
  columns: TableColumn[]
  rows: Record<string, unknown>[]
  page: number
  page_size: number
  total: number
}

import { http } from './http'

export type PlayerStatus = 'idle' | 'retreating' | 'meditating' | 'exploring' | 'dead'

export interface Player {
  id: number
  life_no: number
  name: string
  realm_id: string
  stage_index: number
  exp: number
  age: number
  lifespan_max: number
  hp: number
  spirit_stones: number
  cultivate_rate: number
  speed_bonus: number
  status: PlayerStatus
}

export interface ReincarnationRecord {
  life_no: number
  name: string
  realm_id: string
  realm_name: string
  stage_index: number
  stage_name: string
  exp: number
  age: number
  lifespan_max: number
  hp: number
  spirit_stones: number
  cultivate_rate: number
  total_days: number
  death_reason: 'lifespan' | 'self' | 'relic'
  death_at: number
}

export interface ActiveRetreat {
  retreat_id: number
  start_at: number
  finish_at: number
  expected_exp: number
  status: number
}

export interface ActiveMeditation {
  start_at: number
  finish_at: number
  duration: number
  expected_exp: number
}

export interface DazuoState {
  daily_used: number
  daily_limit: number
  batch_size: number
}

export interface RetreatStartPayload {
  duration_hours: number
  technique_id: string
  formation_id: string
  pill_ids: string[]
}

export function getPlayer() {
  return http.get<{
    player: Player
    retreat: ActiveRetreat | null
    meditation: ActiveMeditation | null
    dazuo: DazuoState
  }>('/player')
}
export function startMeditation(duration: number) {
  return http.post<{
    expected_exp: number
    player: Player
    meditation: ActiveMeditation
  }>('/meditate', { duration })
}
export function claimMeditation() {
  return http.post<{ gained_exp: number; player: Player }>('/meditate/claim', {})
}
export function dazuo(count: number) {
  return http.post<{
    gained_exp: number
    batch_size: number
    daily_used: number
    daily_limit: number
    player: Player
  }>('/dazuo', { count })
}
export function startRetreat(payload: RetreatStartPayload) {
  return http.post<{ retreat_id: number; finish_at: number; expected_exp: number }>('/retreat/start', payload)
}
export function claimRetreat(retreat_id: number) {
  return http.post<{ gained_exp: number; player: Player }>('/retreat/claim', { retreat_id })
}
export function breakthrough() {
  return http.post<{
    success: boolean
    to_realm?: string
    exp_rollback?: number
    hp_loss?: number
    player: Player
  }>('/breakthrough', {})
}
export function reincarnate() {
  return http.post<{ player: Player }>('/reincarnate', {})
}
export function getReincarnationRecords() {
  return http.get<{ records: ReincarnationRecord[] }>('/reincarnate/records')
}

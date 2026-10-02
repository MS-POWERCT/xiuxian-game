import { http } from './http'

export type PlayerStatus = 'idle' | 'retreating' | 'meditating' | 'exploring' | 'dead'

// 灵石四级（下/中/上/极），分开计数
export interface SpiritStones {
  low: number
  mid: number
  high: number
  top: number
}

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
  spirit_stones: SpiritStones
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
  spirit_stones: SpiritStones
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

// ==================== 灵石 / 商店 / 储物戒 ====================

export interface ShopItem {
  id: string
  name: string
  category: string
  ref_id: string
  price: number
  currency_level: string
}

export interface ItemEntry {
  item_id: string
  name: string
  category: string
  quantity: number
  created_at: number
  updated_at: number
}

export function getSpiritStones() {
  return http.get<{ stones: SpiritStones }>('/spirit-stones')
}
export function exchangeSpiritStones(from_level: string, to_level: string, amount: number) {
  return http.post<{ stones: SpiritStones; cost: number; fee: number }>('/spirit-stones/exchange', {
    from_level,
    to_level,
    amount,
  })
}
export function getShop() {
  return http.get<{ items: ShopItem[] }>('/shop')
}
export function buyShopItem(item_id: string, quantity: number) {
  return http.post<{
    item_id: string
    name: string
    quantity: number
    cost: number
    currency_level: string
    stones: SpiritStones
  }>('/shop/buy', { item_id, quantity })
}
export function getItems() {
  return http.get<{ items: ItemEntry[] }>('/items')
}

// ==================== 游历 / 事件 / 庇护 ====================

export interface TravelMode {
  id: string
  name: string
  duration_minutes: number
  event_weights: Record<string, number>
}

export interface TravelEvent {
  id: number
  event_id: string
  name: string
  desc: string
  quality: string
  type: string
  created_at: number
  expire_at: number
}

export interface ActiveTravel {
  travel_id: number
  mode_id: string
  mode_name: string
  start_at: number
  finish_at: number
}

export interface TravelState {
  max_slots: number
  events: TravelEvent[]
  active_travel: ActiveTravel | null
}

export interface Patron {
  id: number
  kind: 'mortal' | 'sect'
  sect_level: string
  sect_name: string
  count: number
  interval_hours: number
  max_accumulate: number
  cycles: number
  applied_cycles: number
  pending: SpiritStones
  last_supply_at: number
  next_supply_at: number
}

export interface ResolveResult {
  event_id: string
  type: string
  patron: { kind: string; count?: number; sect_level?: string; sect_name?: string } | null
  stones: SpiritStones | null
  items: { item_id: string; name: string; category: string; quantity: number }[] | null
}

export function getTravel() {
  return http.get<TravelState>('/travel')
}
export function startTravel(mode_id: string) {
  return http.post<{
    travel_id: number
    mode_id: string
    mode_name: string
    start_at: number
    finish_at: number
  }>('/travel/start', { mode_id })
}
export function resolveTravelEvent(id: number) {
  return http.post<ResolveResult>(`/travel/events/${id}/resolve`, {})
}
export function abandonTravelEvent(id: number) {
  return http.post<{ event_id: string }>(`/travel/events/${id}/abandon`, {})
}
export function getPatrons() {
  return http.get<{ patrons: Patron[]; total_pending: SpiritStones; stones: SpiritStones }>('/patrons')
}
export function claimPatrons() {
  return http.post<{ claimed: number; total: SpiritStones; stones: SpiritStones }>('/patrons/claim', {})
}
export function releasePatron(id: number) {
  return http.post<{ id: number; sect_level: string; item_id: string; cost: number }>(
    `/patrons/${id}/release`,
    {}
  )
}
